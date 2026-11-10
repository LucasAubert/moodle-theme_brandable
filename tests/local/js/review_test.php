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

namespace theme_brandable\local\js;

use theme_brandable\external\ask_js_review;
use theme_brandable\external\editor_state;
use theme_brandable\external\get_js_review;
use theme_brandable\external\mark_js_reviewed;
use theme_brandable\local\account\connection;
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\revision_manager;

#[\PHPUnit\Framework\Attributes\CoversClass(review::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(jsdownload::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(get_js_review::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(mark_js_reviewed::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(ask_js_review::class)]
/**
 * Tests for the review of the generated JavaScript before publishing (spec B13), the security review of Brandable
 * (contract 12.6) and the download of the JavaScript of a version (B14).
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\js\review
 * @covers     \theme_brandable\local\js\jsdownload
 * @covers     \theme_brandable\external\get_js_review
 * @covers     \theme_brandable\external\mark_js_reviewed
 * @covers     \theme_brandable\external\ask_js_review
 */
final class review_test extends \advanced_testcase {
    /** @var string A valid site key. */
    private const KEY = 'bsk_9fQxAbCdEfGhIjKlMnOpQrStUvWxYz0123456789-_A';

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
        $this->setAdminUser();
    }

    /**
     * A JavaScript change.
     *
     * @param string $slug Slug of the target.
     * @param string $body Body of the function.
     * @param array|null $scope Scope.
     * @param bool $deleted Whether the module is removed.
     * @return change
     */
    private function module(string $slug, string $body, ?array $scope = null, bool $deleted = false): change {
        return change::from_array(['type' => 'js', 'target' => "js/$slug", 'scope' => $scope, 'deleted' => $deleted,
            'content' => $deleted ? '' : "export default function (b) {\n  $body\n}\n",
            'compiled' => $deleted ? null : 'function(b){"use strict";' . $body . '}',
            'report' => $deleted ? null : ['analyzer' => '1', 'apis' => ['b.once'], 'findings' => [['line' => 2,
                'column' => 3, 'message' => 'A finding']]],
            'signature' => $deleted ? null : 'v1.testSignatureNotFromBrandable00']);
    }

    /**
     * Expects an exception of the plugin.
     *
     * @param string $code Error code.
     * @param callable $call Call.
     */
    private function expect_error(string $code, callable $call): void {
        try {
            $call();
            $this->fail("$code expected");
        } catch (\moodle_exception $e) {
            $this->assertSame($code, $e->errorcode);
        }
    }

    public function test_publishing_needs_a_review_of_each_changed_module(): void {
        global $DB, $USER;
        revision_manager::create([$this->module('welcome', 'b.once("w");'), change::from_array(['type' => 'scss',
            'target' => 'post/x', 'content' => '.x { color: red; }'])], 'p', 's');
        $changed = review::get_changed();
        $this->assertSame(['js/welcome'], array_column($changed, 'target'));
        $module = reset($changed);
        $this->assertNull($module['published']);
        $this->assertFalse($module['reviewed']);

        // Refused server side, whatever the editor shows.
        $this->expect_error('error:jsnotreviewed', fn() => revision_manager::publish('v1'));
        $this->expect_error('error:jsreviewchanged', fn() => review::record($module['key'], str_repeat('0', 64)));
        $this->expect_error('error:jsnoreview', fn() => review::record('js/other', $module['hash']));

        $export = mark_js_reviewed::execute($module['key'], $module['hash']);
        $export = \core_external\external_api::clean_returnvalue(mark_js_reviewed::execute_returns(), $export);
        $this->assertSame(0, $export['pending']);
        $this->assertTrue($export['modules'][0]['reviewed']);
        $this->assertSame(hash('sha256', $module['content']), $export['modules'][0]['hash']);
        $this->assertSame(['b.once'], $export['modules'][0]['apis']);
        $this->assertSame('A finding', $export['modules'][0]['findings'][0]['message']);
        $this->assertFalse($export['canaskbrandable']);
        $review = $DB->get_record('theme_brandable_jsreview', []);
        $this->assertSame([(int) $USER->id, 'js/welcome'], [(int) $review->userid, $review->target]);
        revision_manager::publish('v1');

        // Unchanged since the published version: nothing to review. Changed: reviewed again; removed: nothing.
        revision_manager::create([change::from_array(['type' => 'scss', 'target' => 'post/x',
            'content' => '.x { color: blue; }'])], 'p', 's');
        $this->assertSame([], review::get_changed());
        revision_manager::publish('v2');
        revision_manager::create([$this->module('welcome', 'b.once("w2");')], 'p', 's');
        $changed = review::get_changed();
        $this->assertSame("export default function (b) {\n  b.once(\"w\");\n}\n", reset($changed)['published']);
        $this->expect_error('error:jsnotreviewed', fn() => revision_manager::publish('v3'));
        revision_manager::create([$this->module('welcome', '', null, true)], 'p', 's');
        $this->assertSame([], review::get_changed());
        revision_manager::publish('v3');

        // The same code back: reviewed already (for its exact source and target).
        revision_manager::create([$this->module('welcome', 'b.once("w");')], 'p', 's');
        $this->assertTrue(array_values(review::get_changed())[0]['reviewed']);
        // Another target with the same source: not reviewed.
        revision_manager::create([$this->module('welcome-copy', 'b.once("w");')], 'p', 's');
        $pending = array_filter(review::get_changed(), fn($module) => !$module['reviewed']);
        $this->assertSame(['js/welcome-copy'], array_column($pending, 'target'));
    }

    public function test_only_those_managing_the_javascript_review(): void {
        revision_manager::create([$this->module('welcome', 'b.once("w");')], 'p', 's');
        $module = array_values(review::get_changed())[0];

        // An editor of the theme sees what is to review, cannot record it, nor publish.
        $editor = $this->getDataGenerator()->create_user();
        $role = $this->getDataGenerator()->create_role();
        assign_capability('theme/brandable:edit', CAP_ALLOW, $role, \context_system::instance());
        role_assign($role, $editor->id, \context_system::instance());
        $this->setUser($editor);
        $export = get_js_review::execute();
        $this->assertSame(1, $export['pending']);
        $this->assertFalse($export['canreview']);
        $calls = [
            fn() => mark_js_reviewed::execute($module['key'], $module['hash']),
            fn() => review::record($module['key'], $module['hash']),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('managejs is needed');
            } catch (\required_capability_exception $e) {
                $this->assertSame('nopermissions', $e->errorcode);
            }
        }
        $this->expect_error('error:jsnotreviewed', fn() => revision_manager::publish('v1', (int) $editor->id));

        // Reviewed by an administrator: the editor can publish.
        $this->setAdminUser();
        $this->assertTrue(get_js_review::execute()['canreview']);
        mark_js_reviewed::execute($module['key'], $module['hash']);
        $this->setUser($editor);
        $this->assertSame(0, get_js_review::execute()['pending']);
        revision_manager::publish('v1', (int) $editor->id);
        $this->assertSame([], review::get_changed());
    }

    public function test_each_scope_is_a_module_to_review(): void {
        revision_manager::create([$this->module('welcome', 'b.once("w");'),
            $this->module('welcome', 'b.once("p");', ['level' => 'page', 'value' => 'bp-my-index'])], 'p', 's');
        $export = get_js_review::export();
        $this->assertSame(2, $export['pending']);
        $this->assertCount(2, array_unique(array_column($export['modules'], 'key')));
        $this->assertStringContainsString('jsdownload.php?draft=1', $export['downloadurl']);
    }

    public function test_security_review_of_brandable(): void {
        connection::store_key(self::KEY);
        revision_manager::create([$this->module('welcome', 'b.once("w");')], 'p', 's');
        $key = array_key_first(review::get_changed());

        // Offered with the plan only.
        \curl::mock_response(json_encode(['plan' => ['id' => 'pro', 'limits' => ['jsReview' => true]]]));
        $this->assertTrue(review::can_ask_brandable());

        \curl::mock_response(json_encode(['report' => ['analyzer' => '2', 'apis' => ['b.once'], 'findings' => [],
            'errors' => [['line' => 2, 'message' => 'Stricter now']]], 'explanation' => 'Shows a window once.',
            'concerns' => ['Check the text.'], 'notice' => '']));
        $result = \core_external\external_api::clean_returnvalue(ask_js_review::execute_returns(), ask_js_review::execute($key));
        $this->assertSame('Shows a window once.', $result['explanation']);
        $this->assertSame(['Check the text.'], $result['concerns']);
        $this->assertSame('Stricter now', $result['errors'][0]['message']);
        // The notice is always there, an aid only.
        $this->assertSame(get_string('jsreview:defaultnotice', 'theme_brandable'), $result['notice']);
        // Never a review.
        $this->assertFalse(array_values(review::get_changed())[0]['reviewed']);

        \curl::mock_response(json_encode(['error' => ['code' => 'signature_invalid', 'message' => 'Not delivered by Brandable']]));
        $result = ask_js_review::execute($key);
        $this->assertSame(['signature_invalid', 'Not delivered by Brandable'], [$result['errorcode'], $result['errormessage']]);
    }

    public function test_download_of_the_javascript_of_a_version(): void {
        revision_manager::create([$this->module('welcome', 'b.once("w");'),
            $this->module('welcome', 'b.once("p");', ['level' => 'page', 'value' => 'bp-my-index'])], 'p', 's');
        foreach (review::get_changed() as $module) {
            review::record($module['key'], $module['hash']);
        }
        revision_manager::publish('v1');

        [$revisionid, $label] = jsdownload::find_revision(1, false);
        $this->assertSame('v1', $label);
        $files = jsdownload::files($revisionid, $label);
        $root = 'brandable-js-v1/';
        $this->assertSame("export default function (b) {\n  b.once(\"w\");\n}\n", $files[$root . 'welcome/source.js'][0]);
        $this->assertSame('function(b){"use strict";b.once("p");}', $files[$root . 'welcome--page-bp-my-index/compiled.js'][0]);
        $this->assertSame("v1.testSignatureNotFromBrandable00\n", $files[$root . 'welcome/signature.txt'][0]);
        $this->assertSame('1', json_decode($files[$root . 'welcome/report.json'][0], true)['analyzer']);
        $this->assertStringContainsString(review::hash($files[$root . 'welcome/source.js'][0]), $files[$root . 'README.txt'][0]);

        $path = make_request_directory() . '/js.zip';
        jsdownload::build($revisionid, $label, $path);
        $this->assertCount(9, get_file_packer('application/zip')->list_files($path));
        $this->expect_error('error:jsnoversion', fn() => jsdownload::find_revision(7, false));

        // Offered under the version in the thread.
        $versions = editor_state::export()['versions'];
        $this->assertStringContainsString('jsdownload.php?version=1', $versions[0]['jsdownloadurl']);
    }
}
