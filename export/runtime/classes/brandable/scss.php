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
 * SCSS of the exported version: the chunks of a section (pre or post), in order, scoped chunks wrapped in their
 * body selector (inside, & is the body itself).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scss {
    /**
     * @var string The brandable-dark mixin of the dark mode of Moodle 5.3 (inert before, or with the modes off). Kept
     * identical to the one of Brandable (theme_brandable\local\colour_modes::MIXIN).
     */
    public const DARK_MIXIN = <<<'SCSS'
        @mixin brandable-dark {
            $parent: &;
            @if $parent and length($parent) > 0 {
                @at-root #{selector-nest('[data-bs-theme="dark"]', $parent)} { @content; }
            } @else {
                [data-bs-theme="dark"] { @content; }
            }
        }

        SCSS;

    /**
     * Returns the SCSS code of a section.
     *
     * @param string $section 'pre' or 'post'.
     * @return string
     */
    public static function get_code(string $section): string {
        $code = '';
        foreach (manifest::get()['scss'] ?? [] as $chunk) {
            if (($chunk['section'] ?? '') !== $section) {
                continue;
            }
            $selector = scope::from_array($chunk['scope'] ?? null)->selector();
            if ($selector === '') {
                // Course or activity missing on this site.
                continue;
            }
            // Markers of the images and fonts of the version: URLs of the theme.
            $content = assets::scss(manifest::read((string) ($chunk['file'] ?? '')));
            $code .= $selector === null ? $content . "\n" : "$selector {\n$content\n}\n";
        }
        return $code;
    }
}
