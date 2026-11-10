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

use theme_brandable\external\get_cloud_history;
use theme_brandable\external\restore_cloud_version;
use theme_brandable\local\account\api_client;
use theme_brandable\local\account\connection;
use theme_brandable\local\assets\file_checker;
use theme_brandable\local\assets\library;
use theme_brandable\local\js\review;
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\resolver;
use theme_brandable\local\overrides\revision_manager;

#[\PHPUnit\Framework\Attributes\CoversClass(restore::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(get_cloud_history::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(restore_cloud_version::class)]
/**
 * Tests for the restoration and the pull of a version of the project (contract 13.5, spec C8 to C13).
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\cloud\restore
 * @covers     \theme_brandable\external\get_cloud_history
 * @covers     \theme_brandable\external\restore_cloud_version
 */
final class restore_test extends \advanced_testcase {
    /** @var string Signature of the right form. */
    private const SIGNATURE = 'v1.testSignatureNotFromBrandable00';

    /** @var array Files of the cloud, by URL. */
    private array $files = [];

    #[\Override]
    protected function setUp(): void {
        global $SESSION;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        connection::store_key('bsk_9fQxAbCdEfGhIjKlMnOpQrStUvWxYz0123456789-_A');
        $SESSION->theme_brandable_account = ['at' => time(), 'data' => ['cloud' => ['access' => cloud::FULL]]];
    }

    /**
     * A client answering by path, recording the requests.
     *
     * @param array $answers Path prefix => answer.
     * @return api_client
     */
    private function client(array $answers): api_client {
        return new class ($answers) extends api_client {
            /** @var string[] Paths requested. */
            public array $paths = [];

            /**
             * Constructor.
             *
             * @param array $answers Answers by path prefix.
             */
            public function __construct(
                /** @var array Answers by path prefix. */
                private array $answers
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
                foreach ($this->answers as $prefix => $answer) {
                    if (strpos($path, $prefix) === 0) {
                        return $answer;
                    }
                }
                throw new \coding_exception("unexpected request $method $path");
            }
        };
    }

    /**
     * A PNG file of the cloud, served at a signed URL.
     *
     * @param string $slug Slug.
     * @param int $red Red (different contents).
     * @return array Asset of the version.
     */
    private function png(string $slug, int $red): array {
        $gd = imagecreatetruecolor(30, 20);
        imagefill($gd, 0, 0, imagecolorallocate($gd, $red, 10, 10));
        ob_start();
        imagepng($gd);
        $content = (string) ob_get_clean();
        // As the library would store it (the file served).
        $path = make_request_directory() . "/$slug.png";
        file_put_contents($path, $content);
        $content = file_checker::check_image($path)['files']['optimized']['content'];
        $url = "https://cloud.example.com/$slug?sig=1";
        $this->files[$url] = $content;
        return ['kind' => 'image', 'slug' => $slug, 'sha256' => hash('sha256', $content), 'mime' => 'image/png',
            'size' => strlen($content), 'width' => 30, 'height' => 20, 'alt' => "Alt $slug", 'url' => $url];
    }

    /**
     * Adds an image to the library.
     *
     * @param string $slug Slug.
     * @param array|null $asset Asset of the cloud whose file is used, else a new one.
     * @return \stdClass
     */
    private function local(string $slug, ?array $asset = null): \stdClass {
        $path = make_request_directory() . "/$slug.png";
        if ($asset) {
            file_put_contents($path, $this->files[$asset['url']]);
        } else {
            $gd = imagecreatetruecolor(30, 20);
            imagefill($gd, 0, 0, imagecolorallocate($gd, 200, 200, 200));
            imagepng($gd, $path);
        }
        return library::add(file_checker::check_image($path), ['slug' => $slug]);
    }

    /**
     * A JavaScript change of the version.
     *
     * @param string $slug Slug.
     * @param array $extra Other fields.
     * @return array
     */
    private function module(string $slug, array $extra = []): array {
        return $extra + ['type' => 'js', 'target' => "js/$slug", 'content' => "export default function (b) { /* $slug */ }\n",
            'sourceHash' => null, 'scope' => ['level' => 'site', 'value' => null], 'scopeRef' => null,
            'compiled' => 'function(b){"use strict";}', 'report' => ['analyzer' => '2', 'apis' => [], 'findings' => []],
            'signature' => self::SIGNATURE, 'blocked' => false];
    }

