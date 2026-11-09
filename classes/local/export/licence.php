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

/**
 * Licence written in an exported theme (licence.php, never a file readable over HTTP): values returned once by
 * POST /exports, or the published test vector (tests, development).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class licence {
    /** @var string Licence id of the test vector (licence-contract.md, 3.2). */
    public const TEST_ID = '3f9a2c4e-1b2d-4e5f-8a9b-0c1d2e3f4a5b';

    /** @var string Token of the test vector (theme acme). */
    public const TEST_TOKEN = 'eyJhbGciOiJFZERTQSIsInR5cCI6IkpXVCIsImtpZCI6InRlc3QtdmVjdG9yIn0.'
        . 'eyJpc3MiOiJodHRwczovL2FwcC5icmFuZGFibGVsbXMuY29tIiwic3ViIjoiM2Y5YTJjNGUtMWIyZC00ZTVmLThhOWItMGMxZDJl'
        . 'M2Y0YTViIiwib3JnIjoiNmFkODZkNWQ1NmM5ZWM0YWYwMDIxODg4IiwicHJqIjoiYjdjMWQyZTMtZjRhNS00YjZjLTlkOGUtN2Y2'
        . 'YTViNGMzZDJlIiwidGhtIjoiYWNtZSIsInBsYW4iOiJzaXRlX2xvY2FsIiwic3RzIjoiYWN0aXZlIiwicGFpZFVudGlsIjoxNzk4'
        . 'NzYxNjAwLCJncmFjZVVudGlsIjoxODAxMzUzNjAwLCJpYXQiOjE3OTgwMDAwMDAsImV4cCI6MTgwMTM1MzYwMH0'
        . '.Hmy06BMX_oo-vfmGu--liehmO1vp9efF6-yiczzaBJu9tLdfR3CI_CM0SknfTTVaA_wpiQFsypdRIxXYnKXcCg';

    /** @var array Public key of the test vector. */
    public const TEST_KEYS = [['kty' => 'OKP', 'crv' => 'Ed25519', 'alg' => 'EdDSA', 'use' => 'sig', 'kid' => 'test-vector',
        'x' => '6kpsY-KcUgq-9VB7Ey7F-ZVHdq6-vnuSQh7qaRRG0iw']];

    /**
     * Constructor.
     *
     * @param string $id Licence id (UUID).
     * @param string $key Licence key (blk_...): authenticates the heartbeat. Secret.
     * @param string $token Initial token (JWS).
     * @param array $publickeys Verification keys (JWK).
     * @param string $heartbeaturl Heartbeat URL.
     * @param string $keysurl Public keys URL.
     * @param bool $test Whether this is the test licence.
     */
    public function __construct(
        /** @var string Licence id. */
        public readonly string $id,
        /** @var string Licence key. */
        public readonly string $key,
        /** @var string Initial token. */
        public readonly string $token,
        /** @var array Public keys. */
        public readonly array $publickeys,
        /** @var string Heartbeat URL. */
        public readonly string $heartbeaturl,
        /** @var string Public keys URL. */
        public readonly string $keysurl,
        /** @var bool Test licence. */
        public readonly bool $test = false,
    ) {
    }

    /**
     * Reads the licence of a 201 answer of POST /exports.
     *
     * @param array $response Answer.
     * @return self
     * @throws \moodle_exception When a value is missing or invalid.
     */
    public static function from_response(array $response): self {
        $keys = array_values(array_filter($response['publicKeys'] ?? [], fn($key) => is_array($key)
            && is_string($key['kid'] ?? null) && is_string($key['x'] ?? null)));
        $valid = preg_match('/^[0-9a-f-]{36}$/', (string) ($response['licenceId'] ?? ''))
            && preg_match('/^blk_[A-Za-z0-9_-]{20,}$/', (string) ($response['licenceKey'] ?? ''))
            && preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', (string) ($response['licenceToken'] ?? ''))
            && $keys && preg_match('~^https?://~', (string) ($response['heartbeatUrl'] ?? ''))
            && preg_match('~^https?://~', (string) ($response['keysUrl'] ?? ''));
        if (!$valid) {
            throw new \moodle_exception('error:apiresponse', 'theme_brandable');
        }
        $keys = array_map(
            fn(array $key) => array_intersect_key($key, array_flip(['kty', 'crv', 'alg', 'use', 'kid', 'x'])),
            $keys
        );
        return new self(
            $response['licenceId'],
            $response['licenceKey'],
            $response['licenceToken'],
            $keys,
            $response['heartbeatUrl'],
            $response['keysUrl']
        );
    }

    /**
     * Returns the test licence (published test vector: verifies for the theme acme only, heartbeat refused).
     *
     * @return self
     */
    public static function test(): self {
        return new self(
            self::TEST_ID,
            'blk_test_' . str_repeat('0', 34),
            self::TEST_TOKEN,
            self::TEST_KEYS,
            'http://localhost:3000/api/v1/licences/heartbeat',
            'http://localhost:3000/api/v1/licences/keys',
            true
        );
    }

    /**
     * Returns the content of licence.php: the values as a PHP array (var_export of validated strings).
     *
     * @param string $themename Theme name.
     * @param string $themeversion Theme version.
     * @return string
     */
    public function to_php(string $themename, string $themeversion): string {
        $data = [
            'licenceId' => $this->id,
            'licenceKey' => $this->key,
            'licenceToken' => $this->token,
            'publicKeys' => $this->publickeys,
            'heartbeatUrl' => $this->heartbeaturl,
            'keysUrl' => $this->keysurl,
            'themeName' => $themename,
            'themeVersion' => $themeversion,
            'test' => $this->test,
        ];
        return "<?php\n" . exporter::php_header('Licence of theme_' . $themename . ' (written by Brandable at the export). Secret: '
            . 'never serve it, revoke it from the Brandable dashboard if it leaks.', $themename)
            . "defined('MOODLE_INTERNAL') || die();\n\nreturn " . var_export($data, true) . ";\n";
    }
}
