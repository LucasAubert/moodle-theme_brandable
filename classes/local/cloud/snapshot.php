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

namespace theme_brandable\local\cloud;

use theme_brandable\local\assets\library;
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\resolver;
use theme_brandable\local\overrides\scope;
use theme_brandable\local\overrides\template_source;
use theme_brandable\local\parent_theme;

/**
 * Snapshot of a version for the cloud (POST /cloud/versions, contract 13.4, spec C6): every override of the version
 * (no delta), the identifiers of the courses and activities of their scopes, the assets they use and the licences of
 * their fonts. Built at the publication: Moodle keeps two versions only.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class snapshot {
    /**
     * Builds the body of POST /cloud/versions for a local version, and where each file is read.
     *
     * @param \stdClass $version Local version (theme_brandable_version).
     * @param array $origin ['kind' => publish, restore, pull or backfill, 'versionId' => ?, 'proposalId' => ?].
     * @param bool $published Whether the version becomes the one of this environment.
     * @param string $clientversionid UUID of this sending.
     * @param string $sessionid Session that produced the version, empty if none.
     * @return array [payload, files: sha256 => ['asset' => id] or ['family' => key] or ['licence' => asset id]]
     */
    public static function build(
        \stdClass $version,
        array $origin,
        bool $published,
        string $clientversionid,
        string $sessionid = ''
    ): array {
        global $DB;
        $map = resolver::get_map((int) $version->revisionid);
        $changes = [];
        foreach ($map as $type => $entries) {
            foreach ($entries as $entry) {
                $changes[] = self::change((string) $type, $entry);
            }
        }
        [$assets, $files] = self::assets($map);
        $author = $version->userid ? $DB->get_record('user', ['id' => $version->userid]) : null;
        $payload = [
            'clientVersionId' => $clientversionid,
            'localNumber' => (int) $version->number,
            'note' => \core_text::substr(trim((string) $version->note), 0, 1000),
            'author' => ['hash' => cloud::author_hash((int) $version->userid)]
                + ($author && !$author->deleted ? ['name' => \core_text::substr(fullname($author), 0, 100)] : []),
            'publishedAt' => gmdate('Y-m-d\TH:i:s\Z', (int) $version->timecreated),
            'moodleVersion' => template_source::get_moodle_version(),
            'parentTheme' => parent_theme::get_name(),
            'origin' => array_filter([
                'kind' => (string) $origin['kind'],
                'versionId' => $origin['versionId'] ?? null,
                'proposalId' => $origin['proposalId'] ?? null,
            ], fn($value) => $value !== null && $value !== ''),
            'published' => $published,
            'changes' => $changes,
            'assets' => $assets,
        ];
        if ($sessionid !== '') {
            $payload['sessionId'] = $sessionid;
        }
        return [$payload, $files];
    }

    /**
     * Exports an override of the version.
     *
     * @param string $type Type.
     * @param array $entry Entry of the map.
     * @return array
     */
    private static function change(string $type, array $entry): array {
        global $DB;
        $scope = resolver::entry_scope($entry);
        $change = [
            'type' => $type,
            'target' => (string) $entry['target'],
            'content' => (string) $entry['content'],
            'sourceHash' => $type === change::TYPE_TEMPLATE ? ($entry['sourcehash'] ?? null) : null,
            'scope' => ['level' => $scope->level, 'value' => $scope->is_site() ? null : (string) $scope->value],
        ];
        // The course or the activity on another environment of the project (contract 13.5).
        if ($scope->level === scope::COURSE) {
            $course = $DB->get_record('course', ['id' => (int) $scope->value], 'id, idnumber, shortname');
            $change['scopeRef'] = $course ? array_filter(['idnumber' => (string) $course->idnumber,
                'shortname' => (string) $course->shortname], 'strlen') : null;
        } else if ($scope->level === scope::CM) {
            $idnumber = (string) $DB->get_field('course_modules', 'idnumber', ['id' => (int) $scope->value]);
            $change['scopeRef'] = $idnumber !== '' ? ['idnumber' => $idnumber] : null;
        }
        if (isset($change['scopeRef']) && !$change['scopeRef']) {
            unset($change['scopeRef']);
        }
        if ($type === change::TYPE_JS) {
            // The source and the signature of Brandable only: it analyses and compiles again (contract 13.4).
            $change['signature'] = ($entry['signature'] ?? '') !== '' ? (string) $entry['signature'] : null;
        }
        return $change;
    }

    /**
     * Returns the assets a version uses (images and font files by slug, licence texts by family) and where to read
     * each file.
     *
     * @param array $map Resolved map.
     * @return array [assets, files]
     */
    private static function assets(array $map): array {
        $library = library::get_all();
        $assets = [];
        $files = [];
        $families = [];
        foreach (library::slugs_in_map($map) as $slug) {
            $asset = $library[$slug] ?? null;
            $file = $asset ? library::get_file($asset, 'optimized') : null;
            if (!$file) {
                // Deleted from the library since: Brandable reports it (unknown_asset).
                continue;
            }
            $sha256 = (string) ($asset->sha256 ?: hash('sha256', $file->get_content()));
            $entry = ['kind' => $asset->kind, 'slug' => $slug, 'sha256' => $sha256, 'mime' => $asset->mime,
                'size' => (int) $file->get_filesize()];
            if ($asset->kind === 'font') {
                $entry += ['family' => (string) $asset->family, 'weight' => (int) $asset->weight,
                    'style' => (string) $asset->style, 'subset' => (string) $asset->subset, 'license' => (string) $asset->licence];
                $families[\core_text::strtolower((string) $asset->family)] ??= $asset;
            } else {
                $entry += ['width' => (int) $asset->width, 'height' => (int) $asset->height, 'alt' => (string) $asset->alt];
            }
            $assets[] = $entry;
            $files[$sha256] = ['asset' => (int) $asset->id];
        }
        // One licence text per family: the one of the catalogue, else the file given with an uploaded font.
        foreach ($families as $key => $font) {
            $family = library::get_family((string) $font->family);
            if ($family && trim((string) $family->licencetext) !== '') {
                $text = (string) $family->licencetext;
                $source = ['family' => $key];
                $licence = (string) $family->licence;
            } else {
                $file = library::get_file($font, 'licence');
                if (!$file) {
                    continue;
                }
                $text = $file->get_content();
                $source = ['licence' => (int) $font->id];
                $licence = (string) $font->licence;
            }
            $sha256 = hash('sha256', $text);
            $assets[] = ['kind' => 'font_license', 'sha256' => $sha256, 'mime' => 'text/plain', 'size' => strlen($text),
                'family' => (string) $font->family, 'license' => $licence];
            $files[$sha256] = $source;
        }
        // Never cut: a version over the limits of Brandable fails visibly (400) rather than incomplete.
        return [$assets, $files];
    }

    /**
     * Reads a file of a version to send it, and checks it did not change since the snapshot.
     *
     * @param string $sha256 sha256 of the file.
     * @param array $source Where it is read (see build()).
     * @return array|null ['content', 'mime'], null when it is no longer there.
     */
    public static function read_file(string $sha256, array $source): ?array {
        if (isset($source['family'])) {
            $family = library::get_family((string) $source['family']);
            $content = $family ? (string) $family->licencetext : null;
            $mime = 'text/plain';
        } else {
            $asset = library::get((int) ($source['asset'] ?? $source['licence'] ?? 0));
            $file = $asset ? library::get_file($asset, isset($source['licence']) ? 'licence' : 'optimized') : null;
            $content = $file ? $file->get_content() : null;
            $mime = isset($source['licence']) ? 'text/plain' : ($asset->mime ?? '');
        }
        if ($content === null || !hash_equals($sha256, hash('sha256', $content))) {
            return null;
        }
        return ['content' => $content, 'mime' => $mime];
    }
}
