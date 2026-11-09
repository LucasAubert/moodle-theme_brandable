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

/**
 * Markers of the assets (contract 11.3, spec A9): replaced by the URL of the asset when rendering, never stored.
 *
 * - SCSS: brandable-asset("slug") becomes url("<URL>"); an asset missing from the library becomes url("data:,").
 * - Templates: [[brandable-asset:slug]] becomes the URL escaped for an HTML attribute; missing: empty string.
 *
 * Revisions keep the markers: the export and the import only change how they are resolved.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class markers {
    /** @var string Anything that looks like a marker, for the check of their form (SCSS). */
    private const SCSS_ANY = '/brandable-asset\s*\(/';

    /** @var string Anything that looks like a marker, for the check of their form (templates). */
    private const TEMPLATE_ANY = '/\[\[\s*brandable-asset/';

    /** @var string A marker inside url(): refused (contract 11.5). */
    private const SCSS_IN_URL = '/url\(\s*["\']?\s*brandable-asset/';

    /** @var array|null URLs by slug, for the revision of the library in $revision. */
    private static ?array $urls = null;

    /** @var int Revision of the library the URLs were read for. */
    private static int $revision = -1;

    /**
     * Replaces the markers of SCSS code.
     *
     * @param string $code SCSS.
     * @return string
     */
    public static function scss(string $code): string {
        if (strpos($code, 'brandable-asset') === false) {
            return $code;
        }
        $urls = self::urls();
        return preg_replace_callback(library::SCSS_MARKER, function (array $match) use ($urls): string {
            return 'url("' . ($urls[$match[2]] ?? 'data:,') . '")';
        }, $code);
    }

    /**
     * Replaces the markers of a template.
     *
     * @param string $source Template source.
     * @return string
     */
    public static function template(string $source): string {
        if (strpos($source, 'brandable-asset') === false) {
            return $source;
        }
        $urls = self::urls();
        return preg_replace_callback(library::TEMPLATE_MARKER, function (array $match) use ($urls): string {
            return isset($urls[$match[1]]) ? s($urls[$match[1]]) : '';
        }, $source);
    }

    /**
     * Checks the form of the markers of a change (contract 11.5): exact form, never inside url().
     *
     * @param string $type scss or template.
     * @param string $content Content.
     * @return bool Whether every marker has the exact form.
     */
    public static function well_formed(string $type, string $content): bool {
        if (strpos($content, 'brandable-asset') === false) {
            return true;
        }
        if ($type === 'scss') {
            return !preg_match(self::SCSS_IN_URL, $content)
                && preg_match_all(self::SCSS_ANY, $content) === preg_match_all(library::SCSS_MARKER, $content);
        }
        return preg_match_all(self::TEMPLATE_ANY, $content) === preg_match_all(library::TEMPLATE_MARKER, $content);
    }

    /**
     * Returns the slugs of the markers that designate no asset of the library.
     *
     * @param string $type scss, template or setting.
     * @param string $content Content.
     * @return string[]
     */
    public static function missing(string $type, string $content): array {
        return array_values(array_diff(library::slugs_in($type, $content), array_keys(self::urls())));
    }

    /**
     * Returns the URL of each asset, by slug (read again when the library changed).
     *
     * @return string[]
     */
    public static function urls(): array {
        $revision = library::get_revision();
        if (self::$urls === null || self::$revision !== $revision) {
            self::$urls = [];
            foreach (library::get_all() as $slug => $record) {
                self::$urls[$slug] = library::url($record)->out(false);
            }
            self::$revision = $revision;
        }
        return self::$urls;
    }

    /**
     * Forgets the URLs read (unit tests).
     */
    public static function reset(): void {
        self::$urls = null;
        self::$revision = -1;
    }
}
