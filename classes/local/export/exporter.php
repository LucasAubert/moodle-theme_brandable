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

use theme_brandable\local\assets\file_checker;
use theme_brandable\local\assets\library;
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\resolver;
use theme_brandable\local\overrides\revision_manager;
use theme_brandable\local\overrides\scope;
use theme_brandable\local\parent_theme;

/**
 * Builds the zip of a standalone theme from the published version (spec S5 to S8).
 *
 * The PHP of the theme is the static runtime (export/runtime), the runtime theme name being the only substitution;
 * the data (version, licence) are written by var_export() of validated values. What the generator produced (templates,
 * SCSS) only goes to .mustache and .scss files.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class exporter {
    /**
     * @var string Root of the site in a template, rendered in PHP and in JavaScript: PHP has the config helper and no
     * globals; the JavaScript renderer of core has globals.config, and config only when the caller puts it in the
     * context.
     */
    public const WWWROOT = '{{#config.wwwroot}}{{config.wwwroot}}{{/config.wwwroot}}'
        . '{{^config.wwwroot}}{{globals.config.wwwroot}}{{/config.wwwroot}}';

    /** @var int Format of brandable/manifest.json. */
    public const FORMAT = 1;

    /** @var string[] Keys of a course on another site. */
    public const COURSE_KEYS = ['shortname', 'idnumber', 'id'];

    /** @var string[] Keys of an activity on another site. */
    public const CM_KEYS = ['idnumber', 'id'];

    /** @var string[] Warnings of the build (scopes left out). */
    private array $warnings = [];

    /** @var int JavaScript modules of the build. */
    private int $jsmodules = 0;

    /**
     * Constructor.
     *
     * @param string $name Theme name, without theme_ (checked with theme_name::check()).
     * @param string $version Theme version (10 digits).
     * @param string $note Version note.
     * @param licence $licence Licence written in the theme.
     * @param array $mapping Course and activity scopes on the target site, by "level:id": ['by' => key, 'value' =>
     *     value], or null to leave the scope out. Missing: the default mapping (see get_scoped_targets()).
     * @param array|null $map Resolved map to export instead of the published version (integration test fixture).
     */
    public function __construct(
        /** @var string Theme name. */
        private readonly string $name,
        /** @var string Theme version. */
        private readonly string $version,
        /** @var string Version note. */
        private readonly string $note,
        /** @var licence Licence. */
        private readonly licence $licence,
        /** @var array Mapping of the scopes. */
        private readonly array $mapping = [],
        /** @var array|null Map exported instead of the published version. */
        private readonly ?array $map = null,
    ) {
        if (!preg_match(theme_name::PATTERN, $name) || !preg_match('/^\d{10}$/', $version)) {
            throw new \coding_exception('Invalid theme name or version');
        }
    }

    /**
     * Lists the course and activity scopes of the published version, with their default key on another site.
     *
     * @return array List of ['key' => "level:id", 'level', 'id', 'label', 'default' => ['by', 'value'] or null].
     */
    public static function get_scoped_targets(): array {
        global $DB;

        $targets = [];
        foreach (resolver::get_map(revision_manager::get_published()) as $entries) {
            foreach ($entries as $entry) {
                $scope = resolver::entry_scope($entry);
                $key = $scope->level . ':' . $scope->value;
                if (!in_array($scope->level, [scope::COURSE, scope::CM], true) || isset($targets[$key])) {
                    continue;
                }
                $default = null;
                if ($scope->level === scope::COURSE) {
                    $shortname = $DB->get_field('course', 'shortname', ['id' => $scope->value]);
                    $default = $shortname !== false ? ['by' => 'shortname', 'value' => (string) $shortname] : null;
                } else {
                    $idnumber = (string) $DB->get_field('course_modules', 'idnumber', ['id' => $scope->value]);
                    $default = $idnumber !== '' ? ['by' => 'idnumber', 'value' => $idnumber] : null;
                }
                $targets[$scope->level . ':' . $scope->value] = [
                    'key' => $scope->level . ':' . $scope->value,
                    'level' => $scope->level,
                    'id' => (int) $scope->value,
                    'label' => $scope->label(),
                    'default' => $default,
                ];
            }
        }
        return array_values($targets);
    }

    /**
     * Returns the version of the theme: YYYYMMDD00 of the day, or more when this name was already exported.
     *
     * @param string $name Theme name.
     * @param string|null $minimum Smallest version accepted by Brandable (GET /exports/latest), null if none.
     * @return string 10 digits.
     */
    public static function next_version(string $name, ?string $minimum = null): string {
        global $DB;

        $versions = [(int) (date('Ymd') . '00')];
        $last = $DB->get_field_sql('SELECT MAX(themeversion) FROM {theme_brandable_export} WHERE themename = ?', [$name]);
        if ($last) {
            $versions[] = (int) $last + 1;
        }
        if ($minimum !== null && preg_match('/^\d{10}$/', $minimum)) {
            $versions[] = (int) $minimum;
        }
        return (string) max($versions);
    }

    /**
     * Writes the zip of the theme.
     *
     * @param string $path Zip file to write.
     * @return string[] Warnings (scopes left out).
     */
    public function build(string $path): array {
        $this->warnings = [];
        $version = revision_manager::get_current_version();
        if ($this->map === null && !$version) {
            throw new \moodle_exception('export:error:nothingpublished', 'theme_brandable');
        }
        $map = $this->map ?? resolver::get_map(revision_manager::get_published());
        $parent = parent_theme::get_name();
        $root = $this->name . '/';

        $files = $this->runtime_files();
        $manifest = [
            'format' => self::FORMAT,
            'runtime' => trim((string) file_get_contents(self::runtime_dir() . '/VERSION')),
            'theme' => $this->name,
            'parent' => $parent,
            'version' => $this->version,
            'source' => ['moodle' => \theme_brandable\local\overrides\template_source::get_moodle_version(),
                'brandable' => self::plugin()->release, 'versionnumber' => (int) ($version->number ?? 0)],
            'templates' => [],
            'variants' => [],
            'scss' => [],
            'assets' => [],
            'settings' => [],
        ];
        // Images and fonts the version uses, copied into the theme (contract 11.6, step 5: licences next to them).
        [$assetfiles, $manifest['assets'], $assetsurls] = $this->assets($map, $root);
        $files += $assetfiles;
        foreach ($map[change::TYPE_SETTING] ?? [] as $entry) {
            if (isset($assetsurls[$entry['content']])) {
                $manifest['settings'][$entry['target']] = $entry['content'];
            }
        }
        $count = 0;
        foreach ($map[change::TYPE_TEMPLATE] as $entry) {
            $scope = $this->map_scope(resolver::entry_scope($entry), $entry['target']);
            if ($scope === null) {
                continue;
            }
            $template = $this->rename_template($entry['target']);
            // Markers become URLs of the theme: templates of the whole site are standard files (also read by core for
            // the rendering in JavaScript); the root of the site is written for both renderers (WWWROOT).
            $content = $this->rename_partials(preg_replace_callback(
                library::TEMPLATE_MARKER,
                fn($match) => $assetsurls[$match[1]] ?? '',
                $entry['content']
            ));
            if ($scope['level'] === scope::SITE) {
                $file = 'templates/' . $this->template_path($template);
                $manifest['templates'][] = ['template' => $template, 'file' => $file, 'sourcehash' => $entry['sourcehash']];
            } else {
                $file = 'brandable/variants/' . (++$count) . '.mustache';
                $manifest['variants'][] = ['template' => $template, 'file' => $file, 'scope' => $scope,
                    'sourcehash' => $entry['sourcehash']];
            }
            $files[$root . $file] = [$content];
        }
        $count = 0;
        foreach ($map[change::TYPE_SCSS] as $entry) {
            $scope = $this->map_scope(resolver::entry_scope($entry), $entry['target']);
            if ($scope === null) {
                continue;
            }
            $file = 'brandable/scss/' . (++$count) . '.scss';
            $manifest['scss'][] = ['target' => $entry['target'], 'section' => explode('/', $entry['target'])[0],
                'file' => $file, 'scope' => $scope];
            $files[$root . $file] = [$entry['content']];
        }

        // Generated JavaScript (spec B15): the modules as delivered, the library b, the state of the activation.
        [$jsfiles, $manifest['js']] = $this->javascript($map, $root);
        $files += $jsfiles;
        $this->jsmodules = count($manifest['js']['modules']);
        $files[$root . 'brandable/manifest.json'] = [json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE) . "\n"];
        $files[$root . 'version.php'] = [$this->version_php($parent)];
        $files[$root . 'licence.php'] = [$this->licence->to_php($this->name, $this->version)];
        $files[$root . 'README.md'] = [$this->readme($parent)];
        $files[$root . 'CHANGELOG.md'] = [$this->changelog()];
        $files[$root . 'COPYING.txt'] = [self::gpl_text()];

        $packer = get_file_packer('application/zip');
        if (!$packer->archive_to_pathname($files, $path)) {
            throw new \moodle_exception('export:error:zip', 'theme_brandable');
        }
        return $this->warnings;
    }

    /**
     * Returns the files of the images and fonts the version uses (served version, WebP version, reduced logos, licences
     * of the fonts), their manifest entries, and the URL of each in a template.
     *
     * @param array $map Resolved map exported.
     * @param string $root Folder of the theme in the zip.
     * @return array [files, manifest entries, URLs by slug]
     */
    private function assets(array $map, string $root): array {
        $library = library::get_all();
        $settings = [];
        foreach ($map[change::TYPE_SETTING] ?? [] as $entry) {
            $settings[$entry['content']][] = $entry['target'];
        }
        $sizes = ['logo' => ['0x200'], 'logocompact' => ['300x300', '100x100'], 'favicon' => ['64x64']];
        $files = [];
        $entries = [];
        $urls = [];
        $licences = [];
        foreach (library::slugs_in_map($map) as $slug) {
            $asset = $library[$slug] ?? null;
            if ($asset === null) {
                // Deleted from the library: the marker becomes empty (contract 11.3), as on this site.
                continue;
            }
            $extension = file_checker::EXTENSIONS[$asset->mime] ?? 'bin';
            $served = library::get_file($asset, 'optimized');
            if (!$served) {
                continue;
            }
            $hash = substr($asset->contenthash, 0, 12);
            $entry = ['slug' => $slug, 'kind' => $asset->kind, 'mime' => $asset->mime,
                'file' => "brandable/assets/$slug.$extension", 'hash' => $hash];
            $files[$root . $entry['file']] = [$served->get_content()];
            if ($webp = library::get_file($asset, 'webp')) {
                $entry['webp'] = "brandable/assets/$slug.webp";
                $files[$root . $entry['webp']] = [$webp->get_content()];
            }
            foreach ($settings[$slug] ?? [] as $target) {
                foreach ($sizes[$target] as $size) {
                    $reduced = \theme_brandable\local\assets\server::resized($asset, $served, $size);
                    if ($reduced->get_id() !== $served->get_id()) {
                        $entry['sizes'][$size] = "brandable/assets/$slug.$size.$extension";
                        $files[$root . $entry['sizes'][$size]] = [$reduced->get_content()];
                    }
                }
            }
            if ($asset->kind === 'image') {
                $entry += ['width' => (int) $asset->width, 'height' => (int) $asset->height, 'alt' => (string) $asset->alt];
            } else {
                $entry += ['family' => $asset->family, 'weight' => (int) $asset->weight, 'style' => $asset->style,
                    'subset' => (string) $asset->subset, 'origin' => $asset->origin, 'licence' => (string) $asset->licence];
                $family = library::get_family((string) $asset->family);
                $own = library::get_file($asset, 'licence');
                if ($own) {
                    // Licence given with a font of the administrator.
                    $entry['licencefile'] = "brandable/assets/licences/$slug.txt";
                    $files[$root . $entry['licencefile']] = [$own->get_content()];
                } else if ($family && (string) $family->licencetext !== '') {
                    $name = trim(preg_replace('/[^a-z0-9]+/', '-', $family->familykey), '-') ?: 'font';
                    $entry['licencefile'] = "brandable/assets/licences/$name.txt";
                    $licences[$root . $entry['licencefile']] = [$family->licencetext];
                }
            }
            $entries[] = $entry;
            $urls[$slug] = self::WWWROOT . '/theme/' . $this->name . '/asset.php/' . $hash . '/' . basename($entry['file']);
        }
        return [$files + $licences, $entries, $urls];
    }

    /**
     * Returns the header of a PHP file written at the export (licence block and docblock).
     *
     * @param string $description Description of the file.
     * @param string $name Theme name.
     * @return string
     */
    public static function php_header(string $description, string $name): string {
        $runtime = file_get_contents(self::runtime_dir() . '/config.php');
        $licence = substr($runtime, strlen("<?php\n"), strpos($runtime, "\n\n/**") - strlen("<?php\n"));
        return $licence . "\n\n/**\n * " . str_replace(['*/', "\n"], ['', ' '], $description) . "\n *\n"
            . " * @package    theme_{$name}\n * @copyright  " . date('Y') . " Brandable\n"
            . " * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later\n */\n\n";
    }

    /**
     * Returns the folder of the static runtime.
     *
     * @return string
     */
    public static function runtime_dir(): string {
        return dirname(__DIR__, 3) . '/export/runtime';
    }

    /**
     * Returns the files of the generated JavaScript and its manifest entry: one folder per module under brandable/js/
     * (source.js, compiled.js, report.json, signature.txt, as stored: the source keeps its markers, the signature stays
     * valid), and the library b (amd/ of the plugin, its component renamed). Served by js.php of the theme, which
     * resolves the markers with the root of the target site.
     *
     * @param array $map Resolved map exported.
     * @param string $root Folder of the theme in the zip.
     * @return array [files, manifest entry: ['enabled' => bool, 'modules' => [...]]]
     */
    private function javascript(array $map, string $root): array {
        $files = [];
        $modules = [];
        $count = 0;
        foreach ($map[change::TYPE_JS] ?? [] as $entry) {
            $scope = $this->map_scope(resolver::entry_scope($entry), $entry['target']);
            if ($scope === null) {
                continue;
            }
            $dir = 'brandable/js/' . (++$count) . '-' . substr((string) $entry['target'], strlen('js/'));
            $report = json_decode((string) ($entry['report'] ?? ''), true);
            $report = is_array($report) ? $report : [];
            $files[$root . $dir . '/source.js'] = [(string) $entry['content']];
            $files[$root . $dir . '/compiled.js'] = [(string) ($entry['compiled'] ?? '')];
            $files[$root . $dir . '/report.json'] = [json_encode($report ?: new \stdClass(), JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"];
            $files[$root . $dir . '/signature.txt'] = [(string) ($entry['signature'] ?? '') . "\n"];
            $modules[] = [
                'key' => 'm' . $count,
                'target' => (string) $entry['target'],
                'scope' => $scope,
                'dir' => $dir,
                'hash' => \theme_brandable\local\js\review::hash((string) $entry['content']),
                'signature' => (string) ($entry['signature'] ?? ''),
                'analyzer' => (string) ($report['analyzer'] ?? ''),
                'errors' => !empty($report['errors']),
            ];
        }
        if (!$modules) {
            return [[], ['enabled' => false, 'modules' => []]];
        }
        $amd = dirname(__DIR__, 3) . '/amd';
        foreach (['src/runtime.js', 'build/runtime.min.js', 'build/runtime.min.js.map'] as $file) {
            $files[$root . 'amd/' . $file] = [str_replace(
                'theme_brandable',
                'theme_' . $this->name,
                (string) file_get_contents($amd . '/' . $file)
            )];
        }
        // On at the export when it is on here (spec B15, Q3); the kill switch of the theme starts off.
        return [$files, ['enabled' => \theme_brandable\local\js\modules::is_enabled(), 'modules' => $modules]];
    }

    /**
     * Returns the files of the static runtime, the runtime theme name replaced by the theme name.
     *
     * @return array Path in the zip => [content].
     */
    private function runtime_files(): array {
        $dir = self::runtime_dir();
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
            if ($relative === 'VERSION') {
                continue;
            }
            $relative = str_replace(theme_name::RUNTIME_TOKEN, $this->name, $relative);
            $content = str_replace(theme_name::RUNTIME_TOKEN, $this->name, file_get_contents($file->getPathname()));
            // The PHPDoc of the runtime names the plugin where it lives (theme_brandable, checked by moodle-cs):
            // it becomes the exported theme.
            $content = preg_replace('/^(\s*\*\s*@package\s+)theme_brandable$/m', '${1}theme_' . $this->name, $content);
            $files[$this->name . '/' . $relative] = [$content];
        }
        return $files;
    }

    /**
     * Returns the scope of an override on the target site (manifest), or null when it is left out.
     *
     * @param scope $scope Scope on this site.
     * @param string $target Override target (for the warning).
     * @return array|null ['level', 'value', 'by'].
     */
    private function map_scope(scope $scope, string $target): ?array {
        if (!in_array($scope->level, [scope::COURSE, scope::CM], true)) {
            return ['level' => $scope->level, 'value' => (string) $scope->value, 'by' => 'id'];
        }
        $key = $scope->level . ':' . $scope->value;
        if (array_key_exists($key, $this->mapping)) {
            $mapped = $this->mapping[$key];
        } else {
            $defaults = array_column(self::get_scoped_targets(), 'default', 'key');
            $mapped = $defaults[$key] ?? null;
        }
        $keys = $scope->level === scope::COURSE ? self::COURSE_KEYS : self::CM_KEYS;
        if (!is_array($mapped) || !in_array($mapped['by'] ?? '', $keys, true) || trim((string) ($mapped['value'] ?? '')) === '') {
            $this->warnings[] = get_string('export:scopedropped', 'theme_brandable', (object) ['target' => $target,
                'scope' => $scope->label()]);
            return null;
        }
        return ['level' => $scope->level, 'value' => trim((string) $mapped['value']), 'by' => $mapped['by']];
    }

    /**
     * Renames a template created by Brandable (theme_brandable/*) into the exported theme.
     *
     * @param string $template Template name.
     * @return string
     */
    private function rename_template(string $template): string {
        return preg_replace('~^theme_brandable/~', 'theme_' . $this->name . '/', $template);
    }

    /**
     * Points the partials and parents of a template created by Brandable to the exported theme.
     *
     * @param string $content Template content.
     * @return string
     */
    private function rename_partials(string $content): string {
        return preg_replace('~(\{\{\s*[<>]\s*)theme_brandable/~', '$1theme_' . $this->name . '/', $content);
    }

    /**
     * Returns the path of a template of the whole site under templates/.
     *
     * @param string $template Template name (component/name).
     * @return string
     */
    private function template_path(string $template): string {
        [$component, $name] = explode('/', $template, 2);
        return ($component === 'theme_' . $this->name ? '' : $component . '/') . $name . '.mustache';
    }

    /**
     * Returns version.php (values written by var_export()).
     *
     * @param string $parent Parent theme.
     * @return string
     */
    private function version_php(string $parent): string {
        $plugin = self::plugin();
        $firstline = trim(strtok($this->note, "\n") ?: '');
        $release = date('Y-m-d') . ($firstline !== '' ? ' - ' . \core_text::substr($firstline, 0, 100) : '');
        $header = self::php_header('Version of theme_' . $this->name . ' (written by Brandable at the export).', $this->name);
        return "<?php\n" . $header
            . "defined('MOODLE_INTERNAL') || die();\n\n"
            . '$plugin->component = ' . var_export('theme_' . $this->name, true) . ";\n"
            . '$plugin->version = ' . var_export((int) $this->version, true) . ";\n"
            . '$plugin->release = ' . var_export($release, true) . ";\n"
            . '$plugin->requires = ' . var_export((int) $plugin->requires, true) . ";\n"
            . '$plugin->supported = ' . var_export(array_map('intval', $plugin->supported), true) . ";\n"
            . "\$plugin->maturity = MATURITY_STABLE;\n"
            . '$plugin->dependencies = [' . var_export('theme_' . $parent, true) . " => ANY_VERSION];\n";
    }

    /**
     * Returns the README of the theme.
     *
     * @param string $parent Parent theme.
     * @return string
     */
    private function readme(string $parent): string {
        $a = (object) ['name' => $this->name, 'parent' => $parent, 'version' => $this->version,
            'warnings' => $this->warnings ? "\n- " . implode("\n- ", $this->warnings) : '-'];
        $readme = get_string('export:readme', 'theme_brandable', $a) . "\n";
        if (in_array('boost', \theme_brandable\local\parent_theme::get_chain($parent), true)) {
            // Colour modes of Boost (Moodle 5.3): the dark variants of the version follow them.
            $readme .= "\n" . get_string('export:readmecolourmodes', 'theme_brandable') . "\n";
        }
        if ($this->jsmodules) {
            // The generated JavaScript: where it is, how to stop it (spec B15).
            $readme .= "\n" . get_string('export:readmejs', 'theme_brandable', (object) [
                'count' => $this->jsmodules,
                'nojs' => \theme_brandable\local\js\modules::NOJS_PARAM,
            ]) . "\n";
        }
        return $readme;
    }

    /**
     * Returns the CHANGELOG: the notes of every version exported under this name from this site, newest first.
     *
     * @return string
     */
    private function changelog(): string {
        global $DB;

        $entries = [['themeversion' => $this->version, 'timecreated' => time(), 'note' => $this->note]];
        foreach ($DB->get_records('theme_brandable_export', ['themename' => $this->name], 'themeversion DESC') as $record) {
            $entries[] = (array) $record;
        }
        $text = '# Changelog: theme_' . $this->name . "\n";
        foreach ($entries as $entry) {
            $text .= "\n## " . $entry['themeversion'] . ' (' . date('Y-m-d', (int) $entry['timecreated']) . ")\n\n"
                . (trim((string) $entry['note']) !== '' ? trim((string) $entry['note']) : '-') . "\n";
        }
        return $text;
    }

    /**
     * Returns the version details of Brandable (requires, supported, release).
     *
     * @return \stdClass
     */
    private static function plugin(): \stdClass {
        $plugin = new \stdClass();
        require(dirname(__DIR__, 3) . '/version.php');
        return $plugin;
    }

    /**
     * Returns the text of the GNU GPL v3 (the copy shipped with Moodle).
     *
     * @return string
     */
    private static function gpl_text(): string {
        global $CFG;
        foreach ([$CFG->dirroot . '/COPYING.txt', dirname($CFG->dirroot) . '/COPYING.txt'] as $file) {
            if (is_readable($file)) {
                return file_get_contents($file);
            }
        }
        return "GNU General Public License v3 or later: https://www.gnu.org/licenses/gpl-3.0.txt\n";
    }
}
