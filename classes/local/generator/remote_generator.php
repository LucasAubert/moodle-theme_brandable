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

namespace theme_brandable\local\generator;

use theme_brandable\local\account\api_client;
use theme_brandable\local\account\api_exception;

/**
 * Generator backed by the Brandable API (contract v2).
 *
 * - POST /generations -> 202 { generationId }
 * - GET /generations/:id -> { status, events, result?, error? }
 * - POST /generations/:id/repair { errors } -> 202 (409 repair_limit_reached / conflict, 410 context_expired)
 *
 * Authenticated with the site key ("Authorization: Bearer <key>"), along with the URL and the hashed id of the site
 * (see connection). Only the plugin talks to the API: the browser never does. Requests go through Moodle's curl
 * class, so the site proxy and cURL security settings apply.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class remote_generator implements generator_interface {
    /** @var api_client Client of the API. */
    private api_client $client;

    /**
     * Constructor.
     *
     * @param string|null $baseurl API base URL (the one of the connection when null).
     * @param string|null $apikey API key (the one of the connection when null).
     */
    public function __construct(?string $baseurl = null, ?string $apikey = null) {
        $this->client = new api_client($baseurl, $apikey);
    }

    #[\Override]
    public function start(string $prompt, generation_context $context): string {
        $response = $this->request('POST', '/generations', $context->to_request($prompt));
        if (empty($response['generationId']) || !is_string($response['generationId'])) {
            throw new generator_exception('invalid_response', get_string('error:apiresponse', 'theme_brandable'));
        }
        return $response['generationId'];
    }

    #[\Override]
    public function poll(string $id): generation_status {
        return generation_status::from_array($this->request('GET', '/generations/' . rawurlencode($id)));
    }

    #[\Override]
    public function repair(string $id, array $errors): void {
        $errors = array_slice(array_map(fn(array $error) => [
            // The API requires a non-empty target: global errors are reported on the theme.
            'target' => \core_text::substr($error['target'] !== '' ? $error['target'] : 'theme', 0, 200),
            'message' => \core_text::substr($error['message'], 0, 4000),
        ] + array_intersect_key($error, ['kind' => true, 'selector' => true, 'matches' => true]), $errors), 0, 100);
        $this->request('POST', '/generations/' . rawurlencode($id) . '/repair', ['errors' => $errors]);
    }

    /**
     * Sends a request to the API and decodes the JSON answer.
     *
     * @param string $method GET or POST.
     * @param string $path Path from the base URL.
     * @param array|null $body JSON body.
     * @return array
     * @throws generator_exception With the API error code and message (shown as is), or a connection error.
     */
    private function request(string $method, string $path, ?array $body = null): array {
        try {
            return $this->client->request($method, $path, $body);
        } catch (api_exception $e) {
            // Brandable itself unavailable (contract 7): the generation is asked again after the Retry-After.
            $unavailable = in_array($e->apicode, ['service_unavailable', 'rate_limited'], true)
                || in_array($e->httpstatus, [502, 503, 504], true);
            throw new generator_exception($e->apicode, $e->displaymessage, $e->url, $e->retry_after(), $unavailable);
        }
    }
}
