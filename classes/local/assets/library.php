<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace theme_brandable\local\assets;

use context_system;
use moodle_exception;
use moodle_url;
use stdClass;
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\resolver;
use theme_brandable\local\overrides\revision_manager;

/**
 * Library of images and fonts (spec docs/SPEC-assets.md, contract section 11).
 *
 * An asset is a row of theme_brandable_asset and its files in the asset file area (itemid = id, one folder per role:
 * original, optimized = the file served, webp, thumbnail, licence). Assets are immutable: only the slug (while unused),
 * the alternative text and the font description change.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class library {
    /** @var string Table. */
    public const TABLE = 'theme_brandable_asset';

    /** @var string Licences of the font families of the catalogue. */
    public const FAMILY_TABLE = 'theme_brandable_fontfamily';

    /** @var string File area. */
    public const FILEAREA = 'asset';

    /** @var string Slug (contract 11.2). */
    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** @var int Longest slug. */
    public const SLUG_LENGTH = 64;

    /** @var int Largest library (contract 11.2: 200 assets per request). */
    public const MAX_ASSETS = 200;

    /** @var string Marker in the SCSS (contract 11.3). */
    public const SCSS_MARKER = '/brandable-asset\(\s*(["\'])([a-z0-9-]+)\1\s*\)/';

    /** @var string Marker in the templates (contract 11.3). */
    public const TEMPLATE_MARKER = '/\[\[brandable-asset:([a-z0-9-]+)\]\]/';

    /** @var string Config holding the revision of the library (caches). */
    public const REVISION_CONFIG = 'brandable_assetsrev';

    /** @var string[] Font styles. */
    public const STYLES = ['normal', 'italic'];

    /** @var string[] Roles of the files. */
    public const ROLES = ['original', 'optimized', 'webp', 'thumbnail', 'licence'];

    /**
     * Adds an image or a font checked by file_checker.
     *
     * @param array $checked Result of file_checker::check_image() or check_font().
     * @param array $meta slug (suggested from the file name when empty), filename, alt; family, weight, style, subset
     *                    for a font; origin (upload, google, import, moodle), licence.
     * @param int|null $userid Author, current user when null.
     * @return stdClass Asset record.
     */
    public static function add(array $checked, array $meta, ?int $userid = null): stdClass {
        global $DB, $USER;

        $font = $checked['mime'] === 'font/woff2';
        $familykey = $font ? \core_text::strtolower(trim((string) ($meta['family'] ?? ''))) : '';
        $files = $font ? self::count_family_files($familykey) : 0;
        // A family of fonts counts as one asset (contract 11.2): only a new image or a new family counts.
        if (($font ? $files === 0 : true) && self::count_units() >= self::MAX_ASSETS) {
            throw new moodle_exception('asset:error:full', 'theme_brandable', '', self::MAX_ASSETS);
        }
        if ($files >= font_downloader::MAX_FILES) {
            throw new moodle_exception('asset:error:familyfull', 'theme_brandable', '', font_downloader::MAX_FILES);
        }
        $slug = trim((string) ($meta['slug'] ?? ''));
        if ($slug === '') {
            $slug = self::suggest_slug((string) ($meta['filename'] ?? ''), $font ? 'font' : 'image');
        }
        self::check_slug($slug);
        $record = (object) [
            'slug' => $slug,
            'kind' => $font ? 'font' : 'image',
            'mime' => $checked['mime'],
            'width' => (int) ($checked['width'] ?? 0),
            'height' => (int) ($checked['height'] ?? 0),
            'alt' => $font ? null : self::clean_alt((string) ($meta['alt'] ?? '')),
            'family' => null,
            'weight' => 0,
            'style' => null,
            'subset' => $font && preg_match('/^[a-z0-9-]{1,30}$/', (string) ($meta['subset'] ?? '')) ? $meta['subset'] : null,
            'origin' => in_array($meta['origin'] ?? '', ['google', 'import', 'moodle'], true) ? $meta['origin'] : 'upload',
            'licence' => isset($meta['licence']) ? clean_param(substr((string) $meta['licence'], 0, 50), PARAM_TEXT) : null,
            'contenthash' => sha1($checked['files']['optimized']['content']),
            // Identity of the file in the cloud of the Pro offer (contract 13.3).
            'sha256' => hash('sha256', $checked['files']['optimized']['content']),
            'favicononly' => empty($checked['favicononly']) ? 0 : 1,
            'userid' => $userid ?? (int) $USER->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        if ($font) {
            [$record->family, $record->weight, $record->style] = self::check_font_meta($meta);
        }

        $transaction = $DB->start_delegated_transaction();
        $record->id = $DB->insert_record(self::TABLE, $record);
        $fs = get_file_storage();
        $context = context_system::instance();
        foreach ($checked['files'] as $role => $file) {
            $extension = $role === 'licence' ? 'txt' : (file_checker::EXTENSIONS[$file['mime']] ?? 'bin');
            $fs->create_file_from_string([
                'contextid' => $context->id,
                'component' => 'theme_brandable',
                'filearea' => self::FILEAREA,
                'itemid' => $record->id,
                'filepath' => '/' . $role . '/',
                'filename' => ($role === 'licence' ? 'LICENSE' : $slug) . '.' . $extension,
                'mimetype' => $file['mime'],
                'userid' => $record->userid ?: null,
            ], $file['content']);
        }
        $transaction->allow_commit();
        self::changed();
        // The published CSS may already use this name (its marker became url("data:,")): built again.
        $published = resolver::get_map(revision_manager::get_published());
        if (in_array($slug, self::slugs_in_map([change::TYPE_SCSS => $published[change::TYPE_SCSS]]), true)) {
            revision_manager::rebuild_published_css();
        }
        return $record;
    }

    /**
     * Returns an asset.
     *
     * @param int $id Id.
     * @return stdClass|null
     */
    public static function get(int $id): ?stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['id' => $id]) ?: null;
    }

    /**
     * Returns the assets, by slug.
     *
     * @return stdClass[]
     */
    public static function get_all(): array {
        global $DB;
        $assets = [];
        foreach ($DB->get_records(self::TABLE, null, 'kind ASC, timecreated DESC, id DESC') as $record) {
            $assets[$record->slug] = $record;
        }
        return $assets;
    }

    /**
     * Updates the description of an asset. The slug only changes while no kept revision uses it (spec A5).
     *
     * @param int $id Id.
     * @param array $data slug, alt (images), family, weight, style (fonts); missing keys are kept.
     * @return stdClass Updated record.
     */
    public static function update(int $id, array $data): stdClass {
        global $DB;

        $record = self::get($id) ?? throw new moodle_exception('asset:error:missing', 'theme_brandable');
        if (isset($data['slug']) && $data['slug'] !== $record->slug) {
            if (self::get_usage()[$record->slug] ?? []) {
                throw new moodle_exception('asset:error:slugused', 'theme_brandable');
            }
            self::check_slug($data['slug']);
            self::rename_files($record, $data['slug']);
            $record->slug = $data['slug'];
        }
        if ($record->kind === 'image' && array_key_exists('alt', $data)) {
            $record->alt = self::clean_alt((string) $data['alt']);
        }
        if ($record->kind === 'font' && isset($data['family'])) {
            [$record->family, $record->weight, $record->style] = self::check_font_meta($data + (array) $record);
        }
        $record->timemodified = time();
        $DB->update_record(self::TABLE, $record);
        self::changed();
        return $record;
    }

    /**
     * Deletes an asset, refused while the published version, the session or the previous version uses it (spec A6).
     *
     * @param int $id Id.
     */
    public static function delete(int $id): void {
        global $DB;

        $record = self::get($id) ?? throw new moodle_exception('asset:error:missing', 'theme_brandable');
        $usage = self::get_usage()[$record->slug] ?? [];
        if ($usage) {
            $where = implode(', ', array_map(fn($place) => get_string('asset:usage:' . $place, 'theme_brandable'), $usage));
            throw new moodle_exception('asset:error:used', 'theme_brandable', '', $where);
        }
        get_file_storage()->delete_area_files(context_system::instance()->id, 'theme_brandable', self::FILEAREA, $record->id);
        $DB->delete_records(self::TABLE, ['id' => $record->id]);
        if ($record->kind === 'font' && self::count_family_files(\core_text::strtolower((string) $record->family)) === 0) {
            // Last file of the family: its licence goes with it.
            $DB->delete_records(self::FAMILY_TABLE, ['familykey' => \core_text::strtolower((string) $record->family)]);
        }
        self::changed();
    }

    /**
     * Returns the number of assets in the sense of the limit: images, plus font families (contract 11.2).
     *
     * @return int
     */
    public static function count_units(): int {
        global $DB;
        $images = $DB->count_records(self::TABLE, ['kind' => 'image']);
        $families = (int) $DB->get_field_sql(
            'SELECT COUNT(DISTINCT LOWER(family)) FROM {' . self::TABLE . '} WHERE kind = ?',
            ['font']
        );
        return $images + $families;
    }

    /**
     * Returns the number of files of a font family.
     *
     * @param string $familykey Family, lowercase.
     * @return int
     */
    private static function count_family_files(string $familykey): int {
        global $DB;
        return $familykey === '' ? 0 : $DB->count_records_select(
            self::TABLE,
            'kind = ? AND LOWER(family) = ?',
            ['font', $familykey]
        );
    }

    /**
     * Returns the licence of a font family of the catalogue, null when none is stored.
     *
     * @param string $family Family (case ignored).
     * @return stdClass|null Record of theme_brandable_fontfamily.
     */
    public static function get_family(string $family): ?stdClass {
        global $DB;
        return $DB->get_record(self::FAMILY_TABLE, ['familykey' => \core_text::strtolower(trim($family))]) ?: null;
    }

    /**
     * Stores the licence of a font family of the catalogue (one per family, replaced when it changes).
     *
     * @param string $family Family.
     * @param string $licence SPDX identifier.
     * @param string $text Full text.
     */
    public static function set_family_licence(string $family, string $licence, string $text): void {
        global $DB;
        $record = self::get_family($family) ?? (object) ['familykey' => \core_text::strtolower(trim($family)),
            'family' => trim($family)];
        $record->licence = $licence;
        $record->licencetext = $text;
        $record->timemodified = time();
        if (isset($record->id)) {
            $DB->update_record(self::FAMILY_TABLE, $record);
        } else {
            $DB->insert_record(self::FAMILY_TABLE, $record);
        }
    }

    /**
     * Returns where each slug is used: the published version, the session (its revisions, undone ones included), the
     * previous version.
     *
     * @return array slug => list of 'published', 'session', 'previous'.
     */
    public static function get_usage(): array {
        global $DB;

        $usage = [];
        $add = function (array $slugs, string $place) use (&$usage): void {
            foreach ($slugs as $slug) {
                if (!in_array($place, $usage[$slug] ?? [], true)) {
                    $usage[$slug][] = $place;
                }
            }
        };
        $add(self::slugs_in_map(resolver::get_map(revision_manager::get_published())), 'published');
        // The session starts from the published version: only its own changes are added.
        $session = revision_manager::get_session_id();
        if ($session !== '') {
            [$sql, $params] = [
                'SELECT c.type, c.content FROM {theme_brandable_change} c
                   JOIN {theme_brandable_revision} r ON r.id = c.revisionid
                  WHERE r.sessionid = ? AND c.deleted = 0',
                [$session],
            ];
            $slugs = [];
            foreach ($DB->get_recordset_sql($sql, $params) as $change) {
                array_push($slugs, ...self::slugs_in((string) $change->type, (string) $change->content));
            }
            $add($slugs, 'session');
        }
        $previous = revision_manager::get_previous_version();
        if ($previous) {
            $add(self::slugs_in_map(resolver::get_map((int) $previous->revisionid)), 'previous');
        }
        return $usage;
    }

    /**
     * Returns the slugs used by a map: markers of the templates and SCSS, slugs of the settings.
     *
     * @param array $map Resolved map.
     * @return string[]
     */
    public static function slugs_in_map(array $map): array {
        $slugs = [];
        foreach ($map as $type => $entries) {
            foreach ($entries as $entry) {
                array_push($slugs, ...self::slugs_in((string) $type, (string) $entry['content']));
            }
        }
        return array_values(array_unique($slugs));
    }

    /**
     * Returns the slugs used by the content of a change.
     *
     * @param string $type Change type.
     * @param string $content Content.
     * @return string[]
     */
    public static function slugs_in(string $type, string $content): array {
        if ($type === 'setting') {
            return $content !== '' ? [$content] : [];
        }
        $pattern = $type === 'scss' ? self::SCSS_MARKER : self::TEMPLATE_MARKER;
        preg_match_all($pattern, $content, $matches);
        return array_values(array_unique($matches[$type === 'scss' ? 2 : 1]));
    }

    /**
     * Returns the URL of the file served (public, immutable: the hash of the content is part of it, spec A4).
     *
     * @param stdClass $record Asset.
     * @param string|null $size Size of a logo (one of settings::SIZES), null for the file as it is.
     * @return moodle_url
     */
    public static function url(stdClass $record, ?string $size = null): moodle_url {
        return moodle_url::make_pluginfile_url(
            context_system::instance()->id,
            'theme_brandable',
            self::FILEAREA,
            (int) $record->id,
            '/' . substr($record->contenthash, 0, 12) . '/' . ($size !== null ? $size . '/' : ''),
            $record->slug . '.' . (file_checker::EXTENSIONS[$record->mime] ?? 'bin')
        );
    }

    /**
     * Returns the URL of the thumbnail (editor only), null when there is none.
     *
     * @param stdClass $record Asset.
     * @return moodle_url|null
     */
    public static function thumbnail_url(stdClass $record): ?moodle_url {
        $file = self::get_file($record, 'thumbnail');
        return $file ? moodle_url::make_pluginfile_url(
            context_system::instance()->id,
            'theme_brandable',
            self::FILEAREA,
            (int) $record->id,
            '/thumbnail/',
            $file->get_filename()
        ) : null;
    }

    /**
     * Returns a file of an asset.
     *
     * @param stdClass $record Asset.
     * @param string $role One of ROLES.
     * @return \stored_file|null
     */
    public static function get_file(stdClass $record, string $role): ?\stored_file {
        $files = get_file_storage()->get_directory_files(
            context_system::instance()->id,
            'theme_brandable',
            self::FILEAREA,
            (int) $record->id,
            '/' . $role . '/',
            false,
            false
        );
        return $files ? reset($files) : null;
    }

    /**
     * Suggests a free slug from a file name.
     *
     * @param string $filename File name.
     * @param string $fallback Slug used when nothing is left of the name.
     * @return string
     */
    public static function suggest_slug(string $filename, string $fallback = 'image'): string {
        global $DB;

        $name = pathinfo($filename, PATHINFO_FILENAME);
        $slug = strtolower(\core_text::specialtoascii($name));
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
        $slug = $slug !== '' ? $slug : $fallback;
        $base = rtrim(substr($slug, 0, self::SLUG_LENGTH - 4), '-');
        $candidate = $base;
        for ($i = 2; $DB->record_exists(self::TABLE, ['slug' => $candidate]); $i++) {
            $candidate = $base . '-' . $i;
        }
        return $candidate;
    }

    /**
     * Returns the revision of the library (part of the cache keys of the resolved markers).
     *
     * @return int
     */
    public static function get_revision(): int {
        return (int) get_config('theme_brandable', self::REVISION_CONFIG);
    }

    /**
     * Checks a slug: format of the contract, free.
     *
     * @param string $slug Slug.
     */
    private static function check_slug(string $slug): void {
        global $DB;
        if (strlen($slug) > self::SLUG_LENGTH || !preg_match(self::SLUG_PATTERN, $slug)) {
            throw new moodle_exception('asset:error:slug', 'theme_brandable');
        }
        if ($DB->record_exists(self::TABLE, ['slug' => $slug])) {
            throw new moodle_exception('asset:error:slugtaken', 'theme_brandable', '', $slug);
        }
    }

    /**
     * Checks the description of a font.
     *
     * @param array $meta family, weight, style.
     * @return array [family, weight, style]
     */
    private static function check_font_meta(array $meta): array {
        $family = trim(clean_param((string) ($meta['family'] ?? ''), PARAM_TEXT));
        $weight = (int) ($meta['weight'] ?? 0);
        $style = (string) ($meta['style'] ?? '');
        if (
            $family === '' || \core_text::strlen($family) > 100 || $weight < 1 || $weight > 1000
                || !in_array($style, self::STYLES, true)
        ) {
            throw new moodle_exception('asset:error:fontmeta', 'theme_brandable');
        }
        return [$family, $weight, $style];
    }

    /**
     * Cleans an alternative text (250 characters, contract 11.2).
     *
     * @param string $alt Text.
     * @return string|null
     */
    private static function clean_alt(string $alt): ?string {
        $alt = trim(preg_replace('/\s+/u', ' ', clean_param($alt, PARAM_TEXT)));
        return $alt === '' ? null : \core_text::substr($alt, 0, 250);
    }

    /**
     * Renames the files of an asset after a new slug.
     *
     * @param stdClass $record Asset.
     * @param string $slug New slug.
     */
    private static function rename_files(stdClass $record, string $slug): void {
        // Reduced logos are made again on demand.
        foreach (
            get_file_storage()->get_area_files(
                context_system::instance()->id,
                'theme_brandable',
                self::FILEAREA,
                (int) $record->id,
                'filepath',
                false
            ) as $file
        ) {
            if (strpos($file->get_filepath(), '/size-') === 0) {
                $file->delete();
            }
        }
        foreach (self::ROLES as $role) {
            $file = $role !== 'licence' ? self::get_file($record, $role) : null;
            if ($file) {
                $file->rename($file->get_filepath(), $slug . '.' . pathinfo($file->get_filename(), PATHINFO_EXTENSION));
            }
        }
    }

    /**
     * Records a change of the library: new revision for the caches.
     */
    private static function changed(): void {
        set_config(self::REVISION_CONFIG, self::get_revision() + 1, 'theme_brandable');
    }
}
