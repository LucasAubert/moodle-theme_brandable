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

namespace theme_brandable\local\inspector;

use cache;
use theme_brandable\local\preview;

/**
 * Page inspector: in preview, records the templates rendered by the last pages loaded in the editor iframe.
 *
 * Top-level templates (render_from_template) come with a sample of their context; partials are recorded
 * by name. Pages are stored in a session cache, indexed by URL (most recent first, bounded).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class inspector {
    /** @var int Maximum templates recorded per page. */
    public const MAX_TEMPLATES = 200;
    /** @var int Pages kept in the session. */
    public const MAX_PAGES = 10;

    /** @var array Templates recorded during this request, by name. */
    private static array $templates = [];

    /** @var bool Whether the shutdown function is registered. */
    private static bool $registered = false;

    /** @var bool|null Forced state (unit tests). */
    private static ?bool $forced = null;

    /**
     * Whether the current request is inspected: a page rendered in preview (not AJAX, web services or CLI).
     *
     * @return bool
     */
    public static function is_active(): bool {
        if (self::$forced !== null) {
            return self::$forced;
        }
        foreach (['CLI_SCRIPT', 'AJAX_SCRIPT', 'WS_SERVER'] as $constant) {
            if (defined($constant) && constant($constant)) {
                return false;
            }
        }
        return preview::is_active();
    }

    /**
     * Records a template rendered on the page.
     *
     * @param string $name Template name.
     * @param mixed $context Context, null for partials (no context available).
     */
    public static function record(string $name, $context = null): void {
        if (!self::is_active()) {
            return;
        }
        if (!isset(self::$templates[$name])) {
            if (count(self::$templates) >= self::MAX_TEMPLATES) {
                return;
            }
            self::$templates[$name] = ['name' => $name, 'sampleContext' => null];
        }
        if ($context !== null && self::$templates[$name]['sampleContext'] === null) {
            self::$templates[$name]['sampleContext'] = sampler::sample($context);
        }
        if (!self::$registered && self::$forced === null) {
            self::$registered = true;
            \core_shutdown_manager::register_function([self::class, 'save']);
        }
    }

    /**
     * Stores the templates of the current page in the session cache (shutdown function).
     */
    public static function save(): void {
        global $PAGE;

        if (!self::$templates || !$PAGE->has_set_url()) {
            return;
        }
        $page = \theme_brandable\local\overrides\page_context::current();
        self::store(
            $PAGE->url->out(false),
            (string) $PAGE->pagetype,
            array_values(self::$templates),
            $page->pagekey,
            $page->courseid,
            $page->cmid
        );
    }

    /**
     * Stores a page.
     *
     * @param string $url Page URL.
     * @param string $pagetype Page type.
     * @param array $templates Templates: [['name' => string, 'sampleContext' => mixed]].
     * @param string|null $pagekey Brandable page key (bp-*).
     * @param int|null $courseid Course id (not the site).
     * @param int|null $cmid Course module id.
     */
    public static function store(
        string $url,
        string $pagetype,
        array $templates,
        ?string $pagekey = null,
        ?int $courseid = null,
        ?int $cmid = null
    ): void {
        $cache = cache::make('theme_brandable', 'inspector');
        $pages = $cache->get('pages') ?: [];
        $key = self::normalise_url($url);
        unset($pages[$key]);
        $pages = [$key => ['url' => $url, 'pagetype' => $pagetype, 'pagekey' => $pagekey, 'courseid' => $courseid,
            'cmid' => $cmid, 'time' => time(), 'templates' => $templates]] + $pages;
        $cache->set('pages', array_slice($pages, 0, self::MAX_PAGES, true));
    }

    /**
     * Returns the recorded page for a URL, or the last recorded page when no URL is given.
     *
     * @param string|null $url Page URL.
     * @return array|null ['url', 'pagetype', 'pagekey', 'courseid', 'cmid', 'time', 'templates']
     */
    public static function get_page(?string $url = null): ?array {
        $pages = cache::make('theme_brandable', 'inspector')->get('pages') ?: [];
        if ($url === null) {
            return $pages ? reset($pages) : null;
        }
        return $pages[self::normalise_url($url)] ?? null;
    }

    /**
     * Normalises a URL to index pages (no fragment, sorted query parameters).
     *
     * @param string $url URL.
     * @return string
     */
    public static function normalise_url(string $url): string {
        $url = strtok($url, '#');
        $parts = parse_url($url);
        $query = [];
        parse_str($parts['query'] ?? '', $query);
        ksort($query);
        $path = $parts['path'] ?? '/';
        return $path . ($query ? '?' . http_build_query($query) : '');
    }

    /**
     * Resets the request state, optionally forcing the active state (unit tests).
     *
     * @param bool|null $forced Forced state.
     */
    public static function reset(?bool $forced = null): void {
        self::$templates = [];
        self::$registered = false;
        self::$forced = $forced;
    }

    /**
     * Returns the templates recorded during this request (unit tests).
     *
     * @return array
     */
    public static function get_recorded(): array {
        return array_values(self::$templates);
    }
}
