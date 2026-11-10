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

use theme_brandable\local\account\api_client;

#[\PHPUnit\Framework\Attributes\CoversClass(diff::class)]
/**
 * Tests for the comparison of two versions of the project (contract 13.6, spec C14).
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\cloud\diff
 */
final class diff_test extends \advanced_testcase {
    /**
     * A client giving one answer, recording the paths.
     *
     * @param array $answer Answer.
     * @return api_client
     */
    private function client(array $answer): api_client {
        return new class ($answer) extends api_client {
            /** @var string[] Paths requested. */
            public array $paths = [];

            /**
             * Constructor.
             *
             * @param array $answer Answer.
             */
            public function __construct(
                /** @var array Answer. */
                private array $answer
            ) {
                parent::__construct('https://api.example.com/api/v1', 'bsk_test');
            }

            #[\Override]
            public function request(
                string $method,
                string $path,
                ?array $body = null,
                bool $authenticated = true,
                ?string $raw = null,
                string $contenttype = 'application/json'
            ): array {
                $this->paths[] = "$method $path";
                return $this->answer;
            }
        };
    }

    /**
     * The diff is read with the release, the parent theme and the language of the site, and given as the plugin shows
     * it: complete contents as text, scopes by scopeRef, licences by family, findings with their severity.
     */
    public function test_read_a_diff(): void {
        $this->resetAfterTest();
        $client = $this->client([
            'from' => ['id' => 'v11', 'number' => 11], 'to' => ['id' => 'v12', 'number' => 12],
            'changes' => [
                ['status' => 'modified', 'type' => 'scss', 'target' => 'post/navbar', 'scope' => ['level' => 'site',
                    'value' => null], 'scopeRef' => null, 'before' => ".a {\n}", 'after' => ".a {\n color: red;\n}"],
                ['status' => 'removed', 'type' => 'setting', 'target' => 'logocompact', 'scope' => ['level' => 'site'],
                    'before' => 'logo', 'after' => null],
                ['status' => 'added', 'type' => 'js', 'target' => 'menu', 'scope' => ['level' => 'course', 'value' => '7'],
                    'scopeRef' => ['idnumber' => 'C-7', 'shortname' => 'algebra'], 'before' => null,
                    'after' => ['content' => 'export const init = () => null;', 'signature' => null]],
                ['status' => 'renamed', 'type' => 'scss', 'target' => 'ignored'],
            ],
            'assets' => [
                ['status' => 'added', 'key' => 'font-inter-latin-400-normal', 'before' => null, 'after' => ['kind' => 'font']],
                ['status' => 'modified', 'key' => 'license:inter', 'before' => 'OFL 1.0', 'after' => 'OFL 1.1'],
            ],
            'findings' => [
                ['code' => 'js_rejected', 'target' => 'menu', 'message' => 'Refused.'],
                ['code' => 'template_source_changed', 'severity' => 'warning', 'target' => 'core/navbar', 'message' => 'Differs.'],
            ],
            'compatibility' => ['moodleVersion' => '4.5.6', 'parentTheme' => 'boost', 'indexed' => true, 'exact' => false],
        ]);

        $result = diff::read(diff::PUBLISHED, 'v12', $client);

        $this->assertCount(1, $client->paths);
        $this->assertStringStartsWith('GET /cloud/diff?from=published&to=v12&moodleVersion=', $client->paths[0]);
        $this->assertStringContainsString('&parentTheme=', $client->paths[0]);
        $this->assertStringContainsString('&lang=', $client->paths[0]);
        $this->assertSame(['id' => 'v11', 'number' => 11], $result['from']);
        $this->assertSame(12, $result['to']['number']);

        $this->assertCount(3, $result['changes']);
        [$scss, $setting, $js] = $result['changes'];
        $this->assertSame(['modified', ".a {\n}", ".a {\n color: red;\n}"], [$scss['status'], $scss['before'], $scss['after']]);
        $this->assertSame(get_string('scope_site', 'theme_brandable'), $scss['scopelabel']);
        $this->assertSame(['removed', 'logo', ''], [$setting['status'], $setting['before'], $setting['after']]);
        $this->assertSame(['', 'export const init = () => null;'], [$js['before'], $js['after']]);
        $this->assertSame(get_string('scope_course', 'theme_brandable', 'algebra'), $js['scopelabel']);

        $this->assertSame([
            ['status' => 'added', 'key' => 'font-inter-latin-400-normal', 'kind' => 'font', 'before' => '', 'after' => ''],
            ['status' => 'modified', 'key' => 'inter', 'kind' => 'license', 'before' => 'OFL 1.0', 'after' => 'OFL 1.1'],
        ], $result['assets']);
        $this->assertSame(['blocking', 'warning'], array_column($result['findings'], 'severity'));
        $this->assertSame(['moodleversion' => '4.5.6', 'indexed' => true, 'exact' => false], $result['compatibility']);
    }

    /**
     * Nothing published on the environment of the site: no version to compare from, everything added.
     */
    public function test_nothing_published_here(): void {
        $this->resetAfterTest();
        $result = diff::read(diff::PUBLISHED, 'v1', $this->client(['from' => null, 'to' => ['id' => 'v1', 'number' => 1],
            'changes' => [], 'assets' => [], 'findings' => []]));
        $this->assertNull($result['from']);
        $this->assertSame(1, $result['to']['number']);
        $this->assertSame(['moodleversion' => '', 'indexed' => true, 'exact' => true], $result['compatibility']);
    }

    /**
     * A version id out of the form is refused before any request.
     */
    public function test_invalid_version(): void {
        $this->resetAfterTest();
        $client = $this->client([]);
        try {
            diff::read('../account', 'v1', $client);
            $this->fail('An invalid version is refused');
        } catch (\invalid_parameter_exception $e) {
            $this->assertSame([], $client->paths);
        }
    }
}
