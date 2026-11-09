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
 * Scope of an override: where it applies.
 *
 * Levels, from the least to the most specific: site, pagetype, page (Brandable page key, bp-*), course, cm.
 * When several overrides of the same template match a page, the most specific one wins. SCSS chunks are
 * wrapped by the plugin in a body selector whose CSS specificity follows the same order; pre/* chunks
 * (variables) are always site-wide.
 *
 * Contract: scope = { level: 'site'|'pagetype'|'page'|'course'|'cm', value: string|null }, no scope = site.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class scope {
    /** @var string Whole site. */
    public const SITE = 'site';
    /** @var string Moodle page type ($PAGE->pagetype, body id page-<pagetype>). */
    public const PAGETYPE = 'pagetype';
    /** @var string Brandable page key (body class bp-*). */
    public const PAGE = 'page';
    /** @var string A course (body class course-<id>). */
    public const COURSE = 'course';
    /** @var string An activity (body class cmid-<id>). */
    public const CM = 'cm';

    /** @var string[] Levels, from the least to the most specific. */
    public const LEVELS = [self::SITE, self::PAGETYPE, self::PAGE, self::COURSE, self::CM];

    /** @var string Pattern of a Moodle page type. */
    public const PAGETYPE_PATTERN = '~^[a-z0-9][a-z0-9_-]*$~';

    /**
     * Constructor.
     *
     * @param string $level One of {@see self::LEVELS}.
     * @param string|null $value Page type, page key, course id or cm id; null for the site.
     */
    public function __construct(
        /** @var string Level. */
        public readonly string $level = self::SITE,
        /** @var string|null Value. */
        public readonly ?string $value = null,
    ) {
    }

    /**
     * Builds a scope from the contract format (null or absent: site).
     *
     * @param mixed $data ['level' => string, 'value' => string|int|null] or null.
     * @return self
     */
    public static function from_array($data): self {
        if (!is_array($data)) {
            return new self();
        }
        $level = strtolower(trim((string) ($data['level'] ?? self::SITE)));
        $value = $data['value'] ?? null;
        $value = ($value === null || $value === '') ? null : trim((string) $value);
        return new self($level === '' ? self::SITE : $level, $level === self::SITE ? null : $value);
    }

    /**
     * Builds a scope from stored columns.
     *
     * @param string|null $level Level column.
     * @param string|null $value Value column.
     * @return self
     */
    public static function from_columns(?string $level, ?string $value): self {
        return new self($level ?: self::SITE, ($value === null || $value === '') ? null : $value);
    }

    /**
     * Exports the scope in the contract format.
     *
     * @return array
     */
    public function to_array(): array {
        return ['level' => $this->level, 'value' => $this->value];
    }

    /**
     * Whether the scope is the whole site.
     *
     * @return bool
     */
    public function is_site(): bool {
        return $this->level === self::SITE;
    }

    /**
     * Rank of the level (higher is more specific).
     *
     * @return int
     */
    public function rank(): int {
        return (int) array_search($this->level, self::LEVELS, true);
    }

    /**
     * Suffix identifying the scope in override keys ('' for the site).
     *
     * @return string
     */
    public function key(): string {
        return $this->is_site() ? '' : '@' . $this->level . ':' . $this->value;
    }

    /**
     * Checks the scope.
     *
     * @return string|null Error message.
     */
    public function validate(): ?string {
        global $DB;

        if (!in_array($this->level, self::LEVELS, true)) {
            return get_string('error:scopelevel', 'theme_brandable', s($this->level));
        }
        $value = (string) $this->value;
        $valid = match ($this->level) {
            self::SITE => true,
            self::PAGETYPE => (bool) preg_match(self::PAGETYPE_PATTERN, $value),
            self::PAGE => (bool) preg_match(page_key::PATTERN, $value),
            self::COURSE => ctype_digit($value) && (int) $value !== (int) SITEID
                && $DB->record_exists('course', ['id' => (int) $value]),
            self::CM => ctype_digit($value) && $DB->record_exists('course_modules', ['id' => (int) $value]),
        };
        return $valid ? null : get_string('error:scopevalue', 'theme_brandable', s($this->level . ': ' . $value));
    }

    /**
     * Whether the scope covers a page.
     *
     * @param page_context $page Page.
     * @return bool
     */
    public function matches(page_context $page): bool {
        return match ($this->level) {
            self::SITE => true,
            self::PAGETYPE => $page->pagetype !== null && $page->pagetype === $this->value,
            self::PAGE => $page->pagekey !== null && $page->pagekey === $this->value,
            self::COURSE => $page->courseid !== null && (string) $page->courseid === $this->value,
            self::CM => $page->cmid !== null && (string) $page->cmid === $this->value,
            default => false,
        };
    }

    /**
     * Human readable label (editor).
     *
     * @return string
     */
    public function label(): string {
        global $DB;

        $value = (string) $this->value;
        switch ($this->level) {
            case self::COURSE:
                $value = $DB->get_field('course', 'shortname', ['id' => (int) $value]) ?: "#{$value}";
                break;
            case self::CM:
                $cm = get_coursemodule_from_id('', (int) $value, 0, false, IGNORE_MISSING);
                $value = $cm ? format_string($cm->name) : "#{$value}";
                break;
        }
        return get_string('scope_' . $this->level, 'theme_brandable', s($value));
    }

    /**
     * Body selector wrapping a scoped SCSS chunk, with a CSS specificity following the levels order:
     * pagetype (1,0,1) < page (1,1,1) < course (2,1,1) < cm (3,1,1). The :not(#bp-sN) parts never match
     * an element and only raise the specificity.
     *
     * @return string|null Null for the site (no wrapper).
     */
    public function selector(): ?string {
        return match ($this->level) {
            self::PAGETYPE => 'body#page-' . $this->value,
            self::PAGE => 'body.' . $this->value . ':not(#bp-s1)',
            self::COURSE => 'body.course-' . $this->value . ':not(#bp-s1):not(#bp-s2)',
            self::CM => 'body.cmid-' . $this->value . ':not(#bp-s1):not(#bp-s2):not(#bp-s3)',
            default => null,
        };
    }
}
