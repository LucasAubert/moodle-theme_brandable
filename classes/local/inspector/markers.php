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

use theme_brandable\local\preview;

/**
 * Template markers: in preview, every template rendering is wrapped in HTML comments
 * (<!-- bt:start core/user_menu --> ... <!-- bt:end core/user_menu -->) so that the element picker of the
 * editor can tell which templates produced an element.
 *
 * Only for HTML page requests in a preview session, when the administrator did not turn them off in the
 * editor and $CFG->debugtemplateinfo does not already wrap templates. Only HTML is wrapped (never an empty
 * rendering, text, or a fragment without tags), to limit the side effects on emails, attribute values and
 * "is it empty?" checks.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class markers {
    /** @var string Start marker prefix. */
    public const START = '<!-- bt:start ';
    /** @var string End marker prefix. */
    public const END = '<!-- bt:end ';

    /**
     * Whether the templates rendered by this request are marked.
     *
     * @return bool
     */
    public static function is_active(): bool {
        global $CFG;
        return inspector::is_active() && preview::markers_enabled() && empty($CFG->debugtemplateinfo);
    }

    /**
     * Wraps a rendered template (top-level rendering): only non empty HTML.
     *
     * @param string $name Template name.
     * @param string $html Rendered HTML.
     * @return string
     */
    public static function wrap_output(string $name, string $html): string {
        $trimmed = trim($html);
        if ($trimmed === '' || $trimmed[0] !== '<' || substr($trimmed, -1) !== '>' || self::is_wrapped($trimmed)) {
            return $html;
        }
        return self::wrap($name, $html);
    }

    /**
     * Wraps a template source (partials, whose rendering cannot be intercepted): only sources with HTML tags.
     *
     * @param string $name Template name.
     * @param string $source Mustache source.
     * @return string
     */
    public static function wrap_source(string $name, string $source): string {
        // Mustache comments ({{! ... }}) are not output: look for tags in the rest only.
        $markup = preg_replace('/\{\{!.*?\}\}/s', '', $source);
        if (!preg_match('/<[a-zA-Z]/', $markup) || self::is_wrapped(trim($markup))) {
            return $source;
        }
        return self::wrap($name, $source);
    }

    /**
     * Wraps content in markers.
     *
     * @param string $name Template name.
     * @param string $content Content.
     * @return string
     */
    private static function wrap(string $name, string $content): string {
        $name = str_replace('--', '-', $name);
        return self::START . $name . ' -->' . $content . self::END . $name . ' -->';
    }

    /**
     * Whether content is already wrapped.
     *
     * @param string $content Content.
     * @return bool
     */
    private static function is_wrapped(string $content): bool {
        return strpos($content, self::START) === 0;
    }
}