    /**
     * Restores a version answered by the cloud.
     *
     * @param array $version Answer of GET /cloud/versions/{id}.
     * @param string $environment Environment of this site.
     * @param string $proposalid Proposal.
     * @param array|null $proposals Proposals of the state; by default, the proposal of v12 when one is given.
     * @return array [result, client]
     */
    private function restore(
        array $version,
        string $environment = 'e-dev',
        string $proposalid = '',
        ?array $proposals = null
    ): array {
        $proposals ??= $proposalid === '' ? [] : [['id' => $proposalid, 'version' => ['id' => 'v12', 'number' => 12],
            'from' => ['id' => 'e-qa', 'name' => 'Recette'], 'to' => ['id' => $environment], 'status' => 'pending']];
        $client = $this->client([
            '/cloud/versions/v12' => $version,
            '/cloud/state' => ['access' => 'full', 'environment' => ['id' => $environment, 'published' => null],
                'environments' => [], 'proposals' => $proposals],
        ]);
        $fetch = fn(string $url) => $this->files[$url] ?? null;
        return [(new restore($client, $fetch))->run('v12', $proposalid), $client];
    }

    public function test_restore_a_version(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['shortname' => 'MATH101', 'idnumber' => '']);
        // Already here under another name; a name taken here by another file; a new file; a file not as announced.
        $same = $this->png('logo-univ', 10);
        $this->local('logo-local', $same);
        $taken = $this->png('banner', 20);
        $this->local('banner');
        $new = $this->png('campus', 30);
        $wrong = $this->png('broken', 40);
        $this->files[$wrong['url']] = 'not the file';
        // JavaScript: published here before (reviewed), new here (reviewed elsewhere is not enough), refused, unsigned.
        revision_manager::create([change::from_array($this->module('known'))], 'p', 's');
        foreach (review::get_changed() as $module) {
            review::record($module['key'], $module['hash']);
        }
        revision_manager::publish('Known');
        revision_manager::create([change::from_array(['type' => 'js', 'target' => 'js/known', 'deleted' => true])], 'p', 's');
        revision_manager::publish('Without');

        [$result, $client] = $this->restore([
            'version' => ['id' => 'v12', 'number' => 12, 'note' => 'Barre bleue.', 'environment' => ['id' => 'e-dev',
                'name' => 'Dev 4.5']],
            'changes' => [
                ['type' => 'template', 'target' => 'core/loading', 'content' => '<img src="[[brandable-asset:banner]]">',
                    'sourceHash' => str_repeat('a', 64), 'scope' => ['level' => 'site', 'value' => null], 'scopeRef' => null],
                ['type' => 'template', 'target' => 'core/notification_error', 'content' => '<p>{{#js}}x{{/js}}</p>',
                    'sourceHash' => null, 'scope' => ['level' => 'site', 'value' => null], 'scopeRef' => null],
                ['type' => 'scss', 'target' => 'post/course', 'content' => '.x { background: brandable-asset("campus"); }',
                    'sourceHash' => null, 'scope' => ['level' => 'course', 'value' => '999'],
                    'scopeRef' => ['idnumber' => 'NOPE', 'shortname' => 'MATH101']],
                ['type' => 'scss', 'target' => 'post/cm', 'content' => '.y { color: red; }', 'sourceHash' => null,
                    'scope' => ['level' => 'cm', 'value' => '77'], 'scopeRef' => ['idnumber' => 'GONE']],
                ['type' => 'setting', 'target' => 'logo', 'content' => 'logo-univ', 'sourceHash' => null,
                    'scope' => ['level' => 'site', 'value' => null], 'scopeRef' => null],
                $this->module('known'),
                $this->module('fresh'),
                $this->module('refused', ['blocked' => true, 'compiled' => null]),
                $this->module('unsigned', ['signature' => null]),
            ],
            'assets' => [$same, $taken, $new, $wrong],
            'findings' => [['code' => 'template_source_changed', 'severity' => 'warning', 'type' => 'template',
                'target' => 'core/loading', 'message' => 'The original differs.']],
        ]);
        // The version of this site, for its Moodle and its parent theme.
        $this->assertMatchesRegularExpression(
            '~^GET /cloud/versions/v12\?moodleVersion=\d+\.\d+\.\d+&parentTheme=boost&lang=~',
            $client->paths[0]
        );
        $this->assertSame(['restore', 12], [$result['kind'], $result['number']]);
        $report = $result['report'];

