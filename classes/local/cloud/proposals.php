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
 * Proposals of a version to another environment of the project (contract 13.7, spec C15, C16): proposing, the
 * proposal waiting for this environment, dismissing it, and the notification of the administrators of this site.
 *
 * Brandable sends no email: the site of the environment proposed to reads the proposal in the state of the project and
 * notifies its administrators itself, once per proposal.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class proposals {
    /** @var string Proposals already notified (JSON list of ids, the latest ones). */
    public const NOTIFIED_CONFIG = 'brandable_cloudnotified';

    /** @var int Proposals remembered as notified. */
    private const NOTIFIED_KEPT = 50;

    /** @var int Longest message of a proposal (characters). */
    public const MESSAGE_LENGTH = 500;

    /** @var string Form of an id of the cloud. */
    private const ID_PATTERN = '/^[A-Za-z0-9-]{1,64}$/';

    /**
     * Returns the proposal waiting for the environment of this site, as the plugin shows it.
     *
     * @param array $state State of the project (GET /cloud/state).
     * @return array|null id, versionid, number, note, from, author, message, createdat; null when none.
     */
    public static function pending(array $state): ?array {
        $here = (string) ($state['environment']['id'] ?? '');
        foreach ((array) ($state['proposals'] ?? []) as $proposal) {
            if (
                !is_array($proposal) || ($proposal['status'] ?? 'pending') !== 'pending'
                    || !preg_match(self::ID_PATTERN, (string) ($proposal['id'] ?? ''))
                    || !preg_match(self::ID_PATTERN, (string) ($proposal['version']['id'] ?? ''))
                    || ($here !== '' && isset($proposal['to']['id']) && (string) $proposal['to']['id'] !== $here)
            ) {
                continue;
            }
            return [
                'id' => (string) $proposal['id'],
                'versionid' => (string) $proposal['version']['id'],
                'number' => (int) ($proposal['version']['number'] ?? 0),
                'note' => (string) ($proposal['version']['note'] ?? ''),
                'from' => (string) ($proposal['from']['name'] ?? ''),
                'author' => (string) ($proposal['author']['name'] ?? ''),
                'message' => (string) ($proposal['message'] ?? ''),
                'createdat' => (int) strtotime((string) ($proposal['createdAt'] ?? '')) ?: 0,
            ];
        }
        return null;
    }

    /**
     * Proposes a version of the project to another environment (a new proposal replaces the one waiting there).
     *
     * @param string $versionid Version.
     * @param string $environmentid Environment proposed to.
     * @param string $message Message, 500 characters at most.
     * @param api_client|null $client Client of the site (tests).
     * @return array The proposal (contract 13.2).
     * @throws api_exception
     */
    public static function propose(string $versionid, string $environmentid, string $message, ?api_client $client = null): array {
        global $USER;
        foreach ([$versionid, $environmentid] as $id) {
            if (!preg_match(self::ID_PATTERN, $id)) {
                throw new \invalid_parameter_exception('id');
            }
        }
        $message = \core_text::substr(trim($message), 0, self::MESSAGE_LENGTH);
        $proposal = ($client ?? cloud::client())->request('POST', '/cloud/proposals', array_filter([
            'versionId' => $versionid,
            'environmentId' => $environmentid,
            'message' => $message,
            'author' => ['hash' => cloud::author_hash((int) $USER->id), 'name' => \core_text::substr(fullname($USER), 0, 100)],
        ], fn($value) => $value !== ''));
        cloud::clear_state();
        return $proposal;
    }

    /**
     * Dismisses the proposal waiting for this environment.
     *
     * @param string $proposalid Proposal.
     * @param api_client|null $client Client of the site (tests).
     * @throws api_exception
     */
    public static function dismiss(string $proposalid, ?api_client $client = null): void {
        if (!preg_match(self::ID_PATTERN, $proposalid)) {
            throw new \invalid_parameter_exception('proposalid');
        }
        ($client ?? cloud::client())->request('POST', '/cloud/proposals/' . $proposalid . '/dismiss');
        cloud::clear_state();
    }

    /**
     * Notifies the administrators of this site of a new proposal, once per proposal (spec C16).
     *
     * @param array $state State of the project (GET /cloud/state).
     * @return int Users notified.
     */
    public static function notify(array $state): int {
        $proposal = self::pending($state);
        $notified = json_decode((string) get_config('theme_brandable', self::NOTIFIED_CONFIG), true);
        $notified = is_array($notified) ? $notified : [];
        if ($proposal === null || in_array($proposal['id'], $notified, true)) {
            return 0;
        }
        $notified[] = $proposal['id'];
        set_config(self::NOTIFIED_CONFIG, json_encode(array_slice($notified, -self::NOTIFIED_KEPT)), 'theme_brandable');

        $url = new \moodle_url('/theme/brandable/editor.php');
        $a = (object) ['number' => $proposal['number'], 'from' => $proposal['from'], 'author' => $proposal['author'],
            'message' => $proposal['message'], 'url' => $url->out(false)];
        $subject = get_string('cloud:proposal:subject', 'theme_brandable', $a);
        $text = get_string('cloud:proposal:body', 'theme_brandable', $a)
            . ($proposal['message'] !== '' ? "\n\n" . get_string('cloud:proposal:bodymessage', 'theme_brandable', $a) : '')
            . "\n\n" . get_string('cloud:proposal:bodylink', 'theme_brandable', $a);
        $count = 0;
        foreach (self::get_recipients() as $user) {
            $message = new \core\message\message();
            $message->component = 'theme_brandable';
            $message->name = 'cloudproposal';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $user;
            $message->subject = $subject;
            $message->fullmessage = $text;
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = '';
            $message->smallmessage = $subject;
            $message->notification = 1;
            $message->contexturl = $url->out(false);
            $message->contexturlname = get_string('editor', 'theme_brandable');
            if (message_send($message)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Returns the users notified: the administrators of the site and those who can edit the design.
     *
     * @return \stdClass[]
     */
    private static function get_recipients(): array {
        $users = get_admins();
        foreach (get_users_by_capability(\context_system::instance(), 'theme/brandable:edit') as $user) {
            $users[$user->id] = $user;
        }
        return array_filter($users, fn($user) => empty($user->deleted) && empty($user->suspended));
    }
}
