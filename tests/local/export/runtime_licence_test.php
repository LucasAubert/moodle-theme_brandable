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

namespace theme_brandable\local\export;

use theme_brandablert\brandable\licence as runtime_licence;

#[\PHPUnit\Framework\Attributes\CoversNothing]
/**
 * Tests for the licence of the exported themes (static runtime, licence-contract.md), run on the runtime sources:
 * tokens signed with a key pair of the test, and the test vector of the contract.
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class runtime_licence_test extends \advanced_testcase {
    /** @var string Secret key of the test. */
    private string $secret;

    /** @var array Public keys of the test. */
    private array $keys;

    /** @var string Licence of the test. */
    private const LICENCE = '11111111-2222-4333-8444-555555555555';

    #[\Override]
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        $dir = exporter::runtime_dir() . '/classes/brandable';
        foreach (['manifest', 'licence'] as $class) {
            require_once("$dir/$class.php");
        }
    }

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->keys = [['kty' => 'OKP', 'crv' => 'Ed25519', 'alg' => 'EdDSA', 'use' => 'sig', 'kid' => 'k1',
            'x' => $this->b64(sodium_crypto_sign_publickey($pair))]];
        runtime_licence::reset(['licenceId' => self::LICENCE, 'licenceKey' => 'blk_secret', 'licenceToken' => $this->token(),
            'publicKeys' => $this->keys, 'heartbeatUrl' => 'https://app.brandablelms.com/api/v1/licences/heartbeat',
            'themeVersion' => '2026102800']);
        runtime_licence::install();
    }

    #[\Override]
    protected function tearDown(): void {
        runtime_licence::reset();
        parent::tearDown();
    }

    /**
     * Base64url without padding.
     *
     * @param string $value Value.
     * @return string
     */
    private function b64(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * Signs a token with the key of the test.
     *
     * @param array $claims Claims replacing the defaults.
     * @param string $kid Key id.
     * @return string
     */
    private function token(array $claims = [], string $kid = 'k1'): string {
        $claims += ['iss' => 'https://app.brandablelms.com', 'sub' => self::LICENCE, 'thm' => 'brandablert', 'plan' => 'site_local',
            'sts' => 'active', 'paidUntil' => time() + 20 * DAYSECS, 'graceUntil' => time() + 50 * DAYSECS, 'iat' => time(),
            'exp' => time() + 45 * DAYSECS];
        $header = ['alg' => 'EdDSA', 'typ' => 'JWT', 'kid' => $kid];
        $input = $this->b64(json_encode($header)) . '.' . $this->b64(json_encode($claims));
        return $input . '.' . $this->b64(sodium_crypto_sign_detached($input, $this->secret));
    }

    public function test_verification(): void {
        $this->assertSame(self::LICENCE, runtime_licence::verify($this->token())['sub']);
        // A byte changed in the claims or the signature, an unknown key, another licence or theme, expired.
        $token = $this->token();
        [$h, $p, $s] = explode('.', $token);
        $this->assertNull(runtime_licence::verify("$h." . $this->b64(json_encode(['sub' => self::LICENCE])) . ".$s"));
        $this->assertNull(runtime_licence::verify("$h.$p." . strrev($s)));
        $this->assertNull(runtime_licence::verify($this->token([], 'unknown')));
        $this->assertNull(runtime_licence::verify($this->token(['sub' => 'other'])));
        $this->assertNull(runtime_licence::verify($this->token(['thm' => 'acme'])));
        $this->assertNull(runtime_licence::verify($this->token(['exp' => time() - 301])));
        $this->assertNotNull(runtime_licence::verify($this->token(['exp' => time() - 100])));

        // Test vector of the contract (theme acme).
        $claims = runtime_licence::decode(licence::TEST_TOKEN, licence::TEST_KEYS);
        $this->assertSame(
            ['3f9a2c4e-1b2d-4e5f-8a9b-0c1d2e3f4a5b', 'acme', 1801353600],
            [$claims['sub'], $claims['thm'], $claims['exp']]
        );
        $this->assertNull(runtime_licence::decode(substr(licence::TEST_TOKEN, 0, -2) . 'AA', licence::TEST_KEYS));
    }

    public function test_states(): void {
        $this->assertSame(['active', 'online'], array_slice(array_values(runtime_licence::get_status()), 0, 2));
        set_config('brandable_token', $this->token(['paidUntil' => time() - DAYSECS]), 'theme_brandablert');
        $this->assertSame('grace', runtime_licence::get_status()['state']);
        set_config('brandable_token', $this->token(['sts' => 'revoked']), 'theme_brandablert');
        $this->assertSame('revoked', runtime_licence::get_status()['state']);
        set_config('brandable_token', $this->token(['sts' => 'expired']), 'theme_brandablert');
        $this->assertSame('expired', runtime_licence::get_status()['state']);

        // Signal off: the offline licence, else nothing.
        set_config('brandable_heartbeat', 0, 'theme_brandablert');
        $this->assertSame('unverified', runtime_licence::get_status()['state']);
        $offline = $this->token(['offline' => true, 'exp' => time() + 10 * DAYSECS]);
        $this->assertNull(runtime_licence::check_offline($offline));
        $this->assertSame('licence:offline:notoffline', runtime_licence::check_offline($this->token()));
        $this->assertSame('licence:offline:invalid', runtime_licence::check_offline('nope'));
        set_config('brandable_offlinelicence', $offline, 'theme_brandablert');
        $this->assertSame(['active', 'offline'], array_slice(array_values(runtime_licence::get_status()), 0, 2));
        // Signal on: a valid online token wins, even revoked.
        set_config('brandable_heartbeat', 1, 'theme_brandablert');
        set_config('brandable_token', $this->token(['sts' => 'revoked']), 'theme_brandablert');
        $this->assertSame('revoked', runtime_licence::get_status()['state']);
        // Online token no longer valid: the offline licence.
        set_config('brandable_token', 'broken', 'theme_brandablert');
        $this->assertSame('offline', runtime_licence::get_status()['source']);
    }

    public function test_problem_warnings_and_notifications(): void {
        global $PAGE;

        $this->setAdminUser();
        // The state is recorded (the administrators are notified once per change: checked on an installed theme).
        set_config('brandable_token', $this->token(['sts' => 'revoked']), 'theme_brandablert');
        runtime_licence::refresh();
        $this->assertGreaterThan(0, (int) get_config('theme_brandablert', 'brandable_problemsince'));
        $this->assertSame('revoked', get_config('theme_brandablert', 'brandable_state'));

        // Administration pages only during 30 days, then every page; never for the other users.
        $PAGE->set_pagelayout('admin');
        $this->assertSame(\core\output\notification::NOTIFY_ERROR, runtime_licence::warning($PAGE)[1]);
        $page = new \moodle_page();
        $page->set_context(\context_system::instance());
        $page->set_pagelayout('standard');
        $this->assertNull(runtime_licence::warning($page));
        set_config('brandable_problemsince', time() - 31 * DAYSECS, 'theme_brandablert');
        $this->assertNotNull(runtime_licence::warning($page));
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertNull(runtime_licence::warning($page));

        // Active again: problem cleared, no warning.
        $this->setAdminUser();
        set_config('brandable_token', $this->token(), 'theme_brandablert');
        runtime_licence::refresh();
        $this->assertFalse(get_config('theme_brandablert', 'brandable_problemsince'));
        $this->assertNull(runtime_licence::warning($page));
        // The strings of the runtime theme are not installed here (they are on an exported theme).
        $this->resetDebugging();
    }

    public function test_heartbeat(): void {
        $fresh = $this->token(['iat' => time() + 1]);
        $curl = $this->curl(200, ['status' => 'active', 'licenceToken' => $fresh, 'publicKeys' => $this->keys,
            'nextHeartbeatAfter' => 86400]);
        $this->assertNull(runtime_licence::heartbeat($curl));
        $this->assertSame($fresh, get_config('theme_brandablert', 'brandable_token'));
        $this->assertStringContainsString('"themeName":"brandablert","themeVersion":"2026102800"', $curl->sent);
        $this->assertStringNotContainsString('user', $curl->sent);

        // Failures keep the last token; an invalid token is a failure; 401: the licence can no longer be checked.
        $this->assertSame(0, runtime_licence::heartbeat($this->curl(503, ['error' => ['code' => 'licensing_unavailable']])));
        $this->assertSame(0, runtime_licence::heartbeat($this->curl(200, ['licenceToken' => 'forged'])));
        $this->assertSame($fresh, get_config('theme_brandablert', 'brandable_token'));
        $this->assertNull(runtime_licence::heartbeat($this->curl(401, ['error' => ['code' => 'licence_invalid']])));
        $this->assertSame('unverified', runtime_licence::get_status()['state']);

        // Signal off: no call.
        set_config('brandable_heartbeat', 0, 'theme_brandablert');
        $curl = $this->curl(200, []);
        $this->assertNull(runtime_licence::heartbeat($curl));
        $this->assertSame('', $curl->sent);
        $this->resetDebugging();
    }

    /**
     * Returns an HTTP client answering once.
     *
     * @param int $status HTTP status.
     * @param array $body JSON body.
     * @return \curl
     */
    private function curl(int $status, array $body): \curl {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        return new class ($status, json_encode($body)) extends \curl {
            /** @var string Body sent. */
            public string $sent = '';

            /**
             * Constructor.
             *
             * @param int $status HTTP status.
             * @param string $body Body answered.
             */
            public function __construct(
                /** @var int Status. */
                private int $status,
                /** @var string Body. */
                private string $body
            ) {
                parent::__construct();
            }

            #[\Override]
            public function post($url, $params = '', $options = []) {
                $this->sent = (string) $params;
                return $this->body;
            }

            #[\Override]
            public function get_info() {
                return ['http_code' => $this->status];
            }
        };
    }
}
