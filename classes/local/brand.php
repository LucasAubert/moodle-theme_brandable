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

namespace theme_brandable\local;

use moodle_url;

/**
 * Brand of Brandable on the pages of the plugin (spec docs/SPEC-brand-editor.md): styles and symbol.
 *
 * The styles (styles/editor.css) are loaded on the pages of the plugin only, never compiled in the CSS of the site.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class brand {
    /** @var string Styles of the pages of the plugin, relative to the directory of the plugin. */
    public const STYLES = '/styles/editor.css';

    /**
     * Adds the styles of the plugin to the page (before its header). Versioned by the date of the file, for the cache of
     * the browser.
     */
    public static function require_styles(): void {
        global $PAGE;
        $file = dirname(__DIR__, 2) . self::STYLES;
        $version = file_exists($file) ? filemtime($file) : 0;
        $PAGE->requires->css(new moodle_url('/theme/brandable' . self::STYLES, ['v' => $version]));
    }

    /**
     * Symbol of Brandable (decorative SVG, the name goes next to it in text).
     *
     * @return string HTML.
     */
    public static function mark(): string {
        global $OUTPUT;
        return trim($OUTPUT->render_from_template('theme_brandable/brand_mark', []));
    }
}
