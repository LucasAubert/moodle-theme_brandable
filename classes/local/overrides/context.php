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

/**
 * Decides which overrides apply to the current request.
 *
 * The published revision applies by default. The preview switches the current session to the draft.
 * Validation and tests can force a revision or a candidate map for the duration of a call.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class context {
    /** @var array|null Candidate map forced for the duration of a call. */
    private static ?array $forcedmap = null;

    /** @var int|null Revision forced for the duration of a call. */
    private static ?int $forcedrevision = null;

    /**
     * Returns the revision whose overrides apply to the current request.
     *
     * @return int
     */
    public static function get_active_revision(): int {
        if (self::$forcedrevision !== null) {
            return self::$forcedrevision;
        }
        if (\theme_brandable\local\preview::is_active()) {
            return \theme_brandable\local\preview::get_revision();
        }
        return revision_manager::get_published();
    }

    /**
     * Returns the overrides that apply to the current request.
     *
     * @return array Resolved map (see {@see resolver}).
     */
    public static function get_active_map(): array {
        return self::$forcedmap ?? resolver::get_map(self::get_active_revision());
    }

    /**
     * Returns the active override of a template for the page being rendered, if any.
     *
     * Among the overrides of the template (one per scope), the most specific one matching the page wins.
     *
     * @param string $name Template name (component/name).
     * @param page_context|null $page Page (the current one when null).
     * @return string|null
     */
    public static function get_template(string $name, ?page_context $page = null): ?string {
        $entry = self::get_template_entry($name, $page);
        return $entry['content'] ?? null;
    }

    /**
     * Returns the active override entry of a template for a page (see {@see resolver}), if any.
     *
     * @param string $name Template name.
     * @param page_context|null $page Page (the current one when null).
     * @return array|null
     */
    public static function get_template_entry(string $name, ?page_context $page = null): ?array {
        $variants = resolver::get_variants(self::get_active_map(), change::TYPE_TEMPLATE, $name);
        if (!$variants) {
            return null;
        }
        return resolver::select($variants, $page ?? page_context::current());
    }

    /**
     * Runs a callable with a forced revision.
     *
     * @param int $revisionid Revision id.
     * @param callable $callback Callable.
     * @return mixed What the callable returns.
     */
    public static function with_revision(int $revisionid, callable $callback) {
        [$previousrevision, $previousmap] = [self::$forcedrevision, self::$forcedmap];
        self::$forcedrevision = $revisionid;
        self::$forcedmap = null;
        try {
            return $callback();
        } finally {
            [self::$forcedrevision, self::$forcedmap] = [$previousrevision, $previousmap];
        }
    }

    /**
     * Runs a callable with a forced candidate map (validation of a revision not stored yet).
     *
     * @param array $map Resolved map.
     * @param callable $callback Callable.
     * @return mixed What the callable returns.
     */
    public static function with_map(array $map, callable $callback) {
        $previous = self::$forcedmap;
        self::$forcedmap = $map;
        try {
            return $callback();
        } finally {
            self::$forcedmap = $previous;
        }
    }
}
