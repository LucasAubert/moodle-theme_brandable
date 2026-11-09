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

use theme_brandable\local\account\api_client;
use theme_brandable\local\account\api_exception;
use theme_brandable\local\generator\generation_context;

/**
 * Note of the version about to be published.
 *
 * Written by Brandable from the exchanges of the session (POST /versions/note), which the plugin sends itself; the
 * summaries of the session are proposed instead when Brandable cannot (not connected, unavailable, rate limited).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class version_note {
    /** @var int Maximum length of a line of the proposal. */
    private const MAX_LINE = 200;

    /** @var int Maximum exchanges sent (backend limit). */
    private const MAX_ENTRIES = 50;

    /**
     * Proposes the note: written by Brandable, or one line per summary of the session.
     *
     * @param api_client|null $client API client (the connection one when null).
     * @return string
     */
    public static function propose(?api_client $client = null): string {
        $entries = revision_manager::get_session_entries();
        // Brandable needs the prompts: revisions without one (scope changed, draft migrated) only give their summary.
        $exchanges = array_values(array_filter($entries, fn(array $entry) => trim($entry['prompt']) !== ''));
        if (!$exchanges) {
            return self::from_summaries($entries);
        }
        try {
            $response = ($client ?? new api_client())->request('POST', '/versions/note', [
                'sessionId' => revision_manager::get_session_id(),
                'lang' => generation_context::get_lang(),
                'entries' => array_map(fn(array $entry) => [
                    'prompt' => \core_text::substr($entry['prompt'], 0, generation_context::MAX_TEXT),
                    'summary' => \core_text::substr($entry['summary'], 0, generation_context::MAX_TEXT),
                ], array_slice($exchanges, -self::MAX_ENTRIES)),
            ]);
            $note = trim((string) ($response['note'] ?? ''));
            if ($note !== '') {
                return \core_text::substr($note, 0, revision_manager::MAX_NOTE);
            }
        } catch (api_exception $e) {
            // Not connected, unavailable (503) or rate limited (429): the summaries of the session.
            unset($e);
        }
        return self::from_summaries($entries);
    }

    /**
     * Proposes the note from the summaries: one line per summary (oldest first), within the length of a note.
     *
     * @param array $entries Exchanges of the session (prompt, summary).
     * @return string
     */
    public static function from_summaries(array $entries): string {
        $lines = [];
        $length = 0;
        foreach ($entries as $entry) {
            $line = trim(preg_replace('/\s+/u', ' ', $entry['summary'] !== '' ? $entry['summary'] : $entry['prompt']));
            if ($line === '') {
                continue;
            }
            if (\core_text::strlen($line) > self::MAX_LINE) {
                $line = \core_text::substr($line, 0, self::MAX_LINE - 1) . '…';
            }
            if ($length + \core_text::strlen($line) + 1 > revision_manager::MAX_NOTE) {
                break;
            }
            $lines[] = $line;
            $length += \core_text::strlen($line) + 1;
        }
        return implode("\n", $lines);
    }
}
