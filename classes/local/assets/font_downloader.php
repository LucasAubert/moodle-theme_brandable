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

namespace theme_brandable\local\assets;

use moodle_exception;
use theme_brandable\local\account\api_client;
use theme_brandable\local\account\api_exception;

/**
 * Fonts of the catalogue asked by a generation (result.fontRequests, contract 11.6, spec A11): every file and the
 * licence text of each family are downloaded from Brandable and stored in the library before the revision is created.
 *
 * Downloads run in batches limited in time (one batch per poll of the editor); what is already in the library is
 * never downloaded again. Only paths of the Brandable fonts API are fetched (/fonts/...), with the site key: an URL of
 * the result never makes the server call another host.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class font_downloader {
    /** @var int Seconds of downloads per poll. */
    public const BATCH_SECONDS = 10;

    /** @var string[] Licences of the catalogue (contract 11.6). */
    public const LICENCES = ['OFL-1.1', 'Apache-2.0', 'UFL-1.0'];

    /** @var int Most files of a family (contract 11.2). */
    public const MAX_FILES = 64;

    /** @var string Path of a font file or a licence in the API, after the base URL. */
    private const PATH = '~^/fonts/[a-z0-9-]{1,100}/(?:[a-z0-9-]{1,120}\.woff2|LICENSE)$~';

    /** @var string Fixture served instead of the API for the demo generator (no network). */
    public const MOCK_SCHEME = 'mock:';

    /**
     * Checks the font requests of a result; invalid ones are refused (the result is not applied).
     *
     * @param mixed $requests result.fontRequests.
     * @param bool $mock Whether the fixtures of the demo generator are allowed (mock: URLs).
     * @return array Requests: [{family, license, licensepath, files: [{slug, weight, style, subset, path}]}].
     * @throws moodle_exception asset:error:fontrequest
     */
    public static function parse($requests, bool $mock = false): array {
        $parsed = [];
        $invalid = fn() => new moodle_exception('asset:error:fontrequest', 'theme_brandable');
        if (!is_array($requests)) {
            throw $invalid();
        }
        foreach ($requests as $request) {
            $family = trim((string) ($request['family'] ?? ''));
            $files = $request['files'] ?? null;
            if (
                $family === '' || \core_text::strlen($family) > 100 || !in_array($request['license'] ?? '', self::LICENCES, true)
                    || !is_array($files) || !$files || count($files) > self::MAX_FILES
            ) {
                throw $invalid();
            }
            $item = ['family' => $family, 'license' => $request['license'], 'files' => [],
                'licensepath' => isset($request['licenseUrl']) ? self::path((string) $request['licenseUrl'], $mock) : null];
            foreach ($files as $file) {
                $slug = (string) ($file['slug'] ?? '');
                $weight = (int) ($file['weight'] ?? 0);
                $style = (string) ($file['style'] ?? '');
                $subset = (string) ($file['subset'] ?? '');
                if (
                    !preg_match(library::SLUG_PATTERN, $slug) || strlen($slug) > library::SLUG_LENGTH || $weight < 1
                        || $weight > 1000 || !in_array($style, library::STYLES, true) || !preg_match('/^[a-z0-9-]{0,30}$/', $subset)
                ) {
                    throw $invalid();
                }
                $item['files'][] = ['slug' => $slug, 'weight' => $weight, 'style' => $style, 'subset' => $subset,
                    'path' => self::path((string) ($file['url'] ?? ''), $mock)];
            }
            $parsed[] = $item;
        }
        return $parsed;
    }

    /**
     * Downloads what is missing from the library, within the time of a batch.
     *
     * @param array $requests Requests checked by parse().
     * @param api_client|null $client Client of the API (with the site key); null for the demo generator (fixtures).
     * @param int $seconds Time of the batch.
     * @return array ['done' => bool, 'stored' => files stored in this batch, 'missing' => files left]
     * @throws api_exception When Brandable refuses or cannot serve a file (404, 429, 503, network).
     * @throws moodle_exception When a file is not a WOFF2 font, or the library is full.
     */
    public static function download(array $requests, ?api_client $client, int $seconds = self::BATCH_SECONDS): array {
        $deadline = time() + $seconds;
        $assets = library::get_all();
        $stored = 0;
        $missing = 0;
        foreach ($requests as $request) {
            foreach ($request['files'] as $file) {
                if (isset($assets[$file['slug']])) {
                    continue;
                }
                if (time() >= $deadline) {
                    $missing++;
                    continue;
                }
                self::store_licence($request, $client);
                $path = make_request_directory() . '/font.woff2';
                file_put_contents($path, self::fetch($file['path'], $client));
                $assets[$file['slug']] = library::add(file_checker::check_font($path), [
                    'slug' => $file['slug'], 'family' => $request['family'], 'weight' => $file['weight'],
                    'style' => $file['style'], 'subset' => $file['subset'], 'origin' => 'google',
                    'licence' => $request['license'],
                ]);
                $stored++;
            }
        }
        return ['done' => $missing === 0, 'stored' => $stored, 'missing' => $missing];
    }

    /**
     * Stores the licence text of a family, once (replaced when the licence changes).
     *
     * @param array $request Request.
     * @param api_client|null $client Client.
     */
    private static function store_licence(array $request, ?api_client $client): void {
        $current = library::get_family($request['family']);
        if ($current && $current->licence === $request['license'] && (string) $current->licencetext !== '') {
            return;
        }
        // Without a licence URL (not expected since the contract has licenseUrl): a notice naming the licence.
        $text = $request['licensepath'] !== null ? self::fetch($request['licensepath'], $client)
            : get_string('asset:licencenotice', 'theme_brandable', ['family' => $request['family'],
                'licence' => $request['license'], 'url' => 'https://spdx.org/licenses/' . $request['license'] . '.html']);
        library::set_family_licence($request['family'], $request['license'], file_checker::check_licence($text));
    }

    /**
     * Returns the path of an URL of the fonts API (after /api/v1), whatever its host: the server only calls its own
     * API URL (dev mode: the URL for the server, contract 11.6).
     *
     * @param string $url URL of the result.
     * @param bool $allowmock Whether mock: URLs are allowed (demo generator).
     * @return string Path, or mock:<path> for the demo generator.
     * @throws moodle_exception asset:error:fontrequest
     */
    private static function path(string $url, bool $allowmock): string {
        $mock = $allowmock && strpos($url, self::MOCK_SCHEME) === 0;
        $path = $mock ? substr($url, strlen(self::MOCK_SCHEME)) : (string) preg_replace('~^https?://[^/]+/api/v1~', '', $url, 1);
        if ($path === $url && !$mock || !preg_match(self::PATH, $path)) {
            throw new moodle_exception('asset:error:fontrequest', 'theme_brandable');
        }
        return ($mock ? self::MOCK_SCHEME : '') . $path;
    }

    /**
     * Downloads a path of the fonts API (or the fixture of the demo generator).
     *
     * @param string $path Path.
     * @param api_client|null $client Client.
     * @return string Content.
     */
    private static function fetch(string $path, ?api_client $client): string {
        if (strpos($path, self::MOCK_SCHEME) === 0) {
            $name = basename(substr($path, strlen(self::MOCK_SCHEME)));
            $fixture = dirname(__DIR__, 3) . '/tests/fixtures/fonts/' . ($name === 'LICENSE' ? 'LICENSE.txt' : 'font.woff2');
            if (!is_readable($fixture)) {
                throw new moodle_exception('asset:error:fontrequest', 'theme_brandable');
            }
            return (string) file_get_contents($fixture);
        }
        if ($client === null) {
            throw new api_exception('site_not_connected', get_string('error:notconnected', 'theme_brandable'));
        }
        return $client->download($path, file_checker::MAX_FILE);
    }
}
