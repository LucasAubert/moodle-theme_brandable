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

use theme_brandable\local\plugin_info;
use theme_brandable\local\plugin_updates;

/**
 * Client of the Brandable API, for the Moodle server only (the browser never calls the API).
 *
 * Authenticated requests carry the site key and the site headers (see connection). Requests go through Moodle's curl
 * class, so the site proxy and cURL security settings apply.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_client {
    /** @var int Request timeout in seconds. */
    private const TIMEOUT = 30;

    /** @var string[] Routes never refused with 426 plugin_upgrade_required (contract 15.5). */
    public const UPGRADE_EXEMPT = ['GET /ping', 'GET /account', 'POST /connect/revoke', 'GET /plugin/latest'];

    /** @var string API base URL, without trailing slash. */
    private string $baseurl;

    /** @var string Key. */
    private string $key;

    /**
     * Constructor.
     *
     * @param string|null $baseurl API base URL (the one of the connection when null).
     * @param string|null $key Key (the one of the connection when null).
     */
    public function __construct(?string $baseurl = null, ?string $key = null) {
        $this->baseurl = rtrim($baseurl ?? connection::get_api_url(), '/');
        $this->key = $key ?? connection::get_key();
    }

    /**
     * Sends a request to the API and decodes the JSON answer.
     *
     * @param string $method GET, POST or PUT.
     * @param string $path Path from the API base URL (/generations, /account...).
     * @param array|null $body JSON body (POST).
     * @param bool $authenticated Whether the site key and headers are sent (all calls but the code exchange).
     * @param string|null $raw Raw body instead of JSON (PUT of a file, contract 13.3).
     * @param string $contenttype Type of the raw body.
     * @return array Decoded answer, empty for an answer without body (204).
     * @throws api_exception
     */
    public function request(
        string $method,
        string $path,
        ?array $body = null,
        bool $authenticated = true,
        ?string $raw = null,
        string $contenttype = 'application/json'
    ): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        if ($this->baseurl === '') {
            throw new api_exception('not_configured', get_string('error:apinotconfigured', 'theme_brandable'));
        }
        if ($authenticated && $this->key === '') {
            throw new api_exception('site_not_connected', get_string('error:notconnected', 'theme_brandable'));
        }

        $curl = new \curl();
        $headers = ['Accept: application/json', 'Content-Type: ' . ($raw !== null ? $contenttype : 'application/json')];
        $curl->setHeader($this->headers($authenticated, $headers));
        $options = ['CURLOPT_TIMEOUT' => self::TIMEOUT, 'CURLOPT_CONNECTTIMEOUT' => 10];
        $url = $this->baseurl . $path;
        if ($raw !== null) {
            // A file as is (PUT): never JSON nor multipart.
            $raw = $curl->post($url, $raw, $options + ['CURLOPT_CUSTOMREQUEST' => $method]);
        } else if ($method === 'POST') {
            $raw = $curl->post($url, json_encode($body ?? new \stdClass()), $options);
        } else {
            $raw = $curl->get($url, [], $options);
        }

        if ($curl->error === get_string('curlsecurityurlblocked', 'admin')) {
            // Blocked by the cURL security settings of the site (private address, port not allowed).
            $parts = parse_url($url);
            $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '')
                . (isset($parts['port']) ? ':' . $parts['port'] : '');
            throw new api_exception('url_blocked', get_string('error:urlblocked', 'theme_brandable', $origin));
        }
        if ($curl->get_errno() || $curl->error) {
            throw self::unavailable($curl->error ?: (string) $curl->get_errno(), 0);
        }
        $status = (int) ($curl->get_info()['http_code'] ?? 0);
        // A route that can refuse an old plugin went through: the version required is no longer true (spec U2).
        $checked = !in_array($method . ' ' . strtok($path, '?'), self::UPGRADE_EXEMPT, true);
        if (trim((string) $raw) === '' && $status < 400) {
            if ($checked) {
                plugin_updates::clear_required();
            }
            return [];
        }
        $data = json_decode((string) $raw, true);

        // API errors are { error: { code, message, url? } } alone (a failed generation also has status and events).
        $apierror = is_array($data) && array_keys($data) === ['error'];
        if ($status >= 400 || !is_array($data) || $apierror) {
            throw self::error($curl, $data, $status);
        }
        if ($checked) {
            plugin_updates::clear_required();
        }
        return $data;
    }

    /**
     * Downloads a file of the API (fonts of the catalogue and their licence, contract 11.6), with the site key.
     *
     * @param string $path Path after the base URL (/fonts/...).
     * @param int $maxbytes Largest file accepted.
     * @return string Content.
     * @throws api_exception Error of the API (404 not_found, 429 rate_limited, 503 service_unavailable...), network.
     */
    public function download(string $path, int $maxbytes): string {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        if ($this->baseurl === '') {
            throw new api_exception('not_configured', get_string('error:apinotconfigured', 'theme_brandable'));
        }
        if ($this->key === '') {
            throw new api_exception('site_not_connected', get_string('error:notconnected', 'theme_brandable'));
        }
        $curl = new \curl();
        $curl->setHeader($this->headers(true, ['Accept: font/woff2, text/plain, application/json']));
        $raw = $curl->get($this->baseurl . $path, [], ['CURLOPT_TIMEOUT' => self::TIMEOUT, 'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_MAXFILESIZE' => $maxbytes]);
        if ($curl->get_errno() || $curl->error) {
            throw self::unavailable($curl->error ?: (string) $curl->get_errno(), 0);
        }
        $status = (int) ($curl->get_info()['http_code'] ?? 0);
        if ($status >= 400) {
            throw self::error($curl, json_decode((string) $raw, true), $status);
        }
        if (strlen((string) $raw) > $maxbytes) {
            throw new api_exception('http_413', get_string('asset:error:filesize', 'theme_brandable', display_size($maxbytes)));
        }
        return (string) $raw;
    }

    /**
     * The error of an answer: the error of the API ({ error: { code, message, url? } }, with the Retry-After of the
     * answer), Brandable unavailable when the answer is not JSON (page of a proxy, cut), or the HTTP status.
     *
     * @param \curl $curl Request done.
     * @param mixed $data Decoded body.
     * @param int $status HTTP status.
     * @return api_exception
     */
    private static function error(\curl $curl, $data, int $status): api_exception {
        $retryafter = self::retry_after($curl);
        $error = is_array($data) ? ($data['error'] ?? null) : null;
        if (is_array($error) && isset($error['code'], $error['message'])) {
            $link = (string) ($error['url'] ?? '');
            $details = array_diff_key($error, array_flip(['code', 'message', 'url']));
            if ($retryafter > 0) {
                $details[api_exception::RETRY_AFTER] = $retryafter;
            }
            $exception = new api_exception(
                (string) $error['code'],
                (string) $error['message'],
                preg_match('~^https?://~i', $link) ? $link : '',
                $status,
                $details
            );
            if ($exception->apicode === plugin_updates::UPGRADE_REQUIRED) {
                plugin_updates::record_required($exception);
            }
            return $exception;
        }
        if (!is_array($data)) {
            // Not JSON (HTML page, cut answer): as the 503 of Brandable, with a message of the plugin (contract 7).
            return self::unavailable('HTTP ' . $status, $status, $retryafter);
        }
        return new api_exception('http_' . $status, get_string('error:apistatus', 'theme_brandable', $status), '', $status);
    }

    /**
     * Brandable cannot be reached (network, timeout, answer that is not JSON): handled as its 503 service_unavailable
     * (contract 7, "Brandable indisponible"), with a message of the plugin; the cause is kept in the details.
     *
     * @param string $cause Error of cURL or status.
     * @param int $status HTTP status, 0 when nothing was received.
     * @param int $retryafter Retry-After of the answer, 0 if none.
     * @return api_exception
     */
    private static function unavailable(string $cause, int $status, int $retryafter = 0): api_exception {
        return new api_exception(
            'service_unavailable',
            get_string('error:unavailable', 'theme_brandable'),
            '',
            $status,
            [api_exception::RETRY_AFTER => $retryafter ?: api_exception::DEFAULT_RETRY_AFTER, 'cause' => $cause]
        );
    }

    /**
     * The Retry-After of an answer, in seconds (seconds or HTTP date), between 0 (none) and an hour.
     *
     * @param \curl $curl Request done.
     * @return int
     */
    private static function retry_after(\curl $curl): int {
        foreach ((array) $curl->getResponse() as $name => $value) {
            if (strcasecmp(trim((string) $name), 'Retry-After') !== 0) {
                continue;
            }
            return self::parse_retry_after((string) (is_array($value) ? end($value) : $value));
        }
        return 0;
    }

    /**
     * Reads a Retry-After header: seconds or HTTP date, between 0 (none, invalid, past) and an hour.
     *
     * @param string $value Value of the header.
     * @return int Seconds.
     */
    public static function parse_retry_after(string $value): int {
        $value = trim($value);
        if (ctype_digit($value)) {
            $seconds = (int) $value;
        } else {
            $time = $value === '' ? false : strtotime($value);
            $seconds = $time === false ? 0 : $time - time();
        }
        return max(0, min(HOURSECS, $seconds));
    }

    /**
     * Returns the headers of a request: the site (authenticated requests), the plugin (all requests, contract 15.1),
     * then those of the request.
     *
     * @param bool $authenticated Whether the site key and headers are sent.
     * @param string[] $headers Headers of the request.
     * @return string[]
     */
    protected function headers(bool $authenticated, array $headers): array {
        // Language of the messages (and of the release notes): fr_ca -> fr-ca.
        $language = 'Accept-Language: ' . str_replace('_', '-', current_language());
        $connection = $authenticated ? connection::get_headers($this->key) : [];
        return array_merge($connection, plugin_info::headers(), [$language], $headers);
    }
}
