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
 * Brandable's own page key: body class bp-<key> and page.pageKey sent to the generator.
 *
 * Moodle's pagetype and body id are not unique per page (the dashboard and "My courses" share one), so the
 * key is derived from the script actually run, relative to the Moodle root ($CFG->dirroot, which is the
 * public/ folder on 5.1+), without extension, in kebab-case:
 *   /my/index.php -> bp-my-index, /my/courses.php -> bp-my-courses, /mod/forum/discuss.php -> bp-mod-forum-discuss.
 *
 * Routed pages (Moodle 5.x router, r.php) all run the same script: their key comes from the route path
 * (request path after r.php, numeric segments such as ids removed): /r.php/course/12/manage -> bp-course-manage.
 *
 * @package    theme_brandablert
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page_key {
    /** @var string Prefix of the keys (and body classes). */
    public const PREFIX = 'bp-';

    /** @var string Pattern of a valid key. */
    public const PATTERN = '~^bp-[a-z0-9]+(-[a-z0-9]+)*$~';

    /**
     * Returns the key of the current page, or null when there is no page script (CLI, unknown script).
     *
     * @return string|null
     */
    public static function current(): ?string {
        global $CFG;

        // Read only, as setup.php does: the script run and, for the routed pages of Moodle 5.1+, the request path.
        $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
        if ($script === '') {
            return null;
        }
        return self::from_script($script, $CFG->dirroot, (string) ($_SERVER['REQUEST_URI'] ?? ''), $CFG->wwwroot);
    }

    /**
     * Computes the key of a script file.
     *
     * @param string $scriptfile Absolute path of the script run.
     * @param string $dirroot Moodle root ($CFG->dirroot: the public/ folder on 5.1+).
     * @param string $requesturi Request URI (for routed pages).
     * @param string $wwwroot Site URL (for routed pages).
     * @return string|null
     */
    public static function from_script(
        string $scriptfile,
        string $dirroot,
        string $requesturi = '',
        string $wwwroot = ''
    ): ?string {
        $file = self::normalise_path(realpath($scriptfile) ?: $scriptfile);
        $root = rtrim(self::normalise_path(realpath($dirroot) ?: $dirroot), '/');
        if ($root === '' || strpos($file, $root . '/') !== 0) {
            return null;
        }
        $relative = substr($file, strlen($root));
        if ($relative === '/r.php') {
            return self::from_route($requesturi, $wwwroot);
        }
        return self::from_path($relative);
    }

    /**
     * Computes the key of a script path relative to the Moodle root (/my/index.php -> bp-my-index).
     *
     * @param string $relativepath Path such as /mod/forum/discuss.php.
     * @return string|null
     */
    public static function from_path(string $relativepath): ?string {
        $path = preg_replace('~\.[^./]*$~', '', self::normalise_path($relativepath));
        $key = trim(preg_replace('~[^a-z0-9]+~', '-', strtolower((string) $path)), '-');
        return $key === '' ? null : self::PREFIX . $key;
    }

    /**
     * Computes the key of a routed page from its request path.
     *
     * @param string $requesturi Request URI.
     * @param string $wwwroot Site URL.
     * @return string|null
     */
    public static function from_route(string $requesturi, string $wwwroot): ?string {
        $path = (string) parse_url($requesturi, PHP_URL_PATH);
        $base = rtrim((string) parse_url($wwwroot, PHP_URL_PATH), '/');
        if ($base !== '' && strpos($path, $base . '/') === 0) {
            $path = substr($path, strlen($base));
        }
        $path = preg_replace('~^/r\.php(?=/|$)~', '', $path);
        $segments = array_filter(explode('/', $path), fn($segment) => $segment !== '' && !ctype_digit($segment));
        return $segments ? self::from_path('/' . implode('/', $segments)) : self::from_path('/r.php');
    }

    /**
     * Normalises directory separators.
     *
     * @param string $path Path.
     * @return string
     */
    private static function normalise_path(string $path): string {
        return str_replace('\\', '/', $path);
    }
}
