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

namespace theme_brandable\local\export;

use core_component;
use moodle_exception;
use theme_brandable\local\assets\file_checker;
use theme_brandable\local\assets\library;
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\scope;
use theme_brandable\local\overrides\template_source;
use theme_brandable\local\parent_theme;
use theme_brandable\local\validation\validator;

/**
 * Reads the zip of an exported theme back into overrides of Brandable (spec section 4, S9).
 *
 * The zip comes from outside: only brandable/manifest.json and the template, SCSS, image and font files it lists are
 * extracted; no PHP file of the zip is read or included. Images and fonts are checked as an upload (spec A14). The
 * changes built are validated as any revision when imported.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class importer {
    /** @var int Largest zip accepted (upload): images and fonts included. */
    public const MAX_ZIP = 100 * 1024 * 1024;

    /** @var int Largest uncompressed content of the zip. */
    private const MAX_CONTENT = 400 * 1024 * 1024;

    /** @var int Most entries in the zip. */
    private const MAX_ENTRIES = 5000;

    /** @var int Largest manifest, template or SCSS file read. */
    private const MAX_FILE = 1024 * 1024;

    /** @var string Folder of a JavaScript module of the export. */
    private const JS_DIR_PATTERN = '~^brandable/js/[0-9]+-[a-z0-9]+(?:-[a-z0-9]+)*$~';

    /** @var string[] Files of a JavaScript module of the export. */
    private const JS_FILES = ['source.js', 'compiled.js', 'report.json', 'signature.txt'];

    /** @var string Files of the zip that can be read (relative to the theme folder). */
    private const FILE_PATTERN = '~^(templates/[a-zA-Z0-9_-]+(/[a-zA-Z0-9_-]+)*\.mustache'
        . '|brandable/variants/[0-9]+\.mustache|brandable/scss/[0-9]+\.scss)$~';

    /** @var string Images and fonts of the zip (brandable/assets/), and licences of the fonts. */
    private const ASSET_PATTERN = '~^brandable/assets/(?:[a-z0-9]+(?:-[a-z0-9]+)*(?:\.[0-9]+x[0-9]+)?'
        . '\.(?:png|jpg|gif|webp|svg|ico|woff2)|licences/[a-z0-9]+(?:-[a-z0-9]+)*\.txt)$~';

    /** @var string[] Warnings (changes left out). */
    private array $warnings = [];

    /**
     * Reads a zip.
     *
     * @param string $path Zip file.
     * @return array ['theme', 'version', 'parent', 'source' => manifest source, 'changes' => change[],
     *     'warnings' => string[]].
     * @throws moodle_exception import:error:* When the zip is not an export of Brandable that fits this site.
     */
    public static function read(string $path): array {
        return (new self())->read_zip($path);
    }

    /**
     * Reads a zip.
     *
     * @param string $path Zip file.
     * @return array See read().
     */
    private function read_zip(string $path): array {
        $packer = get_file_packer('application/zip');
        $entries = filesize($path) <= self::MAX_ZIP ? $packer->list_files($path) : false;
        if (!$entries) {
            throw new moodle_exception('import:error:zip', 'theme_brandable');
        }
        $root = $this->check_entries($entries);

        $files = [];
        foreach ($entries as $entry) {
            if (!$entry->is_directory) {
                $files[substr($entry->pathname, strlen($root) + 1)] = (int) $entry->size;
            }
        }
        $manifest = $this->read_manifest($packer, $path, $root, $files);

        $parent = parent_theme::get_name();
        if ($manifest['parent'] !== $parent) {
            throw new moodle_exception('import:error:parent', 'theme_brandable', '', (object) [
                'zip' => s($manifest['parent']), 'site' => $parent]);
        }

        // The files listed by the manifest, and only them.
        $wanted = [];
        foreach (['templates', 'variants', 'scss'] as $list) {
            foreach ($manifest[$list] as $item) {
                $file = is_array($item) ? (string) ($item['file'] ?? '') : '';
                if (!preg_match(self::FILE_PATTERN, $file) || !isset($files[$file]) || $files[$file] > self::MAX_FILE) {
                    throw new moodle_exception('import:error:manifest', 'theme_brandable');
                }
                $wanted[$file] = $root . '/' . $file;
            }
        }
        $contents = $this->extract($packer, $path, $wanted);
        $assets = $this->read_assets($packer, $path, $root, $files, $manifest);
        // URLs of the theme in the templates become markers of the library again (exporter::assets()).
        $urls = '~' . preg_quote(exporter::WWWROOT, '~') . '/theme/' . preg_quote($root, '~')
            . '/asset\.php/[0-9a-f]{12}/([a-z0-9]+(?:-[a-z0-9]+)*)\.[a-z0-9]+~';
        foreach ($contents as $file => $content) {
            if (str_ends_with($file, '.mustache')) {
                $contents[$file] = preg_replace($urls, '[[brandable-asset:$1]]', $content);
            }
        }

        $changes = [];
        foreach ($manifest['templates'] as $item) {
            $change = $this->template_change($root, $item, $contents[$item['file']], null);
            if ($change) {
                $changes[] = $change;
            }
        }
        foreach ($manifest['variants'] as $item) {
            $scope = $this->local_scope($item['scope'] ?? null, (string) ($item['template'] ?? ''));
            $change = $scope ? $this->template_change($root, $item, $contents[$item['file']], $scope) : null;
            if ($change) {
                $changes[] = $change;
            }
        }
        foreach ($manifest['scss'] as $item) {
            $target = (string) ($item['target'] ?? '');
            $scope = $this->local_scope($item['scope'] ?? null, $target);
            if ($scope) {
                $changes[] = new change(change::TYPE_SCSS, $target, $contents[$item['file']], false, null, false, $scope);
            }
        }

        // Generated JavaScript (spec B15): accepted with its signature; not reviewed on this site (import_as_draft()).
        array_push($changes, ...$this->read_js($packer, $path, $root, $files, $manifest));

        foreach ($manifest['settings'] ?? [] as $target => $slug) {
            if (in_array($target, \theme_brandable\local\assets\settings::TARGETS, true) && isset($assets[$slug])) {
                $changes[] = new change(change::TYPE_SETTING, $target, $slug, false, null, false, new scope());
            }
        }

        return [
            'theme' => $root,
            'version' => (string) $manifest['version'],
            'parent' => $parent,
            'source' => is_array($manifest['source'] ?? null) ? $manifest['source'] : [],
            'changes' => $changes,
            'assets' => $assets,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * Adds the images and fonts of a zip read to the library, then returns its changes with the slugs of the library
     * (spec A14): the same file already in the library is reused; a slug taken by another file gets a new one.
     *
     * @param array $read Result of read().
     * @return change[] Changes to import.
     */
    public static function apply_assets(array $read): array {
        global $DB;
        $renamed = [];
        foreach ($read['assets'] as $slug => $asset) {
            $entry = $asset['entry'];
            if ($entry['kind'] === 'font') {
                $checked = file_checker::check_font($asset['file'], $entry['origin'] === 'google' ? null : $asset['licence']);
            } else {
                // The served version of the zip, kept as is (checked, SVG sanitised again).
                $checked = file_checker::check_image(
                    $asset['file'],
                    $asset['reencoded'] ? $asset['file'] : null,
                    $asset['webp']
                );
            }
            $hash = sha1($checked['files']['optimized']['content']);
            $existing = $DB->get_record(library::TABLE, ['slug' => $slug]);
            if ($existing && $existing->contenthash === $hash) {
                continue;
            }
            $same = $DB->get_records(library::TABLE, ['contenthash' => $hash], 'id ASC', 'slug', 0, 1);
            if ($same) {
                $renamed[$slug] = reset($same)->slug;
                continue;
            }
            $newslug = $existing ? library::suggest_slug($slug) : $slug;
            library::add($checked, ['slug' => $newslug, 'alt' => $entry['alt'] ?? '', 'family' => $entry['family'] ?? '',
                'weight' => $entry['weight'] ?? 0, 'style' => $entry['style'] ?? '', 'subset' => $entry['subset'] ?? '',
                'origin' => $entry['kind'] === 'font' && $entry['origin'] === 'google' ? 'google' : 'import',
                'licence' => $entry['licence'] ?? null]);
            if (
                $entry['kind'] === 'font' && $entry['origin'] === 'google' && $asset['licence'] !== null
                    && in_array($entry['licence'] ?? '', \theme_brandable\local\assets\font_downloader::LICENCES, true)
            ) {
                library::set_family_licence(
                    (string) $entry['family'],
                    (string) $entry['licence'],
                    file_checker::check_licence((string) file_get_contents($asset['licence']))
                );
            }
            if ($newslug !== $slug) {
                $renamed[$slug] = $newslug;
            }
        }
        return self::rename_slugs($read['changes'], $renamed);
    }

    /**
     * Follows new slugs of the library in changes: markers of templates, SCSS and JavaScript (source and compiled
     * function), and settings.
     *
     * @param change[] $changes Changes.
     * @param array $renamed Old slug => new slug.
     * @return change[]
     */
    public static function rename_slugs(array $changes, array $renamed): array {
        if (!$renamed) {
            return $changes;
        }        // Markers and settings follow the new slugs.
        return array_map(function (change $change) use ($renamed): change {
            $content = (string) $change->content;
            if ($change->type === change::TYPE_SETTING) {
                $content = $renamed[$content] ?? $content;
            } else {
                $content = preg_replace_callback(
                    $change->type === change::TYPE_SCSS ? library::SCSS_MARKER : library::TEMPLATE_MARKER,
                    function (array $match) use ($renamed, $change): string {
                        $slug = $match[$change->type === change::TYPE_SCSS ? 2 : 1];
                        return isset($renamed[$slug]) ? str_replace($slug, $renamed[$slug], $match[0]) : $match[0];
                    },
                    $content
                );
            }
            // A module: the markers of its compiled function follow too (its source changes, so does its review).
            $compiled = $change->compiled === null ? null : preg_replace_callback(
                library::TEMPLATE_MARKER,
                fn(array $match) => isset($renamed[$match[1]]) ? str_replace($match[1], $renamed[$match[1]], $match[0]) : $match[0],
                $change->compiled
            );
            return new change(
                $change->type,
                $change->target,
                $content,
                $change->deleted,
                $change->remotesourcehash,
                $change->hasremotesourcehash,
                $change->scope,
                $compiled,
                $change->report,
                $change->signature
            );
        }, $changes);
    }

    /**
     * Reads the images and fonts listed by the manifest (exports made before the library have none).
     *
     * @param \zip_packer $packer Packer.
     * @param string $path Zip file.
     * @param string $root Theme folder.
     * @param array $files Sizes of the files of the zip.
     * @param array $manifest Manifest.
     * @return array Slug => ['entry', 'file', 'webp', 'licence', 'reencoded'] (paths of the extracted files).
     */
    private function read_assets(\zip_packer $packer, string $path, string $root, array $files, array $manifest): array {
        $list = $manifest['assets'] ?? [];
        if (!is_array($list) || !array_is_list($list)) {
            throw new moodle_exception('import:error:manifest', 'theme_brandable');
        }
        $wanted = [];
        $entries = [];
        foreach ($list as $entry) {
            $slug = is_array($entry) ? (string) ($entry['slug'] ?? '') : '';
            $paths = is_array($entry) ? array_filter([$entry['file'] ?? null, $entry['webp'] ?? null,
                $entry['licencefile'] ?? null]) : [];
            if (
                !preg_match(library::SLUG_PATTERN, $slug) || !in_array($entry['kind'] ?? '', ['image', 'font'], true)
                    || !isset($entry['file']) || isset($entries[$slug])
            ) {
                throw new moodle_exception('import:error:manifest', 'theme_brandable');
            }
            foreach ($paths as $file) {
                if (
                    !is_string($file) || !preg_match(self::ASSET_PATTERN, $file) || !isset($files[$file])
                        || $files[$file] > file_checker::MAX_FILE
                ) {
                    throw new moodle_exception('import:error:manifest', 'theme_brandable');
                }
                $wanted[$file] = $root . '/' . $file;
            }
            $entries[$slug] = $entry + ['origin' => 'upload'];
        }
        if (!$wanted) {
            return [];
        }
        $dir = make_request_directory();
        $done = $packer->extract_to_pathname($path, $dir, array_values($wanted));
        foreach ($wanted as $inzip) {
            if (!is_array($done) || ($done[$inzip] ?? null) !== true || !is_file("$dir/$inzip")) {
                throw new moodle_exception('import:error:zip', 'theme_brandable');
            }
        }
        $assets = [];
        foreach ($entries as $slug => $entry) {
            $at = fn(?string $file) => $file !== null ? "$dir/$root/$file" : null;
            $assets[$slug] = ['entry' => $entry, 'file' => $at($entry['file']), 'webp' => $at($entry['webp'] ?? null),
                'licence' => $at($entry['licencefile'] ?? null),
                'reencoded' => in_array($entry['mime'] ?? '', ['image/png', 'image/jpeg', 'image/webp'], true)];
        }
        return $assets;
    }

    /**
     * Checks the entries of the zip: one theme folder, no absolute path nor "..", sizes.
     *
     * @param array $entries Entries (zip_packer::list_files()).
     * @return string Name of the theme folder.
     */
    private function check_entries(array $entries): string {
        $root = null;
        $size = 0;
        foreach ($entries as $entry) {
            $name = (string) $entry->pathname;
            $parts = explode('/', rtrim($name, '/'));
            if (
                $name === '' || $name[0] === '/' || strpbrk($name, "\\:\0") !== false || in_array('..', $parts, true)
                    || in_array('.', $parts, true) || ($root !== null && $parts[0] !== $root)
            ) {
                throw new moodle_exception('import:error:zip', 'theme_brandable');
            }
            $root = $parts[0];
            $size += (int) $entry->size;
        }
        if (
            $root === null || count($entries) > self::MAX_ENTRIES || $size > self::MAX_CONTENT
                || !preg_match(theme_name::PATTERN, $root) || strlen($root) > theme_name::MAX_LENGTH
        ) {
            throw new moodle_exception('import:error:zip', 'theme_brandable');
        }
        return $root;
    }

    /**
     * Reads and checks brandable/manifest.json.
     *
     * @param \zip_packer $packer Packer.
     * @param string $path Zip file.
     * @param string $root Theme folder.
     * @param array $files Sizes of the files of the zip, by path in the theme folder.
     * @return array Manifest.
     */
    private function read_manifest(\zip_packer $packer, string $path, string $root, array $files): array {
        $file = 'brandable/manifest.json';
        if (!isset($files[$file]) || $files[$file] > self::MAX_FILE) {
            throw new moodle_exception('import:error:notexport', 'theme_brandable');
        }
        $manifest = json_decode($this->extract($packer, $path, [$file => $root . '/' . $file])[$file], true, 16);
        if (!is_array($manifest) || ($manifest['format'] ?? null) !== exporter::FORMAT) {
            throw new moodle_exception('import:error:notexport', 'theme_brandable');
        }
        foreach (['templates', 'variants', 'scss'] as $list) {
            if (!is_array($manifest[$list] ?? null) || !array_is_list($manifest[$list])) {
                throw new moodle_exception('import:error:manifest', 'theme_brandable');
            }
        }
        if (
            ($manifest['theme'] ?? null) !== $root || !is_string($manifest['parent'] ?? null)
                || !preg_match('/^\d{10}$/', (string) ($manifest['version'] ?? ''))
        ) {
            throw new moodle_exception('import:error:manifest', 'theme_brandable');
        }
        return $manifest;
    }

    /**
     * Extracts files of the zip and returns their contents.
     *
     * @param \zip_packer $packer Packer.
     * @param string $path Zip file.
     * @param array $files Path in the theme folder => path in the zip.
     * @return array Path in the theme folder => content.
     */
    private function extract(\zip_packer $packer, string $path, array $files): array {
        $dir = make_request_directory();
        if (!$files) {
            return [];
        }
        $done = $packer->extract_to_pathname($path, $dir, array_values($files));
        $contents = [];
        foreach ($files as $file => $inzip) {
            if (!is_array($done) || ($done[$inzip] ?? null) !== true || !is_file("$dir/$inzip")) {
                throw new moodle_exception('import:error:zip', 'theme_brandable');
            }
            $content = file_get_contents("$dir/$inzip");
            if (!mb_check_encoding($content, 'UTF-8')) {
                throw new moodle_exception('import:error:manifest', 'theme_brandable');
            }
            $contents[$file] = $content;
        }
        return $contents;
    }

    /**
     * Builds the change of a template of the zip, its theme_<name>/* names back to theme_brandable/*.
     *
     * @param string $root Theme name.
     * @param array $item Manifest item (template, file, sourcehash).
     * @param string $content Content of the file.
     * @param scope|null $scope Scope on this site, the site when null.
     * @return change|null Null (with a warning) when the template is not on this site.
     */
    private function template_change(string $root, array $item, string $content, ?scope $scope): ?change {
        $prefix = 'theme_' . $root . '/';
        $template = (string) ($item['template'] ?? '');
        if (strpos($template, $prefix) === 0) {
            $template = validator::CREATABLE_PREFIX . substr($template, strlen($prefix));
        } else {
            [$component] = explode('/', $template, 2);
            $known = $component === 'core' || core_component::get_component_directory($component) !== null;
            if (!$known || template_source::get_original($template) === null) {
                $this->warnings[] = get_string('import:templatemissing', 'theme_brandable', $template);
                return null;
            }
        }
        $content = preg_replace('~(\{\{\s*[<>]\s*)' . preg_quote($prefix, '~') . '~', '$1' . validator::CREATABLE_PREFIX, $content);
        $sourcehash = isset($item['sourcehash']) && is_string($item['sourcehash']) ? $item['sourcehash'] : null;
        return new change(change::TYPE_TEMPLATE, $template, $content, false, $sourcehash, $sourcehash !== null, $scope);
    }

    /**
     * Reads the JavaScript modules of the export: source, compiled function, report and signature, as delivered by
     * Brandable. A module whose source does not match the hash of the manifest is left out.
     *
     * @param \zip_packer $packer Packer.
     * @param string $path Zip.
     * @param string $root Folder of the theme.
     * @param array $files Files of the zip (relative path => size).
     * @param array $manifest Manifest.
     * @return change[]
     */
    private function read_js(\zip_packer $packer, string $path, string $root, array $files, array $manifest): array {
        $modules = is_array($manifest['js']['modules'] ?? null) ? $manifest['js']['modules'] : [];
        $wanted = [];
        foreach ($modules as $module) {
            $dir = is_array($module) ? (string) ($module['dir'] ?? '') : '';
            if (!preg_match(self::JS_DIR_PATTERN, $dir)) {
                throw new moodle_exception('import:error:manifest', 'theme_brandable');
            }
            foreach (self::JS_FILES as $name) {
                $file = $dir . '/' . $name;
                if (!isset($files[$file]) || $files[$file] > self::MAX_FILE) {
                    throw new moodle_exception('import:error:manifest', 'theme_brandable');
                }
                $wanted[$file] = $root . '/' . $file;
            }
        }
        $contents = $this->extract($packer, $path, $wanted);
        $changes = [];
        foreach ($modules as $module) {
            $dir = (string) $module['dir'];
            $target = (string) ($module['target'] ?? '');
            $content = $contents[$dir . '/source.js'] ?? '';
            if (!hash_equals((string) ($module['hash'] ?? ''), \theme_brandable\local\js\review::hash($content))) {
                $this->warnings[] = get_string('import:jschanged', 'theme_brandable', s($target));
                continue;
            }
            $scope = $this->local_scope($module['scope'] ?? null, $target);
            if ($scope === null) {
                continue;
            }
            $report = json_decode($contents[$dir . '/report.json'] ?? '', true);
            $changes[] = change::from_array([
                'type' => change::TYPE_JS,
                'target' => $target,
                'scope' => $scope->to_array(),
                'content' => $content,
                'compiled' => trim($contents[$dir . '/compiled.js'] ?? ''),
                'report' => is_array($report) ? $report : [],
                'signature' => trim($contents[$dir . '/signature.txt'] ?? ''),
            ]);
        }
        return $changes;
    }

    /**
     * Returns the scope on this site of a scope of the manifest: courses and activities found by their key.
     *
     * @param mixed $data Scope of the manifest (['level', 'value', 'by']).
     * @param string $target Target (for the warning).
     * @return scope|null Null (with a warning) when no course or activity of this site has the key.
     */
    private function local_scope($data, string $target): ?scope {
        global $DB;

        $level = is_array($data) ? (string) ($data['level'] ?? '') : '';
        $value = is_array($data) ? trim((string) ($data['value'] ?? '')) : '';
        $by = is_array($data) ? (string) ($data['by'] ?? 'id') : '';
        if ($level === scope::SITE) {
            return new scope();
        }
        if (in_array($level, [scope::PAGETYPE, scope::PAGE], true)) {
            return new scope($level, $value);
        }
        $ids = [];
        if ($by === 'id' && !ctype_digit($value)) {
            $value = '';
        }
        if ($level === scope::COURSE && in_array($by, exporter::COURSE_KEYS, true) && $value !== '') {
            $ids = $DB->get_fieldset_select('course', 'id', "$by = ? AND id <> ?", [$value, SITEID]);
        } else if ($level === scope::CM && in_array($by, exporter::CM_KEYS, true) && $value !== '') {
            $ids = $DB->get_fieldset_select('course_modules', 'id', "$by = ?", [$value]);
        } else if (!in_array($level, [scope::COURSE, scope::CM], true)) {
            throw new moodle_exception('import:error:manifest', 'theme_brandable');
        }
        if (count($ids) !== 1) {
            $this->warnings[] = get_string('import:scopedropped', 'theme_brandable', (object) ['target' => $target,
                'scope' => "$level $by: $value"]);
            return null;
        }
        return new scope($level, (string) reset($ids));
    }
}
