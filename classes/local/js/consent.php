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

namespace theme_brandable\local\js;

use theme_brandable\local\account\account;
use theme_brandable\local\account\api_client;
use theme_brandable\local\account\api_exception;
use theme_brandable\local\generator\factory;

/**
 * Activation of the generated JavaScript (contract 12.7, spec B1 to B4): consent to the text of Brandable, recorded by
 * Brandable then here, kill switch, and notification of the administrators when the text changes.
 *
 * The setting is never changed directly: activation and deactivation go through here.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class consent {
    /** @var string Version of the text of the demo generator (no call to Brandable). */
    public const MOCK_VERSION = 'mock-1';

    /** @var string Setting: version of the text the administrators were notified of. */
    public const NOTIFIED_CONFIG = 'brandable_jsconsentnotified';

    /** @var string Capability managing the generated JavaScript. */
    public const CAPABILITY = 'theme/brandable:managejs';

    /** @var int Entries of the history shown. */
    public const HISTORY = 20;

    /**
     * Whether the demo generator is active: the text and the consent stay on the site.
     *
     * @return bool
     */
    public static function is_mock(): bool {
        return factory::get_name() === 'mock';
    }

    /**
     * Returns the current text of the consent and its version (GET /account → js).
     *
     * @param bool $refresh Whether to read the account again.
     * @param api_client|null $client Client of the API.
     * @return array ['version' => string, 'text' => string]
     * @throws api_exception
     */
    public static function get_terms(bool $refresh = false, ?api_client $client = null): array {
        if (self::is_mock()) {
            return ['version' => self::MOCK_VERSION, 'text' => get_string('jsconsent:mocktext', 'theme_brandable')];
        }
        $js = account::get($refresh, $client)['js'] ?? null;
        $version = is_array($js) ? (string) ($js['consentVersion'] ?? '') : '';
        $text = is_array($js) ? (string) ($js['consentText'] ?? '') : '';
        if ($version === '' || $text === '') {
            throw new api_exception('invalid_response', get_string('error:apiresponse', 'theme_brandable'));
        }
        return ['version' => $version, 'text' => $text];
    }

    /**
     * Returns the version of the text accepted at the last activation (empty if none).
     *
     * @return string
     */
    public static function get_version(): string {
        return (string) get_config('theme_brandable', modules::CONSENT_CONFIG);
    }

    /**
     * Returns what POST /generations says of the JavaScript (contract 12.2): the setting, and the version accepted.
     * With an older version, Brandable answers needsJsActivation to a request needing JavaScript (B4).
     *
     * @return array ['enabled' => bool, 'version' => string]
     */
    public static function for_generation(): array {
        return ['enabled' => modules::is_enabled(), 'version' => modules::is_enabled() ? self::get_version() : ''];
    }

    /**
     * Turns the generated JavaScript on: the consent is recorded by Brandable, then here.
     *
     * @param string $version Version of the text the administrator read.
     * @param api_client|null $client Client of the API.
     * @return array|null Null once on; the current text when the version read is no longer the current one (409).
     * @throws api_exception
     */
    public static function enable(string $version, ?api_client $client = null): ?array {
        if (self::is_mock()) {
            if ($version !== self::MOCK_VERSION) {
                return self::get_terms();
            }
        } else {
            try {
                self::send(true, $version, $client);
            } catch (api_exception $e) {
                if ($e->apicode !== 'conflict') {
                    throw $e;
                }
                // The text changed since it was shown: the current one is shown again.
                account::clear_cache();
                return self::get_terms(true, $client);
            }
        }
        self::record(true, $version);
        return null;
    }

    /**
     * Turns the generated JavaScript off. Brandable is told first; the JavaScript is turned off here even when
     * Brandable cannot be reached (turning off is a safety action).
     *
     * @param api_client|null $client Client of the API.
     * @return bool Whether Brandable recorded it.
     */
    public static function disable(?api_client $client = null): bool {
        $version = self::get_version();
        $recorded = true;
        if (!self::is_mock()) {
            try {
                self::send(false, $version, $client);
            } catch (api_exception $e) {
                $recorded = false;
            }
        }
        self::record(false, $version);
        return $recorded;
    }

    /**
     * Turns the kill switch on or off (spec B2): the execution of every module stops, or starts again, at the next
     * page; nothing is unpublished and Brandable is not called.
     *
     * @param bool $suspended Whether the modules stop.
     */
    public static function set_suspended(bool $suspended): void {
        $old = modules::is_suspended() ? '1' : '0';
        $new = $suspended ? '1' : '0';
        set_config(modules::SUSPENDED_CONFIG, $new, 'theme_brandable');
        if ($old !== $new) {
            add_to_config_log(modules::SUSPENDED_CONFIG, $old, $new, 'theme_brandable');
        }
    }

    /**
     * Returns the last activations and deactivations, latest first.
     *
     * @return array List of ['enabled' => bool, 'version' => string, 'user' => string, 'time' => int].
     */
    public static function get_history(): array {
        global $DB;
        $records = $DB->get_records('theme_brandable_jsconsent', null, 'timecreated DESC, id DESC', '*', 0, self::HISTORY);
        $userids = array_filter(array_unique(array_map(fn($record) => (int) $record->userid, $records)));
        $users = $userids ? $DB->get_records_list('user', 'id', $userids, '', 'id,' . implode(
            ',',
            \core_user\fields::get_name_fields()
        )) : [];
        return array_values(array_map(fn($record) => [
            'enabled' => (bool) $record->enabled,
            'version' => (string) $record->version,
            'user' => isset($users[$record->userid]) ? fullname($users[$record->userid]) : '',
            'time' => (int) $record->timecreated,
        ], $records));
    }

    /**
     * Notifies the administrators managing the JavaScript, once per version, when the text of the consent changed
     * while the JavaScript is on (spec B4): the modules published keep running; new modules need the new version.
     *
     * @param array $account Account (GET /account).
     * @return bool Whether a notification was sent.
     */
    public static function check_version(array $account): bool {
        $version = (string) ($account['js']['consentVersion'] ?? '');
        if (
            !modules::is_enabled() || self::is_mock() || $version === '' || $version === self::get_version()
                || $version === (string) get_config('theme_brandable', self::NOTIFIED_CONFIG)
        ) {
            return false;
        }
        set_config(self::NOTIFIED_CONFIG, $version, 'theme_brandable');
        $url = new \moodle_url('/theme/brandable/jsconsent.php');
        foreach (self::get_managers() as $user) {
            $message = new \core\message\message();
            $message->component = 'theme_brandable';
            $message->name = 'jsconsent';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $user;
            $message->subject = get_string('jsconsent:newversionsubject', 'theme_brandable');
            $message->fullmessage = get_string('jsconsent:newversionmessage', 'theme_brandable', $url->out(false));
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = '';
            $message->smallmessage = get_string('jsconsent:newversionsubject', 'theme_brandable');
            $message->notification = 1;
            $message->contexturl = $url->out(false);
            $message->contexturlname = get_string('jsconsent', 'theme_brandable');
            message_send($message);
        }
        return true;
    }

    /**
     * Returns the users managing the generated JavaScript: the site administrators, and those given the capability.
     *
     * @return \stdClass[]
     */
    public static function get_managers(): array {
        $users = get_admins();
        foreach (get_users_by_capability(\context_system::instance(), self::CAPABILITY) as $user) {
            $users[$user->id] = $user;
        }
        return array_filter($users, fn($user) => empty($user->deleted) && empty($user->suspended));
    }

    /**
     * Sends an activation or a deactivation to Brandable (POST /js/consent).
     *
     * @param bool $enabled Whether the JavaScript is turned on.
     * @param string $version Version of the text.
     * @param api_client|null $client Client of the API.
     * @throws api_exception
     */
    private static function send(bool $enabled, string $version, ?api_client $client): void {
        global $USER;
        try {
            // Never a name nor an email: Brandable hashes the id (contract 12.7).
            ($client ?? new api_client())->request('POST', '/js/consent', [
                'version' => $version,
                'enabled' => $enabled,
                'adminId' => (string) (int) $USER->id,
            ]);
        } catch (api_exception $e) {
            account::handle_error($e->apicode);
            throw $e;
        }
    }

    /**
     * Records an activation or a deactivation on the site: history, setting and its log.
     *
     * @param bool $enabled Whether the JavaScript is on.
     * @param string $version Version of the text.
     */
    private static function record(bool $enabled, string $version): void {
        global $DB, $USER;
        $DB->insert_record('theme_brandable_jsconsent', (object) [
            'userid' => (int) $USER->id,
            'version' => \core_text::substr($version, 0, 50),
            'enabled' => $enabled ? 1 : 0,
            'timecreated' => time(),
        ]);
        $old = modules::is_enabled() ? '1' : '0';
        if ($enabled) {
            set_config(modules::CONSENT_CONFIG, $version, 'theme_brandable');
            // A later text is notified again.
            unset_config(self::NOTIFIED_CONFIG, 'theme_brandable');
        }
        set_config(modules::ENABLED_CONFIG, $enabled ? '1' : '0', 'theme_brandable');
        add_to_config_log(modules::ENABLED_CONFIG, $old, $enabled ? '1' : '0', 'theme_brandable');
    }
}
