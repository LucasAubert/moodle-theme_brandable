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
 * Brandable licence of the theme (licence-contract.md): Ed25519 token checked locally, refreshed by the daily signal,
 * or offline licence pasted by the administrator. Never changes the rendering: only administrators are told.
 *
 * @package    theme_brandablert
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class licence {
    /** @var string This theme. */
    public const COMPONENT = 'theme_brandablert';

    /** @var string Name of the theme (claim thm). */
    public const THEME = 'brandablert';

    /** @var int Clock tolerance (seconds). */
    public const CLOCK_SKEW = 300;

    /** @var int Duration of the warning on the administration pages only, from the first problem. */
    public const ADMIN_PAGES_ONLY = 30 * DAYSECS;

    /** @var int Warning before the end of an offline licence. */
    public const OFFLINE_NOTICE = 30 * DAYSECS;

    /** @var string[] States of the licence. */
    public const STATES = ['active', 'grace', 'expired', 'revoked', 'unverified'];

    /** @var array|null Values written at the export (licence.php). */
    private static ?array $embedded = null;

    /**
     * Returns the values written at the export.
     *
     * @return array
     */
    public static function embedded(): array {
        if (self::$embedded === null) {
            $file = dirname(__DIR__, 2) . '/licence.php';
            $data = is_readable($file) ? include($file) : null;
            self::$embedded = is_array($data) ? $data : [];
        }
        return self::$embedded;
    }

    /**
     * Replaces the values of the export (unit tests), or reads them again (null).
     *
     * @param array|null $data Values, as in licence.php.
     */
    public static function reset(?array $data = null): void {
        self::$embedded = $data;
    }

    /**
     * Copies the values of the export into the configuration (installation, new version of the theme).
     */
    public static function install(): void {
        $data = self::embedded();
        set_config('brandable_token', (string) ($data['licenceToken'] ?? ''), self::COMPONENT);
        set_config('brandable_publickeys', json_encode($data['publicKeys'] ?? []), self::COMPONENT);
        unset_config('brandable_keyinvalid', self::COMPONENT);
    }

    /**
     * Returns the trusted public keys: the last ones received from the daily signal, else those of the export.
     *
     * @return array
     */
    public static function public_keys(): array {
        $keys = json_decode((string) get_config(self::COMPONENT, 'brandable_publickeys'), true);
        return is_array($keys) && $keys ? $keys : (self::embedded()['publicKeys'] ?? []);
    }

    /**
     * Checks a token (licence-contract.md, 3.1): signature, licence, theme, expiry.
     *
     * @param string $token Token (JWS compact).
     * @param array|null $keys Public keys (the trusted ones when null).
     * @return array|null Claims, null when the token is not valid.
     */
    public static function verify(string $token, ?array $keys = null): ?array {
        $claims = self::decode($token, $keys ?? self::public_keys());
        if (
            $claims === null || ($claims['sub'] ?? null) !== (self::embedded()['licenceId'] ?? '')
            || ($claims['thm'] ?? null) !== self::THEME || (int) ($claims['exp'] ?? 0) <= time() - self::CLOCK_SKEW
        ) {
            return null;
        }
        return $claims;
    }

    /**
     * Returns the claims of a token whose Ed25519 signature is valid for one of the keys (kid of the header).
     *
     * @param string $token Token (JWS compact).
     * @param array $keys Public keys (JWK: kid, x).
     * @return array|null Claims, null when the signature is not valid.
     */
    public static function decode(string $token, array $keys): ?array {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return null;
        }
        $parts = explode('.', trim($token));
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;
        $b64 = fn(string $v) => base64_decode(strtr($v, '-_', '+/') . str_repeat('=', (4 - strlen($v) % 4) % 4), true);
        $header = json_decode((string) $b64($h), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'EdDSA') {
            return null;
        }
        $key = null;
        foreach ($keys as $candidate) {
            if (is_array($candidate) && ($candidate['kid'] ?? null) === ($header['kid'] ?? false)) {
                $key = $b64((string) ($candidate['x'] ?? ''));
            }
        }
        $signature = $b64($s);
        if (
            !is_string($key) || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
        ) {
            return null;
        }
        try {
            if (!sodium_crypto_sign_verify_detached($signature, "$h.$p", $key)) {
                return null;
            }
        } catch (\SodiumException $e) {
            return null;
        }
        $claims = json_decode((string) $b64($p), true);
        return is_array($claims) ? $claims : null;
    }

    /**
     * Whether the daily signal is on (setting, on by default).
     *
     * @return bool
     */
    public static function heartbeat_enabled(): bool {
        return get_config(self::COMPONENT, 'brandable_heartbeat') !== '0';
    }

    /**
     * Returns the status of the licence (licence-contract.md, 5): the last online token while the signal is on,
     * else the offline licence, else none.
     *
     * @return array ['state' => one of STATES, 'source' => online, offline or none, 'claims' => array|null].
     */
    public static function get_status(): array {
        $claims = null;
        $source = 'none';
        if (self::heartbeat_enabled() && !get_config(self::COMPONENT, 'brandable_keyinvalid')) {
            $token = (string) get_config(self::COMPONENT, 'brandable_token');
            $claims = self::verify($token !== '' ? $token : (string) (self::embedded()['licenceToken'] ?? ''));
            $source = $claims ? 'online' : 'none';
        }
        if (!$claims) {
            $offline = self::verify((string) get_config(self::COMPONENT, 'brandable_offlinelicence'));
            if ($offline && !empty($offline['offline']) && ($offline['sts'] ?? '') === 'active') {
                $claims = $offline;
                $source = 'offline';
            }
        }
        if (!$claims) {
            return ['state' => 'unverified', 'source' => 'none', 'claims' => null];
        }
        $state = match ($claims['sts'] ?? '') {
            'active' => !empty($claims['paidUntil']) && (int) $claims['paidUntil'] < time() ? 'grace' : 'active',
            'revoked' => 'revoked',
            default => 'expired',
        };
        return ['state' => $state, 'source' => $source, 'claims' => $claims];
    }

    /**
     * Records the state: date of the first problem (cleared once active again), and a notification to the
     * administrators when the state changes.
     *
     * @return array Status (see get_status()).
     */
    public static function refresh(): array {
        $status = self::get_status();
        $problem = in_array($status['state'], ['expired', 'revoked', 'unverified'], true);
        if (!$problem) {
            unset_config('brandable_problemsince', self::COMPONENT);
        } else if (!get_config(self::COMPONENT, 'brandable_problemsince')) {
            set_config('brandable_problemsince', time(), self::COMPONENT);
        }
        if (get_config(self::COMPONENT, 'brandable_state') !== $status['state']) {
            set_config('brandable_state', $status['state'], self::COMPONENT);
            if ($status['state'] !== 'active') {
                self::notify($status);
            }
        }
        return $status;
    }

    /**
     * Sends the daily signal and stores the new token (licence-contract.md, 4).
     *
     * @param \curl|null $curl HTTP client (Moodle curl when null: proxy and security of the site).
     * @return int|null Null when done; on a failure, 0 (retries 1 h, 4 h, then 12 h later), or the seconds to wait (429).
     */
    public static function heartbeat(?\curl $curl = null): ?int {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        if (!self::heartbeat_enabled()) {
            self::refresh();
            return null;
        }
        $data = self::embedded();
        $curl = $curl ?? new \curl();
        $curl->setHeader(['Authorization: Bearer ' . ($data['licenceKey'] ?? ''), 'Content-Type: application/json',
            'Accept: application/json']);
        $raw = $curl->post(self::heartbeat_url(), json_encode([
            'siteUrl' => $CFG->wwwroot,
            'themeName' => self::THEME,
            'themeVersion' => (string) ($data['themeVersion'] ?? ''),
            'moodleVersion' => preg_replace('/^(\d+\.\d+(?:\.\d+)?).*$/', '$1', (string) $CFG->release),
        ]), ['CURLOPT_TIMEOUT' => 10, 'CURLOPT_CONNECTTIMEOUT' => 10]);
        $status = (int) ($curl->get_info()['http_code'] ?? 0);
        $answer = json_decode((string) $raw, true);
        $retry = null;
        if ($status === 200 && is_array($answer)) {
            $keys = is_array($answer['publicKeys'] ?? null) && $answer['publicKeys'] ? $answer['publicKeys'] : self::public_keys();
            if (self::verify((string) ($answer['licenceToken'] ?? ''), $keys)) {
                set_config('brandable_token', $answer['licenceToken'], self::COMPONENT);
                set_config('brandable_publickeys', json_encode($keys), self::COMPONENT);
                set_config('brandable_lastsignal', time(), self::COMPONENT);
                unset_config('brandable_keyinvalid', self::COMPONENT);
            } else {
                $retry = 0;
            }
        } else if ($status === 401) {
            // Unknown key: the licence can no longer be checked; tried again the next day.
            set_config('brandable_keyinvalid', 1, self::COMPONENT);
        } else if ($status === 429) {
            $headers = implode("\n", (array) $curl->getResponse());
            $retry = preg_match('/retry-after:\s*(\d+)/i', $headers, $m) ? max(60, (int) $m[1]) : 0;
        } else if ($status !== 400) {
            // Unavailable, server error or network: the last token is kept.
            $retry = 0;
        }
        self::refresh();
        return $retry;
    }

    /**
     * Returns the URL of the daily signal. In development (the site runs Brandable in dev mode), the address the Moodle
     * server reaches Brandable at replaces the origin.
     *
     * @return string
     */
    public static function heartbeat_url(): string {
        global $CFG;

        $url = (string) (self::embedded()['heartbeatUrl'] ?? '');
        $devapi = !empty($CFG->theme_brandable_devmode) ? trim((string) get_config('theme_brandable', 'brandable_apiurl')) : '';
        return $devapi !== '' ? rtrim($devapi, '/') . '/licences/heartbeat' : $url;
    }

    /**
     * Returns the warning shown to administrators on a page, null when there is nothing to say.
     *
     * Administration pages only during the first 30 days of a problem, then every page; never for the other users.
     *
     * @param \moodle_page $page Page.
     * @return array|null [message (HTML), type (\core\output\notification::NOTIFY_*)].
     */
    public static function warning(\moodle_page $page): ?array {
        if (!isloggedin() || isguestuser() || !has_capability('moodle/site:config', \context_system::instance())) {
            return null;
        }
        $status = self::get_status();
        $adminpage = $page->pagelayout === 'admin';
        $a = self::strings($status);
        $message = '';
        $type = \core\output\notification::NOTIFY_WARNING;
        if ($status['state'] === 'grace') {
            $message = $adminpage ? get_string('licence:banner:grace', self::COMPONENT, $a) : '';
        } else if (in_array($status['state'], ['expired', 'revoked', 'unverified'], true)) {
            $since = (int) get_config(self::COMPONENT, 'brandable_problemsince') ?: time();
            $message = $adminpage || time() - $since > self::ADMIN_PAGES_ONLY
                ? get_string('licence:banner:' . $status['state'], self::COMPONENT, $a) : '';
            $type = \core\output\notification::NOTIFY_ERROR;
        } else if ($status['source'] === 'offline' && (int) $status['claims']['exp'] - time() < self::OFFLINE_NOTICE) {
            $message = $adminpage ? get_string('licence:banner:offlineending', self::COMPONENT, $a) : '';
        }
        return $message === '' ? null : [$message, $type];
    }

    /**
     * Adds the warning to the notifications of the pages rendered with this theme (hook callback, before the headers:
     * the theme shows it with its own notifications, in the page content). Never fails.
     *
     * @param \core\hook\output\before_http_headers $hook Hook.
     */
    public static function before_http_headers(\core\hook\output\before_http_headers $hook): void {
        global $CFG, $PAGE, $SESSION;
        try {
            if (
                during_initial_install() || !empty($CFG->upgraderunning) || AJAX_SCRIPT || CLI_SCRIPT
                || $PAGE->theme->name !== self::THEME
            ) {
                return;
            }
            $warning = self::warning($PAGE);
            if ($warning === null) {
                return;
            }
            // Once: a page without notifications region (left before its footer) keeps it for the next page.
            foreach ($SESSION->notifications ?? [] as $queued) {
                if (($queued->message ?? null) === $warning[0]) {
                    return;
                }
            }
            \core\notification::add($warning[0], $warning[1]);
        } catch (\Throwable $e) {
            // The licence never breaks a page.
            debugging('theme_brandablert licence warning: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Describes the licence for the settings page.
     *
     * @return string HTML.
     */
    public static function status_html(): string {
        $status = self::get_status();
        $a = self::strings($status);
        $text = get_string('licence:state:' . $status['state'], self::COMPONENT, $a);
        if ($status['source'] !== 'none') {
            $text .= ' ' . get_string('licence:source:' . $status['source'], self::COMPONENT, $a);
        }
        if (!empty(self::embedded()['test'])) {
            $text .= ' ' . get_string('licence:test', self::COMPONENT);
        }
        return \html_writer::tag('p', $text);
    }

    /**
     * Checks an offline licence pasted by the administrator.
     *
     * @param string $token Token.
     * @return string|null Error string identifier, null when valid.
     */
    public static function check_offline(string $token): ?string {
        $claims = self::verify($token);
        if (!$claims) {
            return 'licence:offline:invalid';
        }
        if (empty($claims['offline']) || ($claims['sts'] ?? '') !== 'active') {
            return 'licence:offline:notoffline';
        }
        return null;
    }

    /**
     * Values of the licence strings (dates, links).
     *
     * @param array $status Status.
     * @return \stdClass
     */
    private static function strings(array $status): \stdClass {
        $claims = $status['claims'] ?? [];
        $origin = preg_replace('~^(https?://[^/]+).*$~', '$1', (string) (self::embedded()['heartbeatUrl'] ?? ''));
        $date = fn($time) => $time ? userdate((int) $time, get_string('strftimedate', 'langconfig')) : '-';
        return (object) [
            'graceuntil' => $date($claims['graceUntil'] ?? null),
            'exp' => $date($claims['exp'] ?? null),
            'lastsignal' => $date(get_config(self::COMPONENT, 'brandable_lastsignal')),
            'pricing' => $origin . '/pricing',
            'dashboard' => $origin . '/dashboard',
            'settings' => (new \moodle_url('/admin/settings.php', ['section' => 'themesetting' . self::THEME]))->out(false),
        ];
    }

    /**
     * Notifies the administrators of a new state.
     *
     * @param array $status Status.
     */
    private static function notify(array $status): void {
        $a = self::strings($status);
        foreach (get_admins() as $admin) {
            // Never an error: the warnings remain.
            $message = new \core\message\message();
            $message->component = self::COMPONENT;
            $message->name = 'licence';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $admin;
            $message->subject = get_string('licence:notification:subject', self::COMPONENT);
            $message->fullmessage = html_to_text(get_string('licence:banner:' . $status['state'], self::COMPONENT, $a));
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = get_string('licence:banner:' . $status['state'], self::COMPONENT, $a);
            $message->smallmessage = $message->subject;
            $message->notification = 1;
            $message->contexturl = $a->settings;
            $message->contexturlname = get_string('pluginname', self::COMPONENT);
            try {
                message_send($message);
            } catch (\Throwable $e) {
                debugging('theme_brandablert licence notification: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }
}
