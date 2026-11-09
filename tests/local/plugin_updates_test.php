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

namespace theme_brandable\local;

use theme_brandable\external\dismiss_plugin_update;
use theme_brandable\local\account\api_client;
use theme_brandable\local\account\api_exception;
use theme_brandable\local\account\connection;
use theme_brandable\local\generator\generation_manager;
use theme_brandable\local\generator\generation_result;

/**
 * Tests of the identity of the plugin and of its updates (spec docs/SPEC-plugin-updates.md, contract 15).
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\plugin_updates
 * @covers     \theme_brandable\local\plugin_info
 * @covers     \theme_brandable\local\generator\generation_manager
 */
#[\PHPUnit\Framework\Attributes\CoversClass(plugin_updates::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(plugin_info::class)]
final class plugin_updates_test extends \advanced_testcase {
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
        plugin_info::reset();
        set_config(connection::KEY_CONFIG, 'bsk_test', 'theme_brandable');
    }

    /**
     * A client of a test API (answers simulated with \curl::mock_response).
     *
     * @return api_client
     */
    private function client(): api_client {
        return new api_client('https://api.example.com/api/v1', 'bsk_test');
    }

    /**
     * Simulates the next answer of the API.
     *
     * @param array $body JSON body.
     */
    private function answer(array $body): void {
        \curl::mock_response(json_encode($body));
    }

    /**
     * An answer of GET /plugin/latest (contract 15.6).
     *
     * @param array $fields Fields replaced.
     * @return array
     */
    private function latest(array $fields = []): array {
        global $CFG;
        return array_replace([
            'release' => '9.0.0', 'version' => 2099010100, 'publishedAt' => '2099-01-01T00:00:00.000Z',
            'maturity' => 'MATURITY_BETA',
            'moodle' => ['supported' => [405, (int) $CFG->branch], 'label' => '4.5 – x', 'compatibleWithThisSite' => true],
            'notes' => ['lang' => 'en', 'markdown' => "- One\n- Two"], 'updateAvailable' => true, 'minimumVersion' => null,
            'installUrl' => 'https://app.example.com/dashboard/install', 'unknownfield' => ['ignored' => true],
        ], $fields);
    }

    public function test_headers_of_every_request(): void {
        global $CFG, $SESSION;
        $headers = plugin_info::headers();
        $this->assertSame('X-Brandable-Plugin-Version: ' . get_config('theme_brandable', 'version'), $headers[0]);
        $this->assertMatchesRegularExpression('~^X-Brandable-Plugin-Version: \d{10}$~', $headers[0]);
        $this->assertMatchesRegularExpression('~^X-Brandable-Plugin-Release: \d+\.\d+\.\d+$~', $headers[1]);
        preg_match('~^\d+\.\d+~', $CFG->release, $moodle);
        $this->assertStringStartsWith('X-Brandable-Moodle-Version: ' . $moodle[0], $headers[2]);
        $this->assertSame(
            'X-Brandable-Capabilities: scss-chunks,templates,scope-cm,js,assets,fonts,cloud,brandable-dark',
            $headers[3]
        );

        // Sent by the client with or without the site key.
        $method = new \ReflectionMethod(api_client::class, 'headers');
        $authenticated = $method->invoke($this->client(), true, ['Accept: application/json']);
        $this->assertContains('Authorization: Bearer bsk_test', $authenticated);
        $this->assertSame(
            array_merge(connection::get_headers('bsk_test'), $headers, ['Accept-Language: en'], ['Accept: application/json']),
            $authenticated
        );
        $anonymous = $method->invoke($this->client(), false, []);
        $this->assertSame(array_merge($headers, ['Accept-Language: en']), $anonymous);
        // Language of the user (messages of the errors), fr_ca -> fr-ca.
        $SESSION->forcelang = 'fr_ca';
        $this->assertContains('Accept-Language: fr-ca', $method->invoke($this->client(), false, []));
        unset($SESSION->forcelang);

        $this->assertSame(['template-patch'], plugin_info::missing(['js', 'template-patch', 'cloud', 'Bad value']));
        $this->assertSame([], plugin_info::missing(['brandable-dark']));
    }

    public function test_upgrade_required(): void {
        $this->assertNull(plugin_updates::required());
        $this->answer(['error' => ['code' => 'plugin_upgrade_required', 'message' => 'Update the plugin.',
            'url' => 'https://app.example.com/dashboard/install',
            'plugin' => ['minimumVersion' => 2099010100, 'latest' => ['release' => '9.0.0', 'version' => 2099010100]]]]);
        try {
            $this->client()->request('POST', '/generations', ['prompt' => 'x']);
            $this->fail('426 expected');
        } catch (api_exception $e) {
            $this->assertSame('plugin_upgrade_required', $e->apicode);
        }
        $required = plugin_updates::required();
        $this->assertSame(2099010100, $required['minimumversion']);
        $this->assertSame('9.0.0', $required['latestrelease']);
        $this->assertSame('Update the plugin.', $required['message']);

        // Screen of the editor: the link for the administrators, the request to them for the others.
        $screen = plugin_updates::upgrade_screen();
        $this->assertTrue($screen['canupdate']);
        $this->assertSame('https://app.example.com/dashboard/install', $screen['url']);
        $this->assertTrue($screen['haslatest']);
        $this->setUser($this->getDataGenerator()->create_user());
        $screen = plugin_updates::upgrade_screen();
        $this->assertFalse($screen['canupdate']);
        $this->assertSame('', $screen['url']);
        $this->setAdminUser();

        // GET /account is never refused: its answer does not clear the state.
        $this->answer(['site' => []]);
        $this->client()->request('GET', '/account');
        $this->assertNotNull(plugin_updates::required());
        // A route that can refuse went through: cleared.
        $this->answer(['status' => 'ok']);
        $this->client()->request('GET', '/cloud/state');
        $this->assertNull(plugin_updates::required());

        // Recorded by another version of the code: no longer true once the plugin is updated.
        plugin_updates::record_required(new api_exception(
            'plugin_upgrade_required',
            'Old.',
            '',
            426,
            ['minimumVersion' => 2099010100]
        ));
        $this->assertNotNull(plugin_updates::required());
        $recorded = json_decode(get_config('theme_brandable', plugin_updates::REQUIRED_CONFIG), true);
        set_config(plugin_updates::REQUIRED_CONFIG, json_encode(['version' => 2000010100] + $recorded), 'theme_brandable');
        $this->assertNull(plugin_updates::required());
    }

    public function test_latest_version(): void {
        // Not connected: never asked.
        unset_config(connection::KEY_CONFIG, 'theme_brandable');
        $this->assertNull(plugin_updates::check($this->client()));
        set_config(connection::KEY_CONFIG, 'bsk_test', 'theme_brandable');

        $this->answer($this->latest());
        $latest = plugin_updates::check($this->client());
        $this->assertSame('9.0.0', $latest['release']);
        $this->assertSame(2099010100, $latest['version']);
        $this->assertSame('MATURITY_BETA', $latest['maturity']);
        $this->assertFalse($latest['security']);
        $this->assertFalse($latest['directory']);
        $this->assertSame(2099010100, plugin_updates::available()['version']);
        $this->assertNull(plugin_updates::required());

        // Not compatible with this Moodle, not newer, out of the range supported: nothing offered.
        foreach (
            [
                ['moodle' => ['supported' => [405, 503], 'label' => '', 'compatibleWithThisSite' => false]],
                ['updateAvailable' => false],
                ['version' => (int) get_config('theme_brandable', 'version')],
                ['moodle' => ['supported' => [300, 301], 'label' => '', 'compatibleWithThisSite' => true]],
            ] as $fields
        ) {
            $this->answer($this->latest($fields));
            plugin_updates::check($this->client());
            $this->assertNull(plugin_updates::available(), json_encode($fields));
        }

        // A minimum above the version installed: required before any request is refused.
        $this->answer($this->latest(['minimumVersion' => 2099010100]));
        plugin_updates::check($this->client());
        $required = plugin_updates::required();
        $this->assertSame(2099010100, $required['minimumversion']);
        $this->assertSame('https://app.example.com/dashboard/install', $required['url']);

        // No version published.
        $this->answer(['error' => ['code' => 'not_found', 'message' => 'None.']]);
        $this->assertNull(plugin_updates::check($this->client()));
        $this->assertNull(plugin_updates::latest());
    }

    public function test_notifications(): void {
        $sink = $this->redirectMessages();
        $this->answer($this->latest());
        plugin_updates::check($this->client());
        $this->assertSame(1, plugin_updates::notify());
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame('pluginupdate', $messages[0]->eventtype);
        $this->assertStringContainsString('9.0.0', $messages[0]->subject);
        // Once per version.
        $this->assertSame(0, plugin_updates::notify());

        // Security update: every week until installed.
        $this->answer($this->latest(['security' => true]));
        plugin_updates::check($this->client());
        $this->assertSame(0, plugin_updates::notify());
        set_config(plugin_updates::NOTIFIEDAT_CONFIG, time() - WEEKSECS - 1, 'theme_brandable');
        $this->assertSame(1, plugin_updates::notify());
        $this->assertSame(0, plugin_updates::notify());

        // On Moodle Marketplace, a beta release: Moodle does not announce it (stable releases by default), the plugin does.
        $this->answer($this->latest(['version' => 2099020100, 'directory' => true]));
        plugin_updates::check($this->client());
        $this->assertSame(1, plugin_updates::notify());
        // A stable release: Moodle notifies, the plugin does not.
        $this->answer($this->latest(['version' => 2099030100, 'directory' => true, 'maturity' => 'MATURITY_STABLE']));
        plugin_updates::check($this->client());
        $this->assertTrue(plugin_updates::moodle_notifies(plugin_updates::latest()));
        $this->assertSame(0, plugin_updates::notify());
        // Unless Moodle does not look for updates, or does not notify them.
        set_config('updateautocheck', 0);
        $this->assertFalse(plugin_updates::moodle_notifies(plugin_updates::latest()));
        set_config('updateautocheck', 1);
        set_config('disableupdatenotifications', 1);
        $this->assertFalse(plugin_updates::moodle_notifies(plugin_updates::latest()));
        set_config('disableupdatenotifications', 0);
        // Beta releases accepted by the site: Moodle notifies.
        set_config('updateminmaturity', MATURITY_BETA);
        $this->assertTrue(plugin_updates::moodle_notifies(['maturity' => 'MATURITY_BETA', 'directory' => true]));
        $this->assertFalse(plugin_updates::moodle_notifies(['maturity' => 'MATURITY_ALPHA', 'directory' => true]));
        $this->assertFalse(plugin_updates::moodle_notifies(['maturity' => 'bad', 'directory' => true]));
        set_config('updateminmaturity', MATURITY_STABLE);

        // Required by Brandable: once per minimum version.
        $sink->clear();
        $this->answer($this->latest(['minimumVersion' => 2099010100]));
        plugin_updates::check($this->client());
        $this->assertSame(1, plugin_updates::notify());
        $this->assertSame(0, plugin_updates::notify());
        $this->assertStringContainsString(
            get_string('pluginupdate:requiredsubject', 'theme_brandable'),
            $sink->get_messages()[0]->subject
        );
        $sink->close();
    }

    public function test_banner(): void {
        $this->answer($this->latest());
        plugin_updates::check($this->client());
        $banner = plugin_updates::banner();
        $this->assertSame('9.0.0', $banner['release']);
        $this->assertTrue($banner['dismissible']);
        $this->assertStringContainsString('<li>One</li>', $banner['notes']);
        $this->assertSame('https://app.example.com/dashboard/install', $banner['url']);

        // Administrators of the site only.
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertNull(plugin_updates::banner());
        $this->setAdminUser();

        // Closed for this version.
        $this->assertTrue(dismiss_plugin_update::execute(2099010100)['dismissed']);
        $this->assertNull(plugin_updates::banner());
        $this->assertFalse(dismiss_plugin_update::execute(2099010101)['dismissed']);

        // A security update cannot be closed.
        $this->answer($this->latest(['security' => true]));
        plugin_updates::check($this->client());
        $banner = plugin_updates::banner();
        $this->assertTrue($banner['security']);
        $this->assertFalse($banner['dismissible']);
        $this->assertFalse(dismiss_plugin_update::execute(2099010100)['dismissed']);

        // On Moodle Marketplace and announced by Moodle (stable): the update page of Moodle.
        $this->answer($this->latest(['version' => 2099020100, 'directory' => true, 'maturity' => 'MATURITY_STABLE']));
        plugin_updates::check($this->client());
        $this->assertStringContainsString('/admin/plugins.php?updatesonly=1', plugin_updates::banner()['url']);
        // A beta release, not listed by Moodle on this site: the page of Brandable.
        $this->answer($this->latest(['version' => 2099030100, 'directory' => true]));
        plugin_updates::check($this->client());
        $this->assertSame('https://app.example.com/dashboard/install', plugin_updates::banner()['url']);
    }

    public function test_needs_plugin_upgrade(): void {
        $result = generation_result::from_array(['summary' => 'Update the plugin.', 'changes' => [],
            'needsPluginUpgrade' => ['capabilities' => ['js'], 'url' => 'https://app.example.com/dashboard/install']]);
        $this->assertSame(
            ['capabilities' => ['js'], 'url' => 'https://app.example.com/dashboard/install'],
            $result->pluginupgrade
        );
        $this->assertSame($result->pluginupgrade, $result->to_array()['needsPluginUpgrade']);
        $this->assertNull(generation_result::from_array(['summary' => 'x', 'changes' => []])->pluginupgrade);
    }

    public function test_generation_offers_the_update(): void {
        set_config('brandable_generator', 'remote', 'theme_brandable');
        // Answers popped last first: the result, then the start.
        $this->answer(['status' => 'done', 'events' => [], 'result' => ['summary' => 'This needs JavaScript: update.',
            'changes' => [], 'needsClarification' => false,
            'needsPluginUpgrade' => ['capabilities' => ['js'], 'url' => 'https://app.example.com/dashboard/install']]]);
        $this->answer(['generationId' => '8b0b4a52-3b1f-4c3e-9a5e-1f2a3b4c5d6e']);
        $record = generation_manager::submit('Add a welcome popup');
        $record = generation_manager::refresh($record->id);
        $export = generation_manager::export($record);
        $this->assertSame(generation_manager::FAILED, $export['status']);
        $this->assertSame('upgrade', $export['action']);
        $this->assertSame('This needs JavaScript: update.', $export['errormessage']);
        $this->assertSame('https://app.example.com/dashboard/install', $export['errorurl']);
        $this->assertTrue($export['retryable']);
        $this->assertEmpty($record->revisionid);

        // Refused with 426: same offer, and the editor shows the screen of the update.
        $this->answer(['error' => ['code' => 'plugin_upgrade_required', 'message' => 'Update the plugin.',
            'url' => 'https://app.example.com/dashboard/install', 'plugin' => ['minimumVersion' => 2099010100, 'latest' => null]]]);
        $export = generation_manager::export(generation_manager::submit('Make it blue'));
        $this->assertSame('upgrade', $export['action']);
        $this->assertSame('Update the plugin.', $export['errormessage']);
        $state = \theme_brandable\external\editor_state::export();
        $this->assertSame('Update the plugin.', $state['pluginupgrade']['message']);
        $this->assertFalse($state['pluginupgrade']['haslatest']);
    }
}
