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

use theme_brandable\local\page_key;

/**
 * What the scopes are matched against: the page being rendered.
 *
 * In AJAX requests (fragments rendered by PHP), the page key of the page displaying them is unknown: page
 * scope does not apply there (pagetype, course and cm do when the request sets them).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class page_context {
    /** @var page_context|null Forced page (unit tests). */
    private static ?page_context $forced = null;

    /**
     * Constructor.
     *
     * @param string|null $pagekey Brandable page key (bp-*).
     * @param string|null $pagetype Moodle page type.
     * @param int|null $courseid Course id (not the site).
     * @param int|null $cmid Course module id.
     */
    public function __construct(
        /** @var string|null Page key. */
        public readonly ?string $pagekey = null,
        /** @var string|null Page type. */
        public readonly ?string $pagetype = null,
        /** @var int|null Course id. */
        public readonly ?int $courseid = null,
        /** @var int|null Course module id. */
        public readonly ?int $cmid = null,
    ) {
    }

    /**
     * Returns the page being rendered.
     *
     * @return self
     */
    public static function current(): self {
        global $PAGE;

        if (self::$forced !== null) {
            return self::$forced;
        }
        $ajax = defined('AJAX_SCRIPT') && AJAX_SCRIPT;
        $course = $PAGE->course ?? null;
        $cm = $PAGE->cm ?? null;
        return new self(
            $ajax ? null : page_key::current(),
            $PAGE->pagetype ?: null,
            ($course && (int) $course->id !== (int) SITEID) ? (int) $course->id : null,
            $cm ? (int) $cm->id : null
        );
    }

    /**
     * Forces the page (unit tests); null to stop forcing.
     *
     * @param page_context|null $page Page.
     */
    public static function force(?page_context $page): void {
        self::$forced = $page;
    }
}
