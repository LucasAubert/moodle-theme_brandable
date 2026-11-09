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

use cache;

/**
 * Resolves the effective overrides of a revision by applying the chain of revisions from the root.
 *
 * A resolved map has the shape [type => [key => entry]], the key being the target, suffixed with the scope
 * when it is not the site ({@see self::entry_key()}): several overrides of a target coexist with different
 * scopes. Each entry is an array with the keys target, scopelevel, scopevalue, content, contenthash,
 * sourcehash, needsreview and revisionid (js entries: compiled, report, signature and jsanalyzer too). Within a
 * type, entries keep the order in which their key was first created: replacing an override keeps its position,
 * removing then re-creating it moves it to the end. SCSS chunks are concatenated in that order.
 *
 * Revisions are immutable, so the resolved map of a revision is cached (MUC) forever under its id.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resolver {
    /**
     * Returns an empty map (revision 0).
     *
     * @return array
     */
    public static function empty_map(): array {
        return array_fill_keys(change::TYPES, []);
    }

    /**
     * Key of an override in a resolved map: the target, plus the scope when it is not the site.
     *
     * @param string $target Target.
     * @param scope $scope Scope.
     * @return string
     */
    public static function entry_key(string $target, scope $scope): string {
        return $target . $scope->key();
    }

    /**
     * Returns the scope of a map entry.
     *
     * @param array $entry Map entry.
     * @return scope
     */
    public static function entry_scope(array $entry): scope {
        return scope::from_columns($entry['scopelevel'] ?? null, $entry['scopevalue'] ?? null);
    }

    /**
     * Returns the entries (all scopes) of a target.
     *
     * @param array $map Resolved map.
     * @param string $type Change type.
     * @param string $target Target.
     * @return array Entries, in map order.
     */
    public static function get_variants(array $map, string $type, string $target): array {
        return array_values(array_filter($map[$type] ?? [], fn(array $entry) => $entry['target'] === $target));
    }

    /**
     * Picks the entry of a target that applies to a page: the most specific matching scope
     * (cm > course > page > pagetype > site).
     *
     * @param array $variants Entries of a target.
     * @param page_context $page Page.
     * @return array|null
     */
    public static function select(array $variants, page_context $page): ?array {
        $best = null;
        $bestrank = -1;
        foreach ($variants as $entry) {
            $scope = self::entry_scope($entry);
            if ($scope->matches($page) && $scope->rank() > $bestrank) {
                $best = $entry;
                $bestrank = $scope->rank();
            }
        }
        return $best;
    }

    /**
     * Returns the resolved overrides of a revision.
     *
     * @param int $revisionid Revision id, 0 for the empty root.
     * @return array
     */
    public static function get_map(int $revisionid): array {
        global $DB;

        if ($revisionid <= 0) {
            return self::empty_map();
        }
        $cache = cache::make('theme_brandable', 'resolved');
        $map = $cache->get($revisionid);
        if ($map !== false) {
            return $map;
        }

        // Walk up to the closest ancestor already resolved (or the root), then apply forward.
        $pending = [];
        $id = $revisionid;
        $map = false;
        while ($id > 0) {
            if ($pending && ($map = $cache->get($id)) !== false) {
                break;
            }
            $pending[] = $id;
            $id = (int) $DB->get_field('theme_brandable_revision', 'parentrevisionid', ['id' => $id], MUST_EXIST);
        }
        if ($map === false) {
            $map = self::empty_map();
        }
        foreach (array_reverse($pending) as $id) {
            $records = $DB->get_records('theme_brandable_change', ['revisionid' => $id], 'sortorder ASC, id ASC');
            $map = self::apply($map, array_values($records), $id);
            $cache->set($id, $map);
        }
        return $map;
    }

    /**
     * Applies changes to a map.
     *
     * @param array $map Resolved map.
     * @param array $changes Change records (or objects) with type, target, content, deleted, contenthash,
     *                       sourcehash, needsreview, scopelevel and scopevalue properties.
     * @param int $revisionid Revision bringing the changes (0 for a candidate revision).
     * @return array
     */
    public static function apply(array $map, array $changes, int $revisionid): array {
        foreach ($changes as $change) {
            $type = $change->type;
            $scope = scope::from_columns($change->scopelevel ?? null, $change->scopevalue ?? null);
            $key = self::entry_key($change->target, $scope);
            if (!empty($change->deleted)) {
                unset($map[$type][$key]);
                continue;
            }
            $map[$type][$key] = [
                'target' => $change->target,
                'scopelevel' => $scope->level,
                'scopevalue' => $scope->value,
                'content' => (string) $change->content,
                'contenthash' => $change->contenthash ?? hash('sha256', (string) $change->content),
                'sourcehash' => $change->sourcehash ?? null,
                'needsreview' => !empty($change->needsreview),
                'revisionid' => $revisionid,
            ];
            if ($type === change::TYPE_JS) {
                // Generated JavaScript (contract 12.3): what the page runs, and what the review needs.
                $map[$type][$key] += [
                    'compiled' => $change->compiled ?? null,
                    'report' => $change->report ?? null,
                    'signature' => $change->signature ?? null,
                    'jsanalyzer' => $change->jsanalyzer ?? null,
                ];
            }
        }
        return $map;
    }

    /**
     * Whether two maps render the same: same entries, same contents, in the same order.
     *
     * @param array $a Resolved map.
     * @param array $b Resolved map.
     * @return bool
     */
    public static function same_content(array $a, array $b): bool {
        foreach (change::TYPES as $type) {
            $hashes = fn(array $map) => array_map(fn(array $entry) => $entry['contenthash'], $map[$type] ?? []);
            if ($hashes($a) !== $hashes($b)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Returns the ids of a revision and its ancestors, from the revision up to the root (excluded).
     *
     * @param int $revisionid Revision id.
     * @return int[]
     */
    public static function get_ancestry(int $revisionid): array {
        global $DB;

        $ids = [];
        while ($revisionid > 0) {
            $ids[] = $revisionid;
            $revisionid = (int) $DB->get_field('theme_brandable_revision', 'parentrevisionid', ['id' => $revisionid], MUST_EXIST);
        }
        return $ids;
    }
}
