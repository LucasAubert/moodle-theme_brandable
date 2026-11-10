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

use theme_brandable\external\editor_state;
use theme_brandable\external\get_js_consent;
use theme_brandable\external\resend_generation;
use theme_brandable\external\set_js_consent;
use theme_brandable\external\set_js_suspended;
use theme_brandable\local\account\api_exception;
use theme_brandable\local\account\connection;
use theme_brandable\local\generator\generation_context;
use theme_brandable\local\generator\generation_manager;
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\resolver;
use theme_brandable\local\overrides\revision_manager;

#[\PHPUnit\Framework\Attributes\CoversClass(consent::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(generation_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\theme_brandable\local\generator\mock_generator::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(set_js_consent::class)]
/**
 * Tests for the activation of the generated JavaScript (contract 12.7, spec B1 to B4): consent, 409, deactivation,
 * kill switch, notification of a new text, needsJsActivation and the request sent again.
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\js\consent
 * @covers     \theme_brandable\local\generator\generation_manager
 * @covers     \theme_brandable\local\generator\mock_generator
 * @covers     \theme_brandable\external\set_js_consent
 */
final class consent_test extends \advanced_testcase {
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
     * Turns the demo generator on (dev mode).
     */
    private function use_mock(): void {
        global $CFG;
        $CFG->theme_brandable_devmode = true;
        set_config('brandable_generator', 'mock', 'theme_brandable');
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
     * An answer of GET /account with the text of the consent.
     *
     * @param string $version Version of the text.
     * @return array
     */
    private function account(string $version): array {
        return ['organization' => ['id' => 'o1', 'name' => 'Université X'],
            'js' => ['consentVersion' => $version, 'consentText' => "Premier paragraphe.\n\nSecond paragraphe."]];
    }

    public function test_needs_activation_then_sent_again_once_on(): void {
        global $DB;
        $this->use_mock();

        // JavaScript off: the request needing it stops, nothing generated, and says so in the request.
        $this->assertFalse(generation_context::build()->to_request('x')['jsEnabled']);
        $this->assertArrayNotHasKey('jsConsentVersion', generation_context::build()->to_request('x'));
        $record = generation_manager::submit('Ajoute une fenêtre de bienvenue en rouge');
        $record = generation_manager::refresh($record->id);
        $this->assertSame(generation_manager::JSACTIVATION, $record->status);
        $this->assertSame(get_string('mock:needsjs', 'theme_brandable'), $record->summary);
        $this->assertEmpty($record->revisionid);
        $this->assertSame(0, (int) $record->repairs);
        $this->assertSame(0, revision_manager::get_draft());

        // Not sent again while off.
        try {
            generation_manager::resend($record->id);
            $this->fail('The JavaScript is off');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:jsnotenabled', $e->errorcode);
        }

        // Another version than the text shown: the current text, nothing recorded.
        $current = consent::enable('mock-0');
        $this->assertSame(consent::MOCK_VERSION, $current['version']);
        $this->assertFalse(modules::is_enabled());
        $this->assertSame(0, $DB->count_records('theme_brandable_jsconsent'));

        // Consent, then the request sent again: same prompt, the module generated with its signature.
        $result = set_js_consent::execute(true, consent::MOCK_VERSION, (int) $record->id);
        $result = \core_external\external_api::clean_returnvalue(set_js_consent::execute_returns(), $result);
        $this->assertSame('enabled', $result['status']);
        $this->assertTrue(modules::is_enabled());
        $this->assertSame(consent::MOCK_VERSION, consent::get_version());
        $this->assertSame(1, $DB->count_records('theme_brandable_jsconsent', ['enabled' => 1, 'version' => consent::MOCK_VERSION]));
        $this->assertSame('Ajoute une fenêtre de bienvenue en rouge', $result['generation']['prompt']);
        $this->assertSame(
            generation_manager::JSRESENT,
            $DB->get_field('theme_brandable_generation', 'status', ['id' => $record->id])
        );

        $request = generation_context::build()->to_request('x');
        $this->assertTrue($request['jsEnabled']);
        $this->assertSame(consent::MOCK_VERSION, $request['jsConsentVersion']);

        $new = generation_manager::refresh($result['generation']['id']);
        $this->assertSame(generation_manager::COMPLETED, $new->status);
        $map = resolver::get_map((int) $new->revisionid);
        $module = $map[change::TYPE_JS]['js/welcome-modal'] ?? null;
        $this->assertNotNull($module);
        $this->assertMatchesRegularExpression(modules::SIGNATURE_PATTERN, $module['signature']);
        $this->assertStringStartsWith('function(', $module['compiled']);
        // The rest of the prompt too (red).
        $this->assertArrayHasKey('pre/brand-colors', $map[change::TYPE_SCSS]);

        // Sent once only.
        try {
            resend_generation::execute($record->id);
            $this->fail('Already sent again');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:cannotresend', $e->errorcode);
        }
    }

    public function test_editor_state_and_thread(): void {
        $this->use_mock();
        $record = generation_manager::refresh(generation_manager::submit('Welcome window')->id);
        $state = \core_external\external_api::clean_returnvalue(editor_state::structure(), editor_state::export());
        $this->assertSame(['enabled' => false, 'suspended' => false, 'canmanage' => true], $state['js']);
        $this->assertSame('jsactivation', $state['generations'][0]['status']);
        $this->assertSame(get_string('mock:needsjs', 'theme_brandable'), $state['generations'][0]['summary']);
        $this->assertSame((int) $record->id, $state['generations'][0]['id']);

        // A manager without the capability (not a site administrator).
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse(editor_state::export()['js']['canmanage']);
    }

    public function test_consent_with_brandable(): void {
        global $DB;
        connection::store_key(self::KEY);
        $this->assertFalse(consent::is_mock());

        $this->answer($this->account('2026-11-02'));
        $terms = consent::get_terms(true);
        $this->assertSame(['version' => '2026-11-02', 'text' => "Premier paragraphe.\n\nSecond paragraphe."], $terms);

        // The text changed meanwhile (409): the current one, nothing recorded here. The simulated answers are a
        // stack: the last one comes first.
        $this->answer($this->account('2026-12-01'));
        $this->answer(['error' => ['code' => 'conflict', 'message' => 'Version is not the current one']]);
        $current = consent::enable('2026-11-02');
        $this->assertSame('2026-12-01', $current['version']);
        $this->assertFalse(modules::is_enabled());
        $this->assertSame(0, $DB->count_records('theme_brandable_jsconsent'));

        $this->answer(['recordedAt' => '2026-12-01T10:00:00Z', 'enabled' => true, 'version' => '2026-12-01']);
        $this->assertNull(consent::enable('2026-12-01'));
        $this->assertTrue(modules::is_enabled());
        $this->assertSame('2026-12-01', consent::get_version());

        // Other errors are reported, nothing changes.
        $this->answer(['error' => ['code' => 'rate_limited', 'message' => 'Too many calls']]);
        try {
            consent::enable('2026-12-01');
            $this->fail('The error must be reported');
        } catch (api_exception $e) {
            $this->assertSame('rate_limited', $e->apicode);
        }
        $this->assertSame(1, $DB->count_records('theme_brandable_jsconsent'));

        // Turned off even when Brandable cannot be told.
        $this->answer(['error' => ['code' => 'internal_error', 'message' => 'Down']]);
        $this->assertFalse(consent::disable());
        $this->assertFalse(modules::is_enabled());
        $history = consent::get_history();
        $this->assertCount(2, $history);
        $this->assertFalse($history[0]['enabled']);
        $this->assertSame('2026-12-01', $history[0]['version']);
        $this->assertSame(fullname(get_admin()), $history[0]['user']);
        $log = $DB->get_records('config_log', ['plugin' => 'theme_brandable', 'name' => modules::ENABLED_CONFIG], 'id DESC');
        $this->assertSame(['0', '1'], [reset($log)->value, reset($log)->oldvalue]);
    }

    public function test_new_version_is_notified_once(): void {
        connection::store_key(self::KEY);
        $this->answer(['recordedAt' => '2026-11-02T10:00:00Z', 'enabled' => true, 'version' => '2026-11-02']);
        consent::enable('2026-11-02');
        $manager = $this->getDataGenerator()->create_user();
        $role = $this->getDataGenerator()->create_role();
        assign_capability(consent::CAPABILITY, CAP_ALLOW, $role, \context_system::instance());
        role_assign($role, $manager->id, \context_system::instance());

        $sink = $this->redirectMessages();
        $this->assertFalse(consent::check_version($this->account('2026-11-02')));
        // Read with the account: the administrators and the managers are told.
        $this->answer($this->account('2026-12-01'));
        \theme_brandable\local\account\account::get(true);
        $messages = $sink->get_messages();
        $this->assertEqualsCanonicalizing([(int) get_admin()->id, (int) $manager->id], array_map(
            fn($message) => (int) $message->useridto,
            $messages
        ));
        $this->assertSame('jsconsent', $messages[0]->eventtype);
        $sink->clear();
        $this->assertFalse(consent::check_version($this->account('2026-12-01')));
        $this->assertCount(0, $sink->get_messages());
        // The modules published keep running.
        $this->assertTrue(modules::is_enabled());

        // Accepted, then another text: told again.
        $this->answer(['recordedAt' => '2026-12-01T10:00:00Z', 'enabled' => true, 'version' => '2026-12-01']);
        consent::enable('2026-12-01');
        $this->assertFalse(consent::check_version($this->account('2026-12-01')));
        $this->assertTrue(consent::check_version($this->account('2027-01-01')));

        // Nothing while the JavaScript is off.
        $this->answer(['recordedAt' => '2027-01-01T10:00:00Z', 'enabled' => false, 'version' => '2026-12-01']);
        consent::disable();
        $this->assertFalse(consent::check_version($this->account('2027-02-01')));
        $sink->close();
    }

    public function test_kill_switch_and_capability(): void {
        global $DB;
        $this->use_mock();
        $this->assertSame(['suspended' => true], set_js_suspended::execute(true));
        $this->assertTrue(modules::is_suspended());
        $log = $DB->get_records('config_log', ['plugin' => 'theme_brandable', 'name' => modules::SUSPENDED_CONFIG]);
        $this->assertSame(['1'], array_values(array_map(fn($entry) => $entry->value, $log)));
        set_js_suspended::execute(false);
        $this->assertFalse(modules::is_suspended());

        $consent = get_js_consent::execute();
        $this->assertSame(consent::MOCK_VERSION, $consent['version']);
        $this->assertFalse($consent['current']);

        // Only those managing the JavaScript (not an editor of the theme).
        $editor = $this->getDataGenerator()->create_user();
        $role = $this->getDataGenerator()->create_role();
        assign_capability('theme/brandable:edit', CAP_ALLOW, $role, \context_system::instance());
        role_assign($role, $editor->id, \context_system::instance());
        $this->setUser($editor);
        $calls = [
            fn() => set_js_suspended::execute(true),
            fn() => set_js_consent::execute(true, consent::MOCK_VERSION),
            fn() => get_js_consent::execute(),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('managejs is needed');
            } catch (\required_capability_exception $e) {
                $this->assertSame('nopermissions', $e->errorcode);
                $this->assertStringContainsString(get_string('brandable:managejs', 'theme_brandable'), $e->getMessage());
            }
        }
        $this->assertFalse(modules::is_suspended());
        $this->assertFalse(modules::is_enabled());
    }
}
