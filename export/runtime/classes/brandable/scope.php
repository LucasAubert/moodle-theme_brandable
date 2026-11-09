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
 * Scope of a template variant or of a SCSS chunk: site, page type, page (bp-* key), course or activity. Courses and
 * activities are found on this site by the key chosen at the export (shortname or idnumber, or the id).
 *
 * @package    theme_brandablert
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scope {
    /** @var int[] Rank of each level: the most specific variant matching a page wins. */
    private const RANKS = ['site' => 0, 'pagetype' => 1, 'page' => 2, 'course' => 3, 'cm' => 4];

    /** @var array Course and activity ids, by scope key (request cache). */
    private static array $ids = [];

    /**
     * Constructor.
     *
     * @param string $level site, pagetype, page, course or cm.
     * @param string $value Page type, page key, or key of the course / activity (empty for the site).
     * @param string $by Course: id, shortname or idnumber. Activity: id or idnumber.
     */
    public function __construct(
        /** @var string Level. */
        public readonly string $level,
        /** @var string Value. */
        public readonly string $value = '',
        /** @var string Key of the course or activity. */
        public readonly string $by = 'id',
    ) {
    }

    /**
     * Builds a scope from the manifest.
     *
     * @param array|null $data ['level', 'value', 'by'].
     * @return self
     */
    public static function from_array(?array $data): self {
        $level = (string) ($data['level'] ?? 'site');
        return new self(
            isset(self::RANKS[$level]) ? $level : 'site',
            (string) ($data['value'] ?? ''),
            (string) ($data['by'] ?? 'id')
        );
    }

    /**
     * Rank of the scope (specificity).
     *
     * @return int
     */
    public function rank(): int {
        return self::RANKS[$this->level];
    }

    /**
     * Whether the scope covers the page.
     *
     * @param \moodle_page $page Page.
     * @param string|null $pagekey Key of the page (bp-*), null when unknown (AJAX).
     * @return bool
     */
    public function matches(\moodle_page $page, ?string $pagekey): bool {
        return match ($this->level) {
            'site' => true,
            'pagetype' => $page->pagetype === $this->value,
            'page' => $pagekey !== null && $pagekey === $this->value,
            'course' => (int) $page->course->id !== (int) SITEID && in_array((int) $page->course->id, $this->get_ids(), true),
            'cm' => $page->cm !== null && in_array((int) $page->cm->id, $this->get_ids(), true),
            default => false,
        };
    }

    /**
     * Body selector wrapping a scoped SCSS chunk, null for the site. The :not(#...) only raise the specificity, so
     * that the levels keep their order whatever the order of the chunks.
     *
     * @return string|null Empty string when the course or activity is not on this site (chunk left out).
     */
    public function selector(): ?string {
        $wrap = fn(string $prefix, string $suffix) => implode(', ', array_map(
            fn(int $id) => "body.$prefix-$id$suffix",
            $this->get_ids()
        ));
        return match ($this->level) {
            'pagetype' => 'body#page-' . $this->value,
            'page' => 'body.' . $this->value . ':not(#bp-s1)',
            'course' => $wrap('course', ':not(#bp-s1):not(#bp-s2)'),
            'cm' => $wrap('cmid', ':not(#bp-s1):not(#bp-s2):not(#bp-s3)'),
            default => null,
        };
    }

    /**
     * Returns the ids of the course or activities of the scope on this site.
     *
     * @return int[]
     */
    private function get_ids(): array {
        global $DB;

        $key = $this->level . '|' . $this->by . '|' . $this->value;
        if (!isset(self::$ids[$key])) {
            $table = $this->level === 'course' ? 'course' : 'course_modules';
            $field = in_array($this->by, ['shortname', 'idnumber'], true) && !($table === 'course_modules'
                && $this->by === 'shortname') ? $this->by : 'id';
            $ids = $this->value === '' ? [] : $DB->get_fieldset_select($table, 'id', "$field = ?", [$this->value]);
            self::$ids[$key] = array_map('intval', $ids);
        }
        return self::$ids[$key];
    }
}
