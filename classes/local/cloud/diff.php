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

namespace theme_brandable\local\cloud;

use theme_brandable\local\account\api_client;
use theme_brandable\local\account\api_exception;

/**
 * Comparison of two versions of the project, or of a version with the one published here (contract 13.6, spec C14).
 * Brandable gives the complete contents before and after; the plugin computes and shows the line diff (in the
 * browser, with the component of the JavaScript review).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diff {
    /** @var string The version published on the environment of this site. */
    public const PUBLISHED = 'published';

    /** @var string[] Statuses of a change or an asset. */
    private const STATUSES = ['added', 'removed', 'modified'];

    /**
     * Reads the diff.
     *
     * @param string $from Version, or published.
     * @param string $to Version.
     * @param api_client|null $client Client of the site (tests).
     * @return array from, to (id, number; from null when nothing is published), changes, assets, findings, compatibility.
     * @throws api_exception
     */
    public static function read(string $from, string $to, ?api_client $client = null): array {
        foreach ([$from, $to] as $id) {
            if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $id)) {
                throw new \invalid_parameter_exception('version');
            }
        }
        $result = ($client ?? cloud::client())->request('GET', '/cloud/diff?' . http_build_query(['from' => $from, 'to' => $to]
            + cloud::read_parameters(), '', '&'));

        $changes = [];
        foreach ((array) ($result['changes'] ?? []) as $item) {
            if (!is_array($item) || !in_array($item['status'] ?? '', self::STATUSES, true)) {
                continue;
            }
            $changes[] = [
                'status' => (string) $item['status'],
                'type' => (string) ($item['type'] ?? ''),
                'target' => (string) ($item['target'] ?? ''),
                'scopelabel' => cloud::scope_label($item),
                'before' => self::text($item['before'] ?? null),
                'after' => self::text($item['after'] ?? null),
            ];
        }
        $assets = [];
        foreach ((array) ($result['assets'] ?? []) as $item) {
            if (!is_array($item) || !in_array($item['status'] ?? '', self::STATUSES, true)) {
                continue;
            }
            $key = (string) ($item['key'] ?? '');
            $licence = str_starts_with($key, 'license:');
            $value = is_array($item['after'] ?? null) ? $item['after'] : (is_array($item['before'] ?? null) ? $item['before'] : []);
            $assets[] = [
                'status' => (string) $item['status'],
                'key' => $licence ? substr($key, strlen('license:')) : $key,
                'kind' => $licence ? 'license' : (string) ($value['kind'] ?? ''),
                // The text of a licence is compared like a source; a file is not.
                'before' => $licence ? self::text($item['before'] ?? null) : '',
                'after' => $licence ? self::text($item['after'] ?? null) : '',
            ];
        }
        $compatibility = is_array($result['compatibility'] ?? null) ? $result['compatibility'] : [];
        $findings = cloud::findings($result['findings'] ?? []);
        $finding = \theme_brandable\local\colour_modes::compatibility_finding(array_column(
            array_filter($changes, fn($change) => $change['type'] === 'scss'),
            'after'
        ));
        if ($finding !== null) {
            $findings[] = $finding;
        }
        return [
            'from' => is_array($result['from'] ?? null)
                ? ['id' => (string) ($result['from']['id'] ?? ''), 'number' => (int) ($result['from']['number'] ?? 0)] : null,
            'to' => ['id' => (string) ($result['to']['id'] ?? $to), 'number' => (int) ($result['to']['number'] ?? 0)],
            'changes' => $changes,
            'assets' => $assets,
            'findings' => $findings,
            'compatibility' => [
                'moodleversion' => (string) ($compatibility['moodleVersion'] ?? ''),
                'indexed' => (bool) ($compatibility['indexed'] ?? true),
                'exact' => (bool) ($compatibility['exact'] ?? true),
            ],
        ];
    }

    /**
     * Returns the text compared of a content: a string as it is, a module of JavaScript by its source, a licence by its
     * text, anything else as JSON.
     *
     * @param mixed $value Content before or after (null: none).
     * @return string
     */
    private static function text($value): string {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            foreach (['content', 'text'] as $field) {
                if (isset($value[$field]) && is_string($value[$field])) {
                    return $value[$field];
                }
            }
        }
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