        // Files: one downloaded, one renamed (name taken), one already here, one refused.
        $this->assertSame(2, $report['downloaded']);
        $this->assertSame([['from' => 'banner', 'to' => 'banner-2']], $report['renamed']);
        $this->assertSame(['broken'], array_column($report['refused'], 'slug'));
        $this->assertSame($new['sha256'], $DB->get_field('theme_brandable_asset', 'sha256', ['slug' => 'campus']));
        $this->assertSame('Alt campus', $DB->get_field('theme_brandable_asset', 'alt', ['slug' => 'campus']));
        $this->assertFalse($DB->record_exists('theme_brandable_asset', ['slug' => 'logo-univ']));

        // The draft of a new session, nothing published.
        $draft = revision_manager::get_draft();
        $this->assertSame($result['draft'], $draft);
        $this->assertNotSame(revision_manager::get_published(), $draft);
        $map = resolver::get_map($draft);
        $this->assertSame('<img src="[[brandable-asset:banner-2]]">', $map[change::TYPE_TEMPLATE]['core/loading']['content']);
        // The original of the version differs from the one of this site: the override is to check (C13).
        $this->assertNotEmpty($map[change::TYPE_TEMPLATE]['core/loading']['needsreview']);
        $this->assertSame('logo-local', $map[change::TYPE_SETTING]['logo']['content']);
        // The course found by its short name, the activity not found here: left out.
        $this->assertArrayHasKey('post/course@course:' . $course->id, $map[change::TYPE_SCSS]);
        $this->assertArrayNotHasKey('core/notification_error', $map[change::TYPE_TEMPLATE]);
        $removed = array_column($report['removed'], 'reason', 'target');
        $scope = get_string('scope_cm', 'theme_brandable', 'GONE');
        $this->assertSame(get_string('cloud:report:noscope', 'theme_brandable', $scope), $removed['post/cm']);
        $this->assertArrayHasKey('core/notification_error', $removed);
        // JavaScript: refused not taken; unsigned taken, as unsigned; the compilation of Brandable.
        $this->assertSame(['js/refused'], $report['blocked']);
        $this->assertArrayNotHasKey('js/refused', $map[change::TYPE_JS]);
        $this->assertSame('', (string) $map[change::TYPE_JS]['js/unsigned']['signature']);
        $this->assertSame('function(b){"use strict";}', $map[change::TYPE_JS]['js/fresh']['compiled']);
        $this->assertSame('template_source_changed', $report['findings'][0]['code']);

