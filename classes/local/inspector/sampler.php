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

/**
 * Turns a template context into a small sample safe to show to the generator.
 *
 * Truncated (depth, list length, string length, total size) and stripped of obviously sensitive data:
 * secrets are removed, personal fields are redacted.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sampler {
    /** @var int Maximum nesting depth kept. */
    public const MAX_DEPTH = 4;
    /** @var int Maximum items kept per list or object. */
    public const MAX_ITEMS = 5;
    /** @var int Maximum length of strings. */
    public const MAX_STRING = 200;
    /** @var int Maximum size of a sample, in bytes of JSON. */
    public const MAX_BYTES = 4096;

    /** @var string Keys whose values are removed (secrets). */
    private const SECRET_KEYS = '/(sesskey|token|password|passwd|secret|cookie|session|auth|apikey|api_key|private)/i';

    /** @var string Keys whose values are redacted (personal data). */
    private const PERSONAL_KEYS = '/(email|phone|mobile|address|^ip$|ipaddress|lastip|username|idnumber|firstname|lastname|'
        . 'fullname|middlename|alternatename|birth|city|country|description|signature)/i';

    /** @var string Replacement of redacted values. */
    public const REDACTED = '[redacted]';

    /**
     * Returns a sample of a context.
     *
     * @param mixed $context Template context (array / stdClass of simple types).
     * @return mixed
     */
    public static function sample($context) {
        $sample = self::walk($context, 0);
        // Keep shrinking until the sample fits the size budget.
        $depth = self::MAX_DEPTH;
        while (strlen((string) json_encode($sample)) > self::MAX_BYTES && $depth > 0) {
            $depth--;
            $sample = self::walk($context, 0, $depth);
        }
        return $sample;
    }

    /**
     * Recursively samples a value.
     *
     * @param mixed $value Value.
     * @param int $depth Current depth.
     * @param int $maxdepth Maximum depth.
     * @return mixed
     */
    private static function walk($value, int $depth, int $maxdepth = self::MAX_DEPTH) {
        if (is_object($value)) {
            $value = $value instanceof \JsonSerializable ? $value->jsonSerialize() : get_object_vars($value);
            if (!is_array($value)) {
                return self::walk($value, $depth, $maxdepth);
            }
        }
        if (is_array($value)) {
            if ($depth >= $maxdepth) {
                return '…';
            }
            $islist = array_is_list($value);
            $result = [];
            foreach ($value as $key => $item) {
                if (count($result) >= self::MAX_ITEMS) {
                    break;
                }
                if (!$islist && preg_match(self::SECRET_KEYS, (string) $key)) {
                    continue;
                }
                if (!$islist && preg_match(self::PERSONAL_KEYS, (string) $key) && !is_array($item) && !is_object($item)) {
                    $result[$key] = self::REDACTED;
                    continue;
                }
                $result[$key] = self::walk($item, $depth + 1, $maxdepth);
            }
            return $result;
        }
        if (is_string($value)) {
            $value = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', self::REDACTED, $value);
            return \core_text::strlen($value) > self::MAX_STRING ? \core_text::substr($value, 0, self::MAX_STRING) . '…' : $value;
        }
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }
        return null;
    }
}
