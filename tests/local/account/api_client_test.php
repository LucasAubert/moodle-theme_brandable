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

#[\PHPUnit\Framework\Attributes\CoversClass(api_client::class)]
/**
 * Errors of the API client: Brandable unavailable (contract 7).
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\account\api_client
 */
final class api_client_test extends \advanced_testcase {
    #[\Override]
    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->libdir . '/filelib.php');
    }

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * The error of a request.
     *
     * @param string $body Answer of the API.
     * @return api_exception
     */
    private function error_of(string $body): api_exception {
        \curl::mock_response($body);
        try {
            (new api_client('https://api.example.com/api/v1', 'bsk_test'))->request('GET', '/account');
        } catch (api_exception $e) {
            return $e;
        }
        $this->fail('Error expected');
    }

    public function test_service_unavailable_of_brandable(): void {
        // The 503 of Brandable: its own message, shown as is (its Retry-After: see test_retry_after).
        $e = $this->error_of(json_encode(['error' => ['code' => 'service_unavailable',
            'message' => 'Brandable est momentanément indisponible. Réessayez dans quelques minutes.']]));
        $this->assertSame('service_unavailable', $e->apicode);
        $this->assertSame('Brandable est momentanément indisponible. Réessayez dans quelques minutes.', $e->displaymessage);
    }

    public function test_answer_that_is_not_json(): void {
        // A page of a proxy, a cut answer: as the 503, with the message of the plugin and the default wait.
        $e = $this->error_of('<html><body>502 Bad Gateway</body></html>');
        $this->assertSame('service_unavailable', $e->apicode);
        $this->assertSame(get_string('error:unavailable', 'theme_brandable'), $e->displaymessage);
        $this->assertSame(api_exception::DEFAULT_RETRY_AFTER, $e->retry_after());
    }

    public function test_retry_after(): void {
        $this->assertSame(120, api_client::parse_retry_after('120'));
        $this->assertSame(HOURSECS, api_client::parse_retry_after('86400'));
        $this->assertEqualsWithDelta(90, api_client::parse_retry_after(gmdate('D, d M Y H:i:s', time() + 90) . ' GMT'), 2);
        $this->assertSame(0, api_client::parse_retry_after(gmdate('D, d M Y H:i:s', time() - 90) . ' GMT'));
        $this->assertSame(0, api_client::parse_retry_after(''));
        $this->assertSame(0, api_client::parse_retry_after('soon'));
    }
}