        // Review: the code published here before is reviewed; the rest is reviewed again here.
        $changed = array_column(review::get_changed(), 'reviewed', 'target');
        $this->assertSame(['js/known' => true, 'js/fresh' => false, 'js/unsigned' => false], $changed);
        // Published unchanged: restore, by the version of the project.
        $origin = json_decode(get_config('theme_brandable', backup::ORIGIN_CONFIG), true);
        $this->assertSame(['kind' => 'restore', 'versionId' => 'v12', 'revision' => $draft], $origin);
    }

    public function test_pull_from_another_environment(): void {
        $course = $this->getDataGenerator()->create_course(['shortname' => 'OTHER']);
        [$result] = $this->restore([
            'version' => ['id' => 'v12', 'number' => 12, 'note' => '', 'environment' => ['id' => 'e-staging',
                'name' => 'Staging']],
            'changes' => [
                // Without scopeRef: an id of another environment is never kept.
                ['type' => 'scss', 'target' => 'post/course', 'content' => '.x { color: red; }', 'sourceHash' => null,
                    'scope' => ['level' => 'course', 'value' => (string) $course->id], 'scopeRef' => null],
                ['type' => 'scss', 'target' => 'post/site', 'content' => '.y { color: red; }', 'sourceHash' => null,
                    'scope' => ['level' => 'site', 'value' => null], 'scopeRef' => null],
            ],
            'assets' => [],
            'findings' => [],
        ], 'e-dev', 'p7');
        $this->assertSame('pull', $result['kind']);
        // Named after the environment that proposed it, not the one the version comes from.
        global $DB;
        $summary = $DB->get_field('theme_brandable_revision', 'summary', ['id' => revision_manager::get_draft()]);
        $this->assertStringContainsString('Recette', $summary);
        $this->assertSame(['post/course'], array_column($result['report']['removed'], 'target'));
        $origin = json_decode(get_config('theme_brandable', backup::ORIGIN_CONFIG), true);
        $this->assertSame(
            ['kind' => 'pull', 'versionId' => 'v12', 'proposalId' => 'p7'],
            array_intersect_key($origin, array_flip(['kind', 'versionId', 'proposalId']))
        );
        // Published unchanged: pull with the proposal (accepted by Brandable).
        $this->assertSame(
            ['kind' => 'pull', 'versionId' => 'v12', 'proposalId' => 'p7'],
            backup::origin_of_draft(revision_manager::get_draft())
        );
    }

    public function test_pull_of_a_proposal_no_longer_waiting(): void {
        $draft = revision_manager::get_draft();
        try {
            $this->restore(['version' => ['id' => 'v12', 'number' => 12, 'environment' => ['id' => 'e-staging']],
                'changes' => [], 'assets' => [], 'findings' => []], 'e-dev', 'p7', []);
            $this->fail('A proposal no longer waiting is not pulled');
        } catch (\moodle_exception $e) {
            $this->assertSame('cloud:error:proposalgone', $e->errorcode);
        }
        $this->assertSame($draft, revision_manager::get_draft());
    }

    public function test_nothing_valid_changes_nothing(): void {
        $published = revision_manager::get_published();
        $this->expectException(\moodle_exception::class);
        try {
            $this->restore([
                'version' => ['id' => 'v12', 'number' => 12, 'note' => '', 'environment' => ['id' => 'e-dev']],
                'changes' => [['type' => 'template', 'target' => 'core/loading', 'content' => '<script>x</script>',
                    'sourceHash' => null, 'scope' => ['level' => 'site', 'value' => null], 'scopeRef' => null]],
                'assets' => [],
                'findings' => [],
            ]);
        } finally {
            $this->assertSame($published, revision_manager::get_draft());
            $this->assertFalse(get_config('theme_brandable', backup::ORIGIN_CONFIG));
        }
    }

    public function test_history_without_the_cloud(): void {
        global $SESSION;
        $SESSION->theme_brandable_account = ['at' => time(), 'data' => ['cloud' => ['access' => cloud::NONE],
            'urls' => ['versions' => 'https://app.brandablelms.com/dashboard/projects/p1/versions'],
            'offers' => ['plans' => [
                ['id' => 'site_local_monthly', 'features' => ['js'], 'maxSites' => 1, 'price' => ['interval' => 'month']],
                ['id' => 'site_cloud_yearly', 'features' => ['cloud'], 'maxSites' => 1, 'price' => ['interval' => 'year']],
                ['id' => 'site_cloud_monthly', 'features' => ['cloud'], 'maxSites' => 1, 'price' => ['interval' => 'month']],
            ]]]];
        $history = \core_external\external_api::clean_returnvalue(
            get_cloud_history::execute_returns(),
            get_cloud_history::execute('')
        );
        // The entry stays: it offers the Pro offer, without calling the cloud.
        $this->assertSame(['none', 'site_cloud_monthly', []], [$history['access'], $history['upgradeitem'], $history['versions']]);
    }
}
