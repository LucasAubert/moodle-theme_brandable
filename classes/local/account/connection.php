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

namespace theme_brandable\local\account;

use theme_brandable\local\generator\factory;

/**
 * Connection of the site to a Brandable account.
 *
 * The plugin never handles a password: the administrator signs in on the Brandable web app, which sends back a code
 * (PKCE); the code is exchanged for a site key, stored in the plugin config and never shown.
 *
 * Dev mode ($CFG->theme_brandable_devmode = true in config.php) also allows other URLs for the web app (opened by the
 * browser) and the API (called by the Moodle server), a manual key, and the demo generator.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class connection {
    /** @var string Brandable, production: the web app and the API share this origin. Not a setting (see DECISIONS). */
    public const BASE_URL = 'https://app.brandablelms.com';

    /** @var string Config holding what the connection returned (organisation, project, environment, plan), JSON. */
    public const INFO_CONFIG = 'brandable_connection';

    /** @var string Config holding the environment the site was last linked to (kept to relink it). */
    public const ENVIRONMENT_CONFIG = 'brandable_environmentid';

    /** @var string Format of a site key. */
    public const KEY_PATTERN = '/^bsk_[A-Za-z0-9_-]{43}$/';

    /** @var string Config holding the site key. */
    public const KEY_CONFIG = 'brandable_sitekey';

    /** @var int Time given to the administrator to sign in on the web app, in seconds. */
    public const FLOW_TTL = 600;

    /** @var string Session entry of the connection flow being run. */
    private const SESSION_KEY = 'theme_brandable_connect';

    /**
     * Whether dev mode is on ($CFG->theme_brandable_devmode = true in config.php).
     *
     * @return bool
     */
    public static function is_devmode(): bool {
        global $CFG;
        return !empty($CFG->theme_brandable_devmode);
    }

    /**
     * Whether the site holds a site key.
     *
     * @return bool
     */
    public static function is_connected(): bool {
        return (string) get_config('theme_brandable', self::KEY_CONFIG) !== '';
    }

    /**
     * Whether prompts can be sent: connected, or in dev mode with the demo generator or a manual key.
     *
     * @return bool
     */
    public static function is_ready(): bool {
        if (self::is_connected()) {
            return true;
        }
        return self::is_devmode() && (factory::get_name() === 'mock' || self::get_manual_key() !== '');
    }

    /**
     * Returns the key sent to the API: the manual key in dev mode when set, the site key otherwise.
     *
     * @return string Empty when there is none.
     */
    public static function get_key(): string {
        $manual = self::get_manual_key();
        return $manual !== '' ? $manual : (string) get_config('theme_brandable', self::KEY_CONFIG);
    }

    /**
     * Returns the base URL of the API, called by the Moodle server (no trailing slash): the web app URL followed by
     * /api/v1, or in dev mode the URL set when the Moodle server reaches Brandable at another address.
     *
     * @return string
     */
    public static function get_api_url(): string {
        $url = self::is_devmode() ? trim((string) get_config('theme_brandable', 'brandable_apiurl')) : '';
        return rtrim($url !== '' ? $url : self::get_web_url() . '/api/v1', '/');
    }

    /**
     * Returns the base URL of the web app, opened by the browser of the administrator (no trailing slash).
     *
     * @return string
     */
    public static function get_web_url(): string {
        $url = self::is_devmode() ? trim((string) get_config('theme_brandable', 'brandable_weburl')) : '';
        return rtrim($url !== '' ? $url : self::BASE_URL, '/');
    }

    /**
     * Stores the site key received from the API, with what it is linked to.
     *
     * @param string $key Site key.
     * @param array $info Organisation, project, environment and plan returned with the key.
     */
    public static function store_key(string $key, array $info = []): void {
        if (trim($key) === '') {
            throw new \coding_exception('Empty site key');
        }
        set_config(self::KEY_CONFIG, $key, 'theme_brandable');
        set_config(self::INFO_CONFIG, json_encode($info), 'theme_brandable');
        if (!empty($info['environment']['id'])) {
            set_config(self::ENVIRONMENT_CONFIG, (string) $info['environment']['id'], 'theme_brandable');
        }
    }

    /**
     * Returns what the site is linked to, as returned with the key.
     *
     * @return array Organisation, project, environment and plan; empty when not connected.
     */
    public static function get_info(): array {
        if (!self::is_connected()) {
            return [];
        }
        $info = json_decode((string) get_config('theme_brandable', self::INFO_CONFIG), true);
        return is_array($info) ? $info : [];
    }

    /**
     * Returns the environment the site was last linked to, to relink it after a site mismatch.
     *
     * @return string Empty when unknown.
     */
    public static function get_environment_id(): string {
        return (string) get_config('theme_brandable', self::ENVIRONMENT_CONFIG);
    }

    /**
     * Forgets the site key (disconnection, or key refused by the API). The environment is kept for a relink.
     */
    public static function forget(): void {
        unset_config(self::KEY_CONFIG, 'theme_brandable');
        unset_config(self::INFO_CONFIG, 'theme_brandable');
    }

    /**
     * Returns the language of the current user, as sent to Brandable (BCP 47: pt_br becomes pt-BR).
     *
     * @param string|null $lang Moodle language (the current one when null).
     * @return string
     */
    public static function get_lang(?string $lang = null): string {
        $parts = explode('_', $lang ?? current_language());
        $lang = strtolower(array_shift($parts));
        foreach ($parts as $part) {
            $lang .= '-' . (strlen($part) === 2 ? strtoupper($part) : $part);
        }
        return $lang;
    }

    /**
     * Returns the terms and the privacy policy of Brandable, in the language of the user (French or English).
     *
     * @return array ['termsurl' => string, 'privacyurl' => string]
     */
    public static function legal_urls(): array {
        $french = str_starts_with(current_language(), 'fr');
        $base = 'https://brandablelms.com/' . ($french ? 'fr' : 'en');
        return [
            'termsurl' => $base . ($french ? '/conditions' : '/terms'),
            'privacyurl' => $base . ($french ? '/confidentialite' : '/privacy'),
        ];
    }

    /**
     * Returns the URL of the site, as sent to the API.
     *
     * @return string
     */
    public static function get_site_url(): string {
        global $CFG;
        return $CFG->wwwroot;
    }

    /**
     * Returns the id of the site sent to the API: the site identifier, hashed (it never leaves the site as is).
     *
     * A cloned site keeps it: the URL tells them apart.
     *
     * @return string
     */
    public static function get_site_id(): string {
        return hash('sha256', get_site_identifier());
    }

    /**
     * Returns the headers authenticating the site, sent with every API request.
     *
     * @param string|null $key Key (the one of the connection when null).
     * @return string[]
     */
    public static function get_headers(?string $key = null): array {
        return [
            'Authorization: Bearer ' . ($key ?? self::get_key()),
            'X-Brandable-Site-Url: ' . self::get_site_url(),
            'X-Brandable-Site-Id: ' . self::get_site_id(),
            'Accept-Language: ' . self::get_lang(),
        ];
    }

    /**
     * Starts a connection flow: a state and a PKCE verifier are kept in the session of the administrator.
     *
     * @return array ['state' => string, 'codechallenge' => string] to send to the web app.
     */
    public static function start_flow(): array {
        global $SESSION;

        $state = self::random();
        $verifier = self::random();
        $SESSION->{self::SESSION_KEY} = (object) [
            'state' => $state,
            'verifier' => $verifier,
            'expires' => time() + self::FLOW_TTL,
        ];
        return ['state' => $state, 'codechallenge' => self::code_challenge($verifier)];
    }

    /**
     * Returns the page of the web app where the administrator signs in and approves the connection.
     *
     * @param array $flow Result of start_flow().
     * @param bool $relink Whether the site is linked again to its environment (after a site mismatch).
     * @return \moodle_url
     */
    public static function get_connect_url(array $flow, bool $relink = false): \moodle_url {
        $params = [
            'siteUrl' => self::get_site_url(),
            'siteId' => self::get_site_id(),
            'moodleVersion' => \theme_brandable\local\overrides\template_source::get_moodle_version(),
            'callback' => self::get_callback_url(),
            'state' => $flow['state'],
            'codeChallenge' => $flow['codechallenge'],
            'codeChallengeMethod' => 'S256',
        ];
        if ($relink && self::get_environment_id() !== '') {
            $params['relink'] = self::get_environment_id();
        }
        // Pages of the web app in the language of the administrator, when Brandable has it.
        $lang = substr(self::get_lang(), 0, 2);
        if (in_array($lang, ['fr', 'en'], true)) {
            $params['lang'] = $lang;
        }
        // Default billing country (and currency) of the organisation.
        $country = self::get_country();
        if ($country !== '') {
            $params['country'] = $country;
        }
        return new \moodle_url(self::get_web_url() . '/connect', $params);
    }

    /**
     * Returns the country sent to the web app (ISO 3166-1 alpha-2): the default country of the site, or else the one
     * in the profile of the administrator who connects the site.
     *
     * @return string Empty when neither is set.
     */
    public static function get_country(): string {
        global $CFG, $USER;
        foreach ([$CFG->country ?? '', $USER->country ?? ''] as $country) {
            $country = strtoupper(trim((string) $country));
            if (preg_match('/^[A-Z]{2}$/', $country)) {
                return $country;
            }
        }
        return '';
    }

    /**
     * Returns the billing country offered by default before the first purchase: the country sent to the web app, or else
     * the region of the language of the administrator (pt_br: BR).
     *
     * @return string ISO 3166-1 alpha-2, empty when unknown.
     */
    public static function get_default_billing_country(): string {
        $country = self::get_country();
        if ($country === '') {
            $parts = explode('-', self::get_lang());
            $country = preg_match('/^[A-Z]{2}$/', (string) end($parts)) && count($parts) > 1 ? end($parts) : '';
        }
        return $country;
    }

    /**
     * Returns the page the web app sends the administrator back to (same origin as the site URL).
     *
     * @return string
     */
    public static function get_callback_url(): string {
        return (new \moodle_url('/theme/brandable/connect_callback.php'))->out(false);
    }

    /**
     * Ends the connection flow when the web app sends the administrator back: checks the state once.
     *
     * @param string $state State received with the callback.
     * @return string|null PKCE verifier to send with the code, null when the state is unknown, used or expired.
     */
    public static function finish_flow(string $state): ?string {
        global $SESSION;

        $flow = $SESSION->{self::SESSION_KEY} ?? null;
        // Single use, whatever the outcome.
        unset($SESSION->{self::SESSION_KEY});
        if (!is_object($flow) || $state === '' || $flow->expires < time() || !hash_equals($flow->state, $state)) {
            return null;
        }
        return $flow->verifier;
    }

    /**
     * Returns the PKCE challenge of a verifier (S256: base64url of its SHA-256, without padding).
     *
     * @param string $verifier Verifier.
     * @return string
     */
    public static function code_challenge(string $verifier): string {
        return self::base64url(hash('sha256', $verifier, true));
    }

    /**
     * Returns a random string for a state or a verifier: 43 characters of the PKCE alphabet (256 bits).
     *
     * @return string
     */
    private static function random(): string {
        return self::base64url(random_bytes(32));
    }

    /**
     * Encodes in base64url without padding.
     *
     * @param string $bytes Bytes.
     * @return string
     */
    private static function base64url(string $bytes): string {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Returns the manual key (dev mode only).
     *
     * @return string
     */
    private static function get_manual_key(): string {
        return self::is_devmode() ? (string) get_config('theme_brandable', 'brandable_apikey') : '';
    }
}
