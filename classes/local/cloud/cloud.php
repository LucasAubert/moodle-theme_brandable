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

use theme_brandable\local\account\account;
use theme_brandable\local\account\api_client;
use theme_brandable\local\account\api_exception;
use theme_brandable\local\account\connection;
use theme_brandable\local\generator\generation_context;
use theme_brandable\local\overrides\scope;
use theme_brandable\local\overrides\template_source;
use theme_brandable\local\parent_theme;

/**
 * Cloud of the Pro offer (contract 13, spec C1, C2): rights of the site, state of the project, client of /cloud.
 *
 * Moodle stays the only source of what runs: the cloud keeps the history of the project; a publication in Moodle never
 * depends on it.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cloud {
    /** @var string Reading and writing (Pro, Multi-sites). */
    public const FULL = 'full';

    /** @var string Reading only, 30 days after the loss of the offer. */
    public const READ = 'read';

    /** @var string No cloud. */
    public const NONE = 'none';

    /** @var int Seconds the state of the project is kept in the session. */
    public const STATE_TTL = 30;

    /** @var string Session entry of the state. */
    private const SESSION_KEY = 'theme_brandable_cloudstate';

    /**
     * Returns the rights of the site: cloud.access of /account (none when not connected, or the account cannot be
     * read).
     *
     * @param array|null $account Account (GET /account), read when null.
     * @return string full, read or none.
     */
    public static function get_access(?array $account = null): string {
        if ($account === null) {
            if (!connection::is_connected()) {
                return self::NONE;
            }
            try {
                $account = account::get();
            } catch (api_exception $e) {
                return self::NONE;
            }
        }
        $access = (string) ($account['cloud']['access'] ?? self::NONE);
        return in_array($access, [self::FULL, self::READ], true) ? $access : self::NONE;
    }

    /**
     * Returns a client of the API with the key of the site (the manual key of dev mode is refused by /cloud).
     *
     * @return api_client
     */
    public static function client(): api_client {
        return new api_client(null, (string) get_config('theme_brandable', connection::KEY_CONFIG));
    }

    /**
     * Returns the state of the project: rights, version this site runs, other environments, proposal waiting
     * (GET /cloud/state, kept a few seconds in the session).
     *
     * @param bool $refresh Whether to read it again.
     * @param api_client|null $client Client of the API.
     * @return array
     * @throws api_exception
     */
    public static function get_state(bool $refresh = false, ?api_client $client = null): array {
        global $SESSION;
        $cached = $SESSION->{self::SESSION_KEY} ?? null;
        if (!$refresh && is_array($cached) && $cached['at'] > time() - self::STATE_TTL) {
            return $cached['data'];
        }
        try {
            $state = ($client ?? self::client())->request('GET', '/cloud/state');
        } catch (api_exception $e) {
            account::handle_error($e->apicode);
            throw $e;
        }
        $SESSION->{self::SESSION_KEY} = ['at' => time(), 'data' => $state];
        return $state;
    }

    /**
     * Forgets the state kept in the session (after a publication, a sending).
     */
    public static function clear_state(): void {
        global $SESSION;
        unset($SESSION->{self::SESSION_KEY});
    }

    /**
     * Returns the stable identifier of an administrator sent to the cloud (never the id itself): sha256 of the
     * identifier of the site and of the id.
     *
     * @param int $userid User.
     * @return string sha256 hex.
     */
    public static function author_hash(int $userid): string {
        global $CFG;
        return hash('sha256', $CFG->siteidentifier . ':' . $userid);
    }

    /**
     * Returns the parameters of a reading (complete version, diff): the Moodle release, the parent theme and the
     * language of this site, for the findings of the reading (contract 13.5, 13.6).
     *
     * @return array
     */
    public static function read_parameters(): array {
        return [
            'moodleVersion' => template_source::get_moodle_version(),
            'parentTheme' => parent_theme::get_name(),
            'lang' => generation_context::get_lang(),
        ];
    }

    /**
     * Returns the findings of Brandable as the plugin shows them (contract 13.6): the severity given, otherwise
     * js_rejected blocking and the others warnings.
     *
     * @param mixed $findings findings of a version or a diff.
     * @return array code, severity, target, message.
     */
    public static function findings($findings): array {
        $result = [];
        foreach (is_array($findings) ? $findings : [] as $finding) {
            if (is_array($finding)) {
                $code = (string) ($finding['code'] ?? '');
                $severity = (string) ($finding['severity'] ?? '');
                $result[] = [
                    'code' => $code,
                    'severity' => in_array($severity, ['warning', 'blocking'], true) ? $severity
                        : ($code === 'js_rejected' ? 'blocking' : 'warning'),
                    'target' => (string) ($finding['target'] ?? ''),
                    'message' => (string) ($finding['message'] ?? ''),
                ];
            }
        }
        return $result;
    }

    /**
     * Returns how the scope of a change of the cloud is named (course and activity by their scopeRef, as they are named
     * on the environment they come from).
     *
     * @param array $item Change of a version or a diff.
     * @return string
     */
    public static function scope_label(array $item): string {
        $level = (string) ($item['scope']['level'] ?? scope::SITE);
        if ($level === scope::SITE) {
            return get_string('scope_site', 'theme_brandable');
        }
        $ref = is_array($item['scopeRef'] ?? null) ? $item['scopeRef'] : [];
        $name = (string) ($ref['shortname'] ?? $ref['idnumber'] ?? ($item['scope']['value'] ?? ''));
        $known = [scope::COURSE, scope::CM, scope::PAGE, scope::PAGETYPE];
        if (!in_array($level, $known, true)) {
            return trim($level . ' ' . $name);
        }
        return get_string('scope_' . $level, 'theme_brandable', $name);
    }
}
