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
 * Variants of templates for a page type, a page, a course or an activity: the most specific one matching the page
 * wins (activity > course > page > page type), the template of the whole site otherwise.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class variants {
    /**
     * Returns the variant of a template that applies to the current page.
     *
     * @param string $name Template name.
     * @return string|null Null when none applies.
     */
    public static function get(string $name): ?string {
        global $PAGE;

        $ajax = defined('AJAX_SCRIPT') && AJAX_SCRIPT;
        $pagekey = $ajax ? null : page_key::current();
        $best = null;
        $bestrank = -1;
        foreach (manifest::get()['variants'] ?? [] as $variant) {
            if (($variant['template'] ?? '') !== $name) {
                continue;
            }
            $scope = scope::from_array($variant['scope'] ?? null);
            if ($scope->rank() > $bestrank && $scope->matches($PAGE, $pagekey)) {
                $best = $variant;
                $bestrank = $scope->rank();
            }
        }
        return $best === null ? null : manifest::read((string) ($best['file'] ?? ''));
    }
}
