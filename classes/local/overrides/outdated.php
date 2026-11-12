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
 * Template overrides made from another version of their original than the one of the site now: the parent theme of
 * Brandable or Moodle changed since. They still apply; the editor lists them to be reviewed.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class outdated {
    /**
     * The overridden templates of a map whose original changed since the override was made.
     *
     * @param array $map Resolved map ({@see resolver::get_map()}).
     * @return string[] Template names, sorted, each once (whatever its scopes).
     */
    public static function templates(array $map): array {
        $names = [];
        foreach ($map[change::TYPE_TEMPLATE] ?? [] as $entry) {
            $made = $entry['sourcehash'] ?? null;
            // A template of Brandable's own (no original when it was made): nothing to compare with.
            if ($made === null || isset($names[$entry['target']])) {
                continue;
            }
            // A template the site no longer has counts as changed.
            if (template_source::get_original_hash($entry['target']) !== $made) {
                $names[$entry['target']] = true;
            }
        }
        $names = array_keys($names);
        sort($names);
        return $names;
    }
}
