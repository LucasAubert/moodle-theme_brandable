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

/**
 * Brandable account of the site: connection (code exchange), disconnection, account summary and purchases.
 *
 * Contract: backend docs/plugin-contract.md (v1).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class account {
    /** @var int Time the account summary is kept in the session, in seconds. */
    public const CACHE_TTL = 60;

    /** @var int Credits a generation reserves when it starts (contract): below, sending a prompt fails. */
    public const RESERVATION = 5;

    /** @var string Session entry of the account summary. */
    private const SESSION_KEY = 'theme_brandable_account';

    /**
     * Ends the connection when the web app sends the administrator back (callback).
     *
     * The state is checked first, whatever the outcome; the code is exchanged by the Moodle server.
     *
     * @param string $state State received.
     * @param string $code Code received, empty on failure.
     * @param string $error Error received (access_denied, invalid_request, server_error), empty on success.
     * @param string $description Description of the error, for the logs only.
     * @param api_client|null $client Client of the API.
     * @return array ['status' => connected|cancelled|expired|failed, 'message' => string shown to the administrator].
     */
    public static function finish_connection(
        string $state,
        string $code,
        string $error = '',
        string $description = '',
        ?api_client $client = null
    ): array {
        $verifier = connection::finish_flow($state);
        if ($verifier === null) {
            return ['status' => 'expired', 'message' => get_string('connect:expired', 'theme_brandable')];
        }
        if ($error !== '' || $code === '') {
            if ($description !== '') {
                debugging('Brandable connection failed: ' . $error . ': ' . $description, DEBUG_DEVELOPER);
            }
            return $error === 'access_denied'
                ? ['status' => 'cancelled', 'message' => get_string('connect:cancelled', 'theme_brandable')]
                : ['status' => 'failed', 'message' => get_string('connect:failed', 'theme_brandable')];
        }
        try {
            $info = self::exchange_code($code, $verifier, $client);
        } catch (api_exception $e) {
            $message = $e->apicode === 'invalid_grant' ? get_string('connect:invalidgrant', 'theme_brandable')
                : $e->displaymessage;
            return ['status' => 'failed', 'message' => $message];
        }
        return ['status' => 'connected', 'message' => get_string('connect:done', 'theme_brandable', (object) [
            'organization' => (string) ($info['organization']['name'] ?? ''),
            'project' => (string) ($info['project']['name'] ?? ''),
            'environment' => (string) ($info['environment']['name'] ?? ''),
        ])];
    }

    /**
     * Exchanges the code received by the callback for a site key, and stores it.
     *
     * @param string $code Code.
     * @param string $verifier PKCE verifier of the flow.
     * @param api_client|null $client Client of the API.
     * @return array Organisation, project, environment and plan the site is linked to.
     * @throws api_exception
     */
    public static function exchange_code(string $code, string $verifier, ?api_client $client = null): array {
        $response = ($client ?? new api_client())->request('POST', '/connect/token', [
            'code' => $code,
            'codeVerifier' => $verifier,
            'siteUrl' => connection::get_site_url(),
            'siteId' => connection::get_site_id(),
        ], false);
        $key = (string) ($response['siteKey'] ?? '');
        if (!preg_match(connection::KEY_PATTERN, $key)) {
            throw new api_exception('invalid_response', get_string('error:apiresponse', 'theme_brandable'));
        }
        $info = [];
        foreach (['site', 'organization', 'project', 'environment', 'plan'] as $name) {
            if (isset($response[$name]) && is_array($response[$name])) {
                $info[$name] = $response[$name];
            }
        }
        connection::store_key($key, $info);
        self::clear_cache();
        return $info;
    }

    /**
     * Disconnects the site: revokes its key, then forgets it.
     *
     * Already revoked (401): forgotten. Site mismatch (409): probably a copy holding the key of the original, which
     * must stay valid; forgotten locally only.
     *
     * @param bool $force Whether to forget the key even when the API cannot revoke it (unreachable).
     * @param api_client|null $client Client of the API.
     * @throws api_exception When the key could not be revoked (the key is kept unless $force).
     */
    public static function disconnect(bool $force = false, ?api_client $client = null): void {
        try {
            ($client ?? new api_client(null, (string) get_config('theme_brandable', connection::KEY_CONFIG)))
                ->request('POST', '/connect/revoke');
        } catch (api_exception $e) {
            if (!$force && !in_array($e->apicode, ['site_not_connected', 'site_mismatch'], true)) {
                throw $e;
            }
        }
        connection::forget();
        self::clear_cache();
    }

    /**
     * Returns the account summary (GET /account), kept for a minute in the session.
     *
     * @param bool $refresh Whether to read it again from the API.
     * @param api_client|null $client Client of the API.
     * @return array
     * @throws api_exception
     */
    public static function get(bool $refresh = false, ?api_client $client = null): array {
        global $SESSION;

        $cached = $SESSION->{self::SESSION_KEY} ?? null;
        if (!$refresh && is_array($cached) && $cached['at'] > time() - self::CACHE_TTL) {
            return $cached['data'];
        }
        try {
            $data = ($client ?? new api_client())->request('GET', '/account');
        } catch (api_exception $e) {
            self::handle_error($e->apicode);
            throw $e;
        }
        $SESSION->{self::SESSION_KEY} = ['at' => time(), 'data' => $data];
        // A new text of the consent to the generated JavaScript is notified to the administrators (spec B4).
        \theme_brandable\local\js\consent::check_version($data);
        return $data;
    }

    /**
     * Starts a purchase (plan or credit pack), or a change of plan for an organisation already subscribed (contract 6):
     * the page to open at once, in a new tab (a payment page of Stripe, or its Customer Portal opened on the
     * confirmation of the change, valid a few minutes).
     *
     * @param string $kind plan or credits.
     * @param string $item Offer id (offers.plans[].id or offers.creditPacks[].id).
     * @param string $country Billing country (ISO 3166-1 alpha-2), required while the organisation has none.
     * @param api_client|null $client Client of the API.
     * @return array ['url' => string, 'flow' => checkout or subscription_update]
     * @throws api_exception already_subscribed for the price already subscribed, country_required...
     */
    public static function checkout(string $kind, string $item, string $country = '', ?api_client $client = null): array {
        $body = [
            'kind' => $kind,
            'item' => $item,
            'returnUrl' => (new \moodle_url('/theme/brandable/connect.php'))->out(false),
        ];
        if ($country !== '') {
            // Billing country chosen by the administrator: stored on the organisation by Brandable.
            $body['country'] = $country;
        }
        try {
            $response = ($client ?? new api_client())->request('POST', '/billing/checkout', $body);
        } catch (api_exception $e) {
            self::handle_error($e->apicode);
            $reread = ['country_not_supported', 'country_required', 'country_locked', 'already_subscribed'];
            if (in_array($e->apicode, $reread, true)) {
                // Billing country not supported, missing or fixed at the first payment, or the plan already
                // subscribed: the account is read again.
                self::clear_cache();
            }
            throw $e;
        }
        // Plan and credits change a few seconds after the payment (webhook): read again.
        self::clear_cache();
        // Stripe, in https; in dev mode, the local web app may answer in http.
        $url = (string) ($response['url'] ?? '');
        if (!preg_match(connection::is_devmode() ? '~^https?://~i' : '~^https://~i', $url)) {
            throw new api_exception('invalid_response', get_string('error:apiresponse', 'theme_brandable'));
        }
        return ['url' => $url, 'flow' => ($response['flow'] ?? '') === 'subscription_update' ? 'subscription_update' : 'checkout'];
    }

    /**
     * Acts on an error of the API: a key refused (401) is forgotten, the site must be connected again.
     *
     * The manual key of dev mode is never forgotten.
     *
     * @param string $code Error code.
     */
    public static function handle_error(string $code): void {
        if ($code !== 'site_not_connected') {
            return;
        }
        self::clear_cache();
        if (connection::is_connected() && connection::get_key() === get_config('theme_brandable', connection::KEY_CONFIG)) {
            connection::forget();
        }
    }

    /**
     * Forgets the account summary kept in the session.
     */
    public static function clear_cache(): void {
        global $SESSION;
        unset($SESSION->{self::SESSION_KEY});
    }
}
