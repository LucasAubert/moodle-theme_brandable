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

use theme_brandable\local\generator\factory;

#[\PHPUnit\Framework\Attributes\CoversClass(connection::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(factory::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\theme_brandable\local\hook_callbacks::class)]
/**
 * Tests for the connection of the site to a Brandable account (key, PKCE flow, dev mode).
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\account\connection
 * @covers     \theme_brandable\local\generator\factory
 * @covers     \theme_brandable\local\hook_callbacks
 */
final class connection_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    public function test_site_key_is_stored_and_forgotten(): void {
        $this->assertFalse(connection::is_connected());
        $this->assertFalse(connection::is_ready());
        $this->assertSame('', connection::get_key());

        connection::store_key('site-key-1');
        $this->assertTrue(connection::is_connected());
        $this->assertTrue(connection::is_ready());
        $this->assertSame('site-key-1', connection::get_key());

        connection::forget();
        $this->assertFalse(connection::is_connected());
        $this->assertFalse(get_config('theme_brandable', connection::KEY_CONFIG));

        $this->expectException(\coding_exception::class);
        connection::store_key(' ');
    }

    public function test_headers_identify_the_site(): void {
        global $CFG;

        connection::store_key('site-key-1');
        $this->assertSame([
            'Authorization: Bearer site-key-1',
            'X-Brandable-Site-Url: ' . $CFG->wwwroot,
            'X-Brandable-Site-Id: ' . hash('sha256', $CFG->siteidentifier),
            'Accept-Language: en',
        ], connection::get_headers());
        // The site identifier never leaves the site as is.
        $this->assertStringNotContainsString(get_site_identifier(), implode("\n", connection::get_headers()));
        $this->assertSame('Authorization: Bearer other', connection::get_headers('other')[0]);
    }

    public function test_manual_settings_need_dev_mode(): void {
        global $CFG;

        set_config('brandable_apiurl', 'http://localhost:3000/', 'theme_brandable');
        set_config('brandable_apikey', 'manual-key', 'theme_brandable');
        set_config(factory::SETTING, 'mock', 'theme_brandable');
        connection::store_key('site-key-1');

        // Without dev mode: the site key, the production URL and the Brandable API only.
        $this->assertSame('site-key-1', connection::get_key());
        $this->assertSame('https://app.brandablelms.com/api/v1', connection::get_api_url());
        $this->assertSame(connection::BASE_URL, connection::get_web_url());
        $this->assertSame('remote', factory::get_name());

        $CFG->theme_brandable_devmode = true;
        $this->assertSame('manual-key', connection::get_key());
        $this->assertSame('http://localhost:3000', connection::get_api_url());
        set_config('brandable_weburl', 'http://localhost:3001', 'theme_brandable');
        $this->assertSame('http://localhost:3001', connection::get_web_url());
        // No API URL for the server: the web app URL followed by /api/v1.
        unset_config('brandable_apiurl', 'theme_brandable');
        $this->assertSame('http://localhost:3001/api/v1', connection::get_api_url());
        $this->assertSame('mock', factory::get_name());

        // Dev mode without a key: ready with the demo generator only.
        connection::forget();
        unset_config('brandable_apikey', 'theme_brandable');
        $this->assertTrue(connection::is_ready());
        set_config(factory::SETTING, 'remote', 'theme_brandable');
        $this->assertFalse(connection::is_ready());
    }

    public function test_flow_checks_the_state_once(): void {
        $flow = connection::start_flow();
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $flow['state']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $flow['codechallenge']);

        $verifier = connection::finish_flow($flow['state']);
        $this->assertNotNull($verifier);
        // RFC 7636: 43 to 128 characters of the unreserved alphabet, S256 challenge.
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._~-]{43,128}$/', $verifier);
        $this->assertSame($flow['codechallenge'], connection::code_challenge($verifier));
        // Single use.
        $this->assertNull(connection::finish_flow($flow['state']));
    }

    public function test_flow_refuses_a_wrong_or_expired_state(): void {
        global $SESSION;

        $this->assertNull(connection::finish_flow('anything'));

        $flow = connection::start_flow();
        $this->assertNull(connection::finish_flow($flow['state'] . 'x'));
        // A wrong state ends the flow too.
        $this->assertNull(connection::finish_flow($flow['state']));

        $flow = connection::start_flow();
        $this->assertNull(connection::finish_flow(''));

        $flow = connection::start_flow();
        $SESSION->theme_brandable_connect->expires = time() - 1;
        $this->assertNull(connection::finish_flow($flow['state']));

        // Each flow has its own state and verifier.
        $this->assertNotSame(connection::start_flow()['state'], connection::start_flow()['state']);
    }

    public function test_known_pkce_challenge(): void {
        // RFC 7636, appendix B.
        $this->assertSame(
            'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            connection::code_challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk')
        );
    }

    public function test_connect_url_follows_the_contract(): void {
        global $CFG;

        $flow = connection::start_flow();
        $url = connection::get_connect_url($flow);
        $this->assertSame('https://app.brandablelms.com/connect', $url->out_omit_querystring());
        $this->assertSame($CFG->wwwroot, $url->get_param('siteUrl'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $url->get_param('siteId'));
        $this->assertMatchesRegularExpression('/^\d+\.\d+(\.\d+)?$/', $url->get_param('moodleVersion'));
        $this->assertSame($CFG->wwwroot . '/theme/brandable/connect_callback.php', $url->get_param('callback'));
        $this->assertSame($flow['state'], $url->get_param('state'));
        $this->assertSame($flow['codechallenge'], $url->get_param('codeChallenge'));
        $this->assertSame('S256', $url->get_param('codeChallengeMethod'));
        $this->assertSame('en', $url->get_param('lang'));
        $this->assertNull($url->get_param('relink'));
        $this->assertNull($url->get_param('country'));

        // Default country of the site, for the billing currency.
        $CFG->country = 'fr';
        $this->assertSame('FR', connection::get_connect_url($flow)->get_param('country'));
        $CFG->country = 'France';
        $this->assertNull(connection::get_connect_url($flow)->get_param('country'));
        // No default country: the one in the profile of the administrator who connects the site.
        $CFG->country = '';
        $this->setUser($this->getDataGenerator()->create_user(['country' => 'BE']));
        $this->assertSame('BE', connection::get_connect_url($flow)->get_param('country'));
        $CFG->country = 'FR';
        $this->assertSame('FR', connection::get_connect_url($flow)->get_param('country'));
        $this->setAdminUser();

        // Relink: the environment the site was linked to.
        connection::store_key('bsk_' . str_repeat('a', 43), ['environment' => ['id' => 'env-1', 'kind' => 'prod']]);
        connection::forget();
        $this->assertSame('env-1', connection::get_environment_id());
        $this->assertSame('env-1', connection::get_connect_url($flow, true)->get_param('relink'));

        // Dev mode: the web app URL opened by the browser.
        $CFG->theme_brandable_devmode = true;
        set_config('brandable_weburl', 'http://localhost:3000/', 'theme_brandable');
        $this->assertSame('http://localhost:3000/connect', connection::get_connect_url($flow)->out_omit_querystring());
    }

    public function test_language_is_sent(): void {
        $this->assertContains('Accept-Language: en', connection::get_headers('k'));
        $this->assertSame('pt-BR', connection::get_lang('pt_br'));
        $this->assertSame('es-MX', connection::get_lang('es_mx'));
        $this->assertSame('fr', connection::get_lang('fr'));
    }

    /**
     * Runs the banner hook on a page and returns the notifications it added.
     *
     * @param string $layout Page layout.
     * @param string $url Page URL.
     * @return array
     */
    private function banner(string $layout, string $url = '/admin/search.php'): array {
        global $PAGE;

        $PAGE = new \moodle_page();
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_url($url);
        $PAGE->set_pagelayout($layout);
        \core\notification::fetch();
        // The hook itself does nothing in CLI scripts (PHPUnit included).
        \theme_brandable\local\hook_callbacks::remind_connection();
        return \core\notification::fetch();
    }

    public function test_admin_pages_remind_that_the_site_is_not_connected(): void {
        $notifications = $this->banner('admin');
        $this->assertCount(1, $notifications);
        $this->assertStringContainsString('/theme/brandable/connect.php', $notifications[0]->get_message());
        $this->assertSame('warning', $notifications[0]->get_message_type());

        // Not on the other pages, not for the other users, not once connected.
        $this->assertSame([], $this->banner('standard'));
        $this->assertSame([], $this->banner('admin', '/theme/brandable/connect.php'));
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertSame([], $this->banner('admin'));
        $this->setAdminUser();
        connection::store_key('site-key-1');
        $this->assertSame([], $this->banner('admin'));
    }

    public function test_editor_shows_the_connection_screen(): void {
        global $PAGE;

        $context = ['previewurl' => '/', 'wwwroot' => 'https://example.com', 'exiturl' => '/', 'settingsurl' => '/',
            'generatoravailable' => true, 'generatorname' => 'x', 'markers' => true, 'user' => '{}',
            'connecturl' => 'https://example.com/theme/brandable/connect.php', 'sesskey' => 'abc'];
        $output = $PAGE->get_renderer('core');
        $html = $output->render_from_template('theme_brandable/editor', $context + ['connected' => false, 'canconnect' => true]);
        $this->assertStringContainsString('data-region="connect"', $html);
        // The free account put forward, a single button starting the connection flow.
        $this->assertStringContainsString(get_string('connect:freetitle', 'theme_brandable'), $html);
        $this->assertStringContainsString('action="https://example.com/theme/brandable/connect.php"', $html);
        $this->assertStringContainsString('name="action" value="start"', $html);
        $this->assertStringContainsString(get_string('connect:startfree', 'theme_brandable'), $html);
        $this->assertMatchesRegularExpression('/data-region="composer"[^>]*hidden/', $html);

        $html = $output->render_from_template('theme_brandable/editor', $context + ['connected' => false, 'canconnect' => false]);
        $this->assertStringNotContainsString('action="https://example.com/theme/brandable/connect.php"', $html);
        $this->assertStringContainsString(get_string('connect:askadmin', 'theme_brandable'), $html);

        $html = $output->render_from_template('theme_brandable/editor', $context + ['connected' => true, 'canconnect' => true]);
        $this->assertMatchesRegularExpression('/data-region="connect"[^>]*hidden/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-region="composer"[^>]*hidden/', $html);
    }
}
