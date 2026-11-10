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

namespace theme_brandablert\brandable;

/**
 * Generated JavaScript of the theme (modules of Brandable, listed by brandable/manifest.json → js): whether they run on
 * a page, which ones, and the file of the modules, an AMD module returning their compiled functions (never evaluated
 * here). The library b is the AMD module theme_brandablert/runtime.
 *
 * Settings of the theme: brandable_js (on at the export when it was on the site of Brandable), brandable_jssuspended
 * (kill switch); ?brandable_nojs=1 turns them off on a page, for the administrators.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class js {
    /** @var string Setting: the modules run. */
    public const ENABLED_CONFIG = 'brandable_js';

    /** @var string Setting: kill switch. */
    public const SUSPENDED_CONFIG = 'brandable_jssuspended';

    /** @var string Setting: key of the opaque identifiers of the users (b.storage). */
    public const USERKEY_CONFIG = 'brandable_jsuserkey';

    /** @var string URL parameter turning the modules off on the page, for the administrators. */
    public const NOJS_PARAM = 'brandable_nojs';

    /** @var string Marker of the library in a module. */
    public const MARKER = '/\[\[brandable-asset:([a-z0-9]+(?:-[a-z0-9]+)*)\]\]/';

    /**
     * Returns the modules of the theme (manifest).
     *
     * @return array[] List of ['key', 'target', 'scope', 'dir', 'hash', 'errors'].
     */
    public static function get_modules(): array {
        $js = manifest::get()['js'] ?? null;
        return is_array($js) && is_array($js['modules'] ?? null) ? array_values(array_filter($js['modules'], 'is_array')) : [];
    }

    /**
     * Whether the modules run on the site: the setting of the theme (on at the export when it was on), and no kill
     * switch.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        $enabled = get_config('theme_brandablert', self::ENABLED_CONFIG);
        if ($enabled === false) {
            // Not saved yet: the state at the export.
            $enabled = !empty(manifest::get()['js']['enabled']);
        }
        return (bool) $enabled && !get_config('theme_brandablert', self::SUSPENDED_CONFIG);
    }

    /**
     * Whether modules run on a page: on, never on the administration pages, nor when an administrator turned them off
     * for the page.
     *
     * @param \moodle_page $page Page.
     * @return bool
     */
    public static function run_on(\moodle_page $page): bool {
        if (during_initial_install() || !self::get_modules() || !self::is_enabled()) {
            return false;
        }
        $path = $page->has_set_url() ? $page->url->get_path() : '';
        if (
            $page->pagelayout === 'admin' || strpos((string) $page->pagetype, 'admin-') === 0
                || strpos($path, '/admin/') !== false
        ) {
            return false;
        }
        return !(optional_param(self::NOJS_PARAM, 0, PARAM_BOOL)
            && has_capability('moodle/site:config', \context_system::instance()));
    }

    /**
     * Returns the modules of a page: for each target, the variant of the most precise scope matching the page, by
     * target in alphabetical order; a module with errors in its analysis never runs.
     *
     * @param \moodle_page $page Page.
     * @param string|null $pagekey Page key (bp-*).
     * @return array[] Modules.
     */
    public static function select(\moodle_page $page, ?string $pagekey): array {
        $best = [];
        foreach (self::get_modules() as $module) {
            $target = (string) ($module['target'] ?? '');
            $scope = scope::from_array(is_array($module['scope'] ?? null) ? $module['scope'] : null);
            if ($target === '' || !$scope->matches($page, $pagekey)) {
                continue;
            }
            if (!isset($best[$target]) || $scope->rank() > $best[$target][0]) {
                $best[$target] = [$scope->rank(), $module];
            }
        }
        ksort($best);
        return array_values(array_filter(array_column($best, 1), fn(array $module) => empty($module['errors'])));
    }

    /**
     * Adds the runtime to a page, with its modules (theme_brandablert_page_init).
     *
     * @param \moodle_page $page Page.
     * @param string|null $pagekey Page key (bp-*).
     */
    public static function page_init(\moodle_page $page, ?string $pagekey): void {
        if (!self::run_on($page)) {
            return;
        }
        $modules = self::select($page, $pagekey);
        if (!$modules) {
            return;
        }
        $course = $page->course && (int) $page->course->id !== (int) SITEID ? (string) $page->course->id : null;
        $page->requires->js_call_amd('theme_brandablert/runtime', 'init', [[
            'url' => (new \moodle_url('/theme/brandablert/js.php', ['h' => self::bundle_hash()]))->out(false),
            'modules' => array_map(fn(array $module) => ['key' => (string) $module['key'],
                'target' => (string) $module['target']], $modules),
            'page' => [
                'pagetype' => (string) $page->pagetype,
                'pageKey' => (string) $pagekey,
                'courseId' => $course,
                'cmId' => $page->cm ? (string) $page->cm->id : null,
                'lang' => current_language(),
            ],
            'user' => self::user_key(),
            'preview' => false,
        ]]);
    }

    /**
     * Returns the file of the modules: an AMD module returning the compiled function of each module by key, as
     * Brandable delivered it; the markers of the library become URLs of the theme.
     *
     * @return string JavaScript.
     */
    public static function bundle(): string {
        $functions = [];
        foreach (self::get_modules() as $module) {
            if (!empty($module['errors'])) {
                continue;
            }
            $compiled = manifest::read(rtrim((string) ($module['dir'] ?? ''), '/') . '/compiled.js');
            if (strpos($compiled, 'function(') !== 0) {
                continue;
            }
            $functions[] = json_encode((string) $module['key']) . ': ' . self::resolve_markers($compiled);
        }
        return "define([], function() {\n\"use strict\";\nreturn {\n" . implode(",\n", $functions) . "\n};\n});\n";
    }

    /**
     * Returns the hash of the file of the modules (part of its URL).
     *
     * @return string
     */
    public static function bundle_hash(): string {
        return substr(sha1(self::bundle()), 0, 16);
    }

    /**
     * Replaces the markers of the library in a compiled function: characters that could end a string are encoded.
     *
     * @param string $code Code.
     * @return string
     */
    public static function resolve_markers(string $code): string {
        return preg_replace_callback(self::MARKER, function (array $match): string {
            $url = assets::url($match[1]);
            return strtr($url ? $url->out(false) : '', ['"' => '%22', "'" => '%27', "\x60" => '%60', '\\' => '%5C',
                "\n" => '', "\r" => '', '<' => '%3C', '>' => '%3E']);
        }, $code);
    }

    /**
     * Returns the opaque identifier of the current user for b.storage (HMAC by a key of the site).
     *
     * @return string
     */
    public static function user_key(): string {
        global $USER;
        $key = (string) get_config('theme_brandablert', self::USERKEY_CONFIG);
        if ($key === '') {
            $key = random_string(32);
            set_config(self::USERKEY_CONFIG, $key, 'theme_brandablert');
        }
        return substr(hash_hmac('sha256', (string) (int) ($USER->id ?? 0), $key), 0, 16);
    }
}
