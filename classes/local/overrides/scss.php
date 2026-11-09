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

namespace theme_brandable\local\overrides;

use theme_brandable\local\assets\markers;

/**
 * Builds the SCSS injected in the theme from the active SCSS chunks.
 *
 * pre/* chunks go to the pre-SCSS (after the parent's pre-SCSS, before the main SCSS: they can set
 * Bootstrap variables; always site-wide); post/* chunks go to the extra SCSS, wrapped in the body selector
 * of their scope ({@see scope::selector()}). Each chunk is delimited by markers so that compilation errors
 * can be attributed to it.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scss {
    /** @var string Marker opening a chunk (followed by its key: target, plus the scope when not the site). */
    public const MARKER_START = '/* brandable-chunk: ';
    /** @var string Marker closing the Brandable SCSS of a section. */
    public const MARKER_END = '/* brandable-chunk-end */';
    /** @var string Class of the sentinel rules (validation only, see $sentinels). */
    public const SENTINEL = 'brandable-chunk-sentinel';

    /**
     * @var bool Whether each chunk is preceded by a sentinel rule carrying its key. Only while compiling for the
     * validation: the compiler moves comments (the markers) into neighbouring rules, never rules.
     */
    public static bool $sentinels = false;

    /**
     * Returns the SCSS code of a section (pre or post) from the active overrides.
     *
     * @param string $section 'pre' or 'post'.
     * @return string
     */
    public static function get_code(string $section): string {
        return self::build(context::get_active_map(), $section);
    }

    /**
     * Returns the SCSS code of a section from a map.
     *
     * @param array $map Resolved map.
     * @param string $section 'pre' or 'post'.
     * @return string
     */
    public static function build(array $map, string $section): string {
        $code = '';
        foreach ($map[change::TYPE_SCSS] ?? [] as $entry) {
            if (strpos($entry['target'], $section . '/') !== 0) {
                continue;
            }
            // Scoped chunks are wrapped in their body selector, opened on the marker line so that compilation
            // error lines stay relative to the chunk. Inside, & is the body itself.
            $selector = resolver::entry_scope($entry)->selector();
            $key = resolver::entry_key($entry['target'], resolver::entry_scope($entry));
            // On the marker line: the lines of the chunk stay the same for error attribution.
            $code .= self::sentinel($key) . self::MARKER_START . $key . ' */' . ($selector !== null ? " {$selector} {" : '') . "\n"
                . markers::scss($entry['content']) . "\n" . ($selector !== null ? "}\n" : '');
        }
        return $code === '' ? '' : $code . self::sentinel('-end') . self::MARKER_END . "\n";
    }

    /**
     * Returns the sentinel rule of a chunk key, when sentinels are on.
     *
     * @param string $key Chunk key ('-end' after the last chunk).
     * @return string
     */
    private static function sentinel(string $key): string {
        return self::$sentinels ? '.' . self::SENTINEL . '[data-key="' . $key . '"] { --brandable-chunk: 1; } ' : '';
    }
}
