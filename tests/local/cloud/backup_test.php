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

use theme_brandable\external\editor_state;
use theme_brandable\local\account\api_client;
use theme_brandable\local\account\api_exception;
use theme_brandable\local\account\connection;
use theme_brandable\local\assets\file_checker;
use theme_brandable\local\assets\library;
use theme_brandable\local\js\review;
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\revision_manager;

/**
 * Tests for the backup of the versions in the cloud of the Pro offer (contract 13.4, spec C1 to C7): snapshot, order
 * of the sendings, queue, catch-up, origin of a publication.
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\cloud\backup
 * @covers     \theme_brandable\local\cloud\snapshot
 * @covers     \theme_brandable\local\cloud\status
 */
#[\PHPUnit\Framework\Attributes\CoversClass(backup::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(snapshot::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(status::class)]
final class backup_test extends \advanced_testcase {
    /** @var string A valid site key. */
    private const KEY = 'bsk_9fQxAbCdEfGhIjKlMnOpQrStUvWxYz0123456789-_A';

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        connection::store_key(self::KEY);
        $this->access(cloud::FULL);
    }

    /**
     * Sets the rights of the site (the account kept in the session).
     *
     * @param string $access full, read or none.
     */
    private function access(string $access): void {
        global $SESSION;
        $SESSION->theme_brandable_account = ['at' => time(), 'data' => ['cloud' => ['access' => $access],
            'urls' => ['billingContact' => 'https://app.brandablelms.com/billing/contact']]];
    }

    /**
     * A client of the API answering from a script, recording the requests.
     *
     * @param array $answers List of answers (array) or api_exception, in order.
     * @return api_client
     */
    private function client(array $answers): api_client {
        return new class ($answers) extends api_client {
            /** @var array Requests: [method, path, body, raw, type]. */
            public array $requests = [];

            /**
             * Constructor.
             *
             * @param array $answers Answers.
             */
            public function __construct(
                /** @var array Answers, in order. */
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
                $this->requests[] = [$method, $path, $body, $raw, $contenttype];
                $answer = array_shift($this->answers);
                if ($answer instanceof api_exception) {
                    throw $answer;
                }
                if ($answer === null) {
                    throw new \coding_exception("unexpected request $method $path");
                }
                return $answer;
            }
        };
    }

    /**
     * An error of the API.
     *
     * @param string $code Code.
     * @param array $details Other fields of the error.
     * @return api_exception
     */
    private function error(string $code, array $details = []): api_exception {
        return new api_exception($code, "message of $code", '', 400, $details);
    }

    /**
     * Adds an image to the library.
     *
     * @param string $slug Slug.
     * @return \stdClass
     */
    private function image(string $slug): \stdClass {
        $dir = make_request_directory();
        $gd = imagecreatetruecolor(40, 20);
        imagefill($gd, 0, 0, imagecolorallocate($gd, 10, 20, 30));
        imagepng($gd, "$dir/$slug.png");
        return library::add(file_checker::check_image("$dir/$slug.png"), ['slug' => $slug, 'alt' => 'Logo']);
    }

    /**
     * Publishes a version with the given changes.
     *
     * @param array $changes Changes (arrays).
     * @param string $note Note.
     * @return \stdClass Local version.
     */
    private function publish(array $changes, string $note = 'Note'): \stdClass {
        global $DB;
        revision_manager::create(array_map(fn($data) => change::from_array($data), $changes), 'p', 's');
        foreach (review::get_changed() as $module) {
            review::record($module['key'], $module['hash']);
        }
        $id = revision_manager::publish($note);
        return $DB->get_record('theme_brandable_version', ['id' => $id]);
    }

    public function test_snapshot_of_a_version(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['shortname' => 'MATH101', 'idnumber' => 'M101']);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'idnumber' => 'P1']);
        $logo = $this->image('logo-universite');
        $font = library::add(file_checker::check_font(__DIR__ . '/../../fixtures/fonts/font.woff2'), ['slug' => 'font-a',
            'family' => 'Brandabletest', 'weight' => 700, 'style' => 'normal', 'licence' => 'OFL-1.1']);
        library::set_family_licence('Brandabletest', 'OFL-1.1', "Copyright Brandabletest\n\nOFL text");
        $version = $this->publish([
            ['type' => 'template', 'target' => 'core/loading', 'content' => '<img src="[[brandable-asset:logo-universite]]">'],
            ['type' => 'scss', 'target' => 'post/course', 'content' => '.x { color: red; }',
                'scope' => ['level' => 'course', 'value' => (string) $course->id]],
            ['type' => 'scss', 'target' => 'post/cm', 'content' => '@font-face { src: brandable-asset("font-a"); }',
                'scope' => ['level' => 'cm', 'value' => (string) $page->cmid]],
            ['type' => 'setting', 'target' => 'logo', 'content' => 'logo-universite'],
            ['type' => 'js', 'target' => 'js/welcome', 'content' => "export default function (b) {}\n",
                'compiled' => 'function(b){"use strict";}', 'report' => ['analyzer' => '1', 'apis' => [], 'findings' => []],
                'signature' => 'v1.testSignatureNotFromBrandable00', 'scope' => ['level' => 'page', 'value' => 'bp-my-index']],
        ], "Barre bleue.\nPolice plus grande.");

        $row = $DB->get_record(backup::TABLE, ['versionid' => $version->id]);
        $this->assertNotFalse($row, 'queued at the publication');
        $payload = json_decode($row->payload, true);
        $this->assertSame($row->clientversionid, $payload['clientVersionId']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $payload['clientVersionId']);
        $this->assertSame(1, $payload['localNumber']);
        $this->assertSame("Barre bleue.\nPolice plus grande.", $payload['note']);
        $this->assertSame(
            ['hash' => cloud::author_hash((int) get_admin()->id), 'name' => fullname(get_admin())],
            $payload['author']
        );
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $payload['moodleVersion']);
        $this->assertSame('boost', $payload['parentTheme']);
        $this->assertSame(['kind' => 'publish'], $payload['origin']);
        $this->assertTrue($payload['published']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $payload['publishedAt']);

        // Every override, no delta; the courses and activities by their identifiers; the JavaScript without compiled.
        $changes = array_column($payload['changes'], null, 'target');
        $this->assertCount(5, $changes);
        $this->assertSame(['level' => 'site', 'value' => null], $changes['core/loading']['scope']);
        $this->assertSame(['idnumber' => 'M101', 'shortname' => 'MATH101'], $changes['post/course']['scopeRef']);
        $this->assertSame(['idnumber' => 'P1'], $changes['post/cm']['scopeRef']);
        $this->assertArrayNotHasKey('scopeRef', $changes['core/loading']);
        $this->assertSame('logo-universite', $changes['logo']['content']);
        $this->assertSame('v1.testSignatureNotFromBrandable00', $changes['js/welcome']['signature']);
        $this->assertArrayNotHasKey('compiled', $changes['js/welcome']);
        $this->assertArrayNotHasKey('deleted', $changes['js/welcome']);

        // The assets used, the licence of the family; where each file is read.
        $assets = array_column($payload['assets'], null, 'kind');
        $logo = $DB->get_record('theme_brandable_asset', ['id' => $logo->id]);
        $this->assertSame(
            ['kind' => 'image', 'slug' => 'logo-universite', 'sha256' => $logo->sha256, 'mime' => 'image/png'],
            array_intersect_key($assets['image'], array_flip(['kind', 'slug', 'sha256', 'mime']))
        );
        $this->assertSame(hash('sha256', library::get_file($logo, 'optimized')->get_content()), $logo->sha256);
        $this->assertSame([40, 20, 'Logo'], [$assets['image']['width'], $assets['image']['height'], $assets['image']['alt']]);
        $this->assertSame(['Brandabletest', 700, 'normal', 'OFL-1.1'], [$assets['font']['family'], $assets['font']['weight'],
            $assets['font']['style'], $assets['font']['license']]);
        $this->assertSame(hash('sha256', "Copyright Brandabletest\n\nOFL text"), $assets['font_license']['sha256']);
        $files = json_decode($row->files, true);
        $this->assertSame(['asset' => (int) $font->id], $files[$assets['font']['sha256']]);
        $this->assertSame(['family' => 'brandabletest'], $files[$assets['font_license']['sha256']]);
        $licence = snapshot::read_file($assets['font_license']['sha256'], ['family' => 'brandabletest']);
        $this->assertSame('text/plain', $licence['mime']);
        // The JavaScript published here is recorded.
        $this->assertTrue(review::was_published(review::hash("export default function (b) {}\n"), 'js/welcome'));
    }

    public function test_sending_order_and_version_stored(): void {
        global $DB;
        $logo = $this->image('logo');
        $version = $this->publish([['type' => 'setting', 'target' => 'logo', 'content' => 'logo']]);
        $sha = $DB->get_field('theme_brandable_asset', 'sha256', ['id' => $logo->id]);
        $this->assertSame(backup::PENDING, $DB->get_field('theme_brandable_version', 'cloudstatus', ['id' => $version->id]));

        // Check, PUT of the missing file (raw body), version; 409 assets_missing: sent, then the version again.
        $client = $this->client([
            ['missing' => []],
            $this->error('assets_missing', ['missing' => [$sha]]),
            ['sha256' => $sha, 'size' => 1, 'created' => true],
            ['versionId' => '9c2d0000-0000-4000-8000-000000000000', 'number' => 12, 'created' => true, 'publishedIn' => []],
        ]);
        $this->assertSame(['sent' => 1, 'waiting' => 0, 'stopped' => 0], backup::process(20, $client));
        $this->assertSame(
            ['POST /cloud/assets/check', 'POST /cloud/versions', "PUT /cloud/assets/$sha", 'POST /cloud/versions'],
            array_map(fn($request) => $request[0] . ' ' . $request[1], $client->requests)
        );
        $this->assertSame([['sha256' => $sha, 'size' => (int) library::get_file($logo, 'optimized')->get_filesize(),
            'mime' => 'image/png']], $client->requests[0][2]['assets']);
        $this->assertSame(library::get_file($logo, 'optimized')->get_content(), $client->requests[2][3]);
        $this->assertSame('image/png', $client->requests[2][4]);
        // The same request both times (idempotence).
        $this->assertSame($client->requests[1][2], $client->requests[3][2]);

        $stored = $DB->get_record('theme_brandable_version', ['id' => $version->id]);
        $this->assertSame(
            ['9c2d0000-0000-4000-8000-000000000000', 12, backup::SYNCED],
            [$stored->cloudversionid, (int) $stored->cloudnumber, $stored->cloudstatus]
        );
        $this->assertSame(0, $DB->count_records(backup::TABLE));
        $export = status::export($stored);
        $this->assertTrue($export['synced']);
        $this->assertSame(12, $export['number']);
        $state = \core_external\external_api::clean_returnvalue(editor_state::structure(), editor_state::export());
        $this->assertSame(12, $state['versions'][0]['cloud']['number']);
    }

    public function test_queue_waits_retries_and_stops(): void {
        global $DB;
        $first = $this->publish([['type' => 'scss', 'target' => 'post/a', 'content' => 'a { b: c; }']], 'A');
        $second = $this->publish([['type' => 'scss', 'target' => 'post/a', 'content' => 'a { b: d; }']], 'B');
        $this->assertSame(2, $DB->count_records(backup::TABLE));

        // Cloud not available: again later, in order (the second waits behind the first).
        $client = $this->client([$this->error('cloud_unavailable')]);
        $this->assertSame(['sent' => 0, 'waiting' => 1, 'stopped' => 0], backup::process(20, $client));
        $row = $DB->get_record(backup::TABLE, ['versionid' => $first->id]);
        $this->assertSame([1, 'cloud_unavailable'], [(int) $row->attempts, $row->lasterror]);
        $this->assertGreaterThan(time(), (int) $row->nextattempt);
        $this->assertSame(['sent' => 0, 'waiting' => 1, 'stopped' => 0], backup::process(20, $this->client([])));
        $export = status::export($DB->get_record('theme_brandable_version', ['id' => $first->id]));
        $this->assertTrue($export['pending']);
        $this->assertSame(get_string('cloud:error:unavailable', 'theme_brandable'), $export['errormessage']);

        // Storage full: the queue stops until the administrator acts; the support is offered.
        $DB->set_field(backup::TABLE, 'nextattempt', 0, ['id' => $row->id]);
        $this->assertSame(
            ['sent' => 0, 'waiting' => 0, 'stopped' => 1],
            backup::process(20, $this->client([$this->error('storage_limit_reached')]))
        );
        $export = status::export($DB->get_record('theme_brandable_version', ['id' => $first->id]));
        $this->assertTrue($export['stopped']);
        $this->assertSame('https://app.brandablelms.com/billing/contact', $export['contacturl']);
        $this->assertSame(
            ['sent' => 0, 'waiting' => 0, 'stopped' => 0],
            backup::process(20, $this->client([])),
            'the second version is not sent past a stopped queue'
        );

        // The administrator sends again: both, in order, the same UUIDs.
        $uuids = $DB->get_fieldset_select(backup::TABLE, 'clientversionid', '1 = 1 ORDER BY id');
        backup::retry();
        $client = $this->client([
            ['versionId' => 'v1', 'number' => 1, 'created' => true],
            ['versionId' => 'v2', 'number' => 2, 'created' => true],
        ]);
        $this->assertSame(['sent' => 2, 'waiting' => 0, 'stopped' => 0], backup::process(20, $client));
        $this->assertSame($uuids, array_map(fn($request) => $request[2]['clientVersionId'], $client->requests));
        $this->assertSame('v2', $DB->get_field('theme_brandable_version', 'cloudversionid', ['id' => $second->id]));
    }

    public function test_refused_version_does_not_stop_the_next_ones(): void {
        global $DB;
        $first = $this->publish([['type' => 'scss', 'target' => 'post/a', 'content' => 'a { b: c; }']], 'A');
        $this->publish([['type' => 'scss', 'target' => 'post/a', 'content' => 'a { b: d; }']], 'B');
        $client = $this->client([$this->error('invalid_request'), ['versionId' => 'v2', 'number' => 2, 'created' => true]]);
        $this->assertSame(['sent' => 1, 'waiting' => 0, 'stopped' => 1], backup::process(20, $client));
        $version = $DB->get_record('theme_brandable_version', ['id' => $first->id]);
        $this->assertSame(backup::FAILED, $version->cloudstatus);
        $this->assertSame(get_string('cloud:error:invalid_request', 'theme_brandable'), status::export($version)['errormessage']);
    }

    public function test_rights_and_never_in_the_way(): void {
        global $DB;
        // Reading only, or no cloud: nothing queued; the publication goes on.
        foreach ([cloud::READ, cloud::NONE] as $access) {
            $this->access($access);
            $version = $this->publish([['type' => 'scss', 'target' => 'post/a', 'content' => "a { b: $access; }"]]);
            $this->assertNull($version->cloudstatus);
            $this->assertSame('', status::export($version)['status']);
        }
        $this->assertSame(0, $DB->count_records(backup::TABLE));
        // Not connected: none.
        set_config(connection::KEY_CONFIG, '', 'theme_brandable');
        $this->assertSame(cloud::NONE, cloud::get_access());
    }

    public function test_origin_of_a_publication(): void {
        global $DB;
        // Restored draft, unchanged: restore; changed since: publish with the version it started from.
        $this->publish([['type' => 'scss', 'target' => 'post/a', 'content' => 'a { b: c; }']]);
        revision_manager::create(
            [change::from_array(['type' => 'scss', 'target' => 'post/a', 'content' => 'a { b: e; }'])],
            'p',
            's'
        );
        set_config(backup::ORIGIN_CONFIG, json_encode(['kind' => 'pull', 'versionId' => 'v9', 'proposalId' => 'p1',
            'revision' => revision_manager::get_draft()]), 'theme_brandable');
        $this->assertSame(
            ['kind' => 'pull', 'versionId' => 'v9', 'proposalId' => 'p1'],
            backup::origin_of_draft(revision_manager::get_draft())
        );
        $this->assertFalse(get_config('theme_brandable', backup::ORIGIN_CONFIG));
        set_config(backup::ORIGIN_CONFIG, json_encode(['kind' => 'pull', 'versionId' => 'v9', 'proposalId' => 'p1',
            'revision' => 1]), 'theme_brandable');
        $this->assertSame(['kind' => 'publish', 'versionId' => 'v9'], backup::origin_of_draft(revision_manager::get_draft()));
        $this->assertSame(['kind' => 'publish'], backup::origin_of_draft(revision_manager::get_draft()));

        // The previous version again: restored, by its version of the project.
        $DB->delete_records(backup::TABLE);
        $previous = revision_manager::get_current_version();
        $DB->set_field('theme_brandable_version', 'cloudversionid', 'v1', ['id' => $previous->id]);
        revision_manager::publish('Second');
        $DB->delete_records(backup::TABLE);
        revision_manager::revert_to_previous();
        $row = $DB->get_record(backup::TABLE, []);
        $this->assertSame(['kind' => 'restore', 'versionId' => 'v1'], json_decode($row->payload, true)['origin']);
        $this->assertSame((int) $previous->id, (int) $row->versionid);
    }

    public function test_sent_after_the_response(): void {
        global $DB;
        // Nothing queued: nothing planned.
        backup::send_after_response();
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(\theme_brandable\task\cloud_send::class));

        // A publication: the ad-hoc task, once, the publication never waiting for the cloud.
        $version = $this->publish([['type' => 'scss', 'target' => 'post/a', 'content' => 'a { b: c; }']]);
        backup::send_after_response();
        backup::send_after_response();
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(\theme_brandable\task\cloud_send::class));
        $this->assertSame(backup::PENDING, $DB->get_field('theme_brandable_version', 'cloudstatus', ['id' => $version->id]));
        // The task sends the queue (here, no cloud: it does nothing and fails nothing).
        $this->access(cloud::NONE);
        (new \theme_brandable\task\cloud_send())->execute();
        $this->assertSame(1, $DB->count_records(backup::TABLE));
    }

    public function test_catch_up(): void {
        global $DB;
        $this->access(cloud::NONE);
        $previous = $this->publish([['type' => 'scss', 'target' => 'post/a', 'content' => 'a { b: c; }']], 'A');
        $current = $this->publish([['type' => 'scss', 'target' => 'post/a', 'content' => 'a { b: d; }']], 'B');
        $this->assertSame(0, $DB->count_records(backup::TABLE));

        // The offer bought: Brandable knows no version for this environment.
        $state = ['access' => cloud::FULL, 'environment' => ['id' => 'e-dev', 'published' => null]];
        $this->assertSame(2, backup::catch_up($state));
        $rows = array_values($DB->get_records(backup::TABLE, null, 'id ASC'));
        $payloads = array_map(fn($row) => json_decode($row->payload, true), $rows);
        $this->assertSame([(int) $previous->id, (int) $current->id], array_map(fn($row) => (int) $row->versionid, $rows));
        $this->assertSame([['kind' => 'backfill'], ['kind' => 'backfill']], array_column($payloads, 'origin'));
        $this->assertSame([false, true], array_column($payloads, 'published'));
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', (int) $previous->timecreated), $payloads[0]['publishedAt']);
        // Once: nothing while queued, nor again for this environment.
        $this->assertSame(0, backup::catch_up($state));
        $DB->delete_records(backup::TABLE);
        $this->assertSame(0, backup::catch_up($state));
        // Reading only: nothing.
        $this->assertSame(0, backup::catch_up(['access' => cloud::READ] + $state));

        // Published while the cloud was not available: the version is queued.
        $DB->set_field('theme_brandable_version', 'cloudstatus', null, ['id' => $current->id]);
        $this->assertSame(1, backup::catch_up(['access' => cloud::FULL, 'environment' => ['id' => 'e-dev',
            'published' => ['versionId' => 'old', 'number' => 3]]]));
        $this->assertSame(['kind' => 'publish'], json_decode($DB->get_field(backup::TABLE, 'payload', []), true)['origin']);
    }
}
