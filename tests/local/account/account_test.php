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

use theme_brandable\external\get_account;

#[\PHPUnit\Framework\Attributes\CoversClass(account::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(api_client::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(get_account::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\theme_brandable\external\billing_checkout::class)]
/**
 * Tests for the Brandable account of the site, against simulated API answers (contract v1).
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\account\account
 * @covers     \theme_brandable\local\account\api_client
 * @covers     \theme_brandable\external\get_account
 * @covers     \theme_brandable\external\billing_checkout
 */
final class account_test extends \advanced_testcase {
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
     * Simulates the next answer of the API.
     *
     * @param array $body JSON body.
     */
    private function answer(array $body): void {
        \curl::mock_response(json_encode($body));
    }

    /**
     * An answer of POST /connect/token.
     *
     * @param string $key Site key.
     * @return array
     */
    private function token(string $key = self::KEY): array {
        return [
            'siteKey' => $key,
            'site' => ['id' => 's1', 'url' => 'https://www.example.com/moodle'],
            'organization' => ['id' => 'o1', 'name' => 'Université X'],
            'project' => ['id' => 'p1', 'name' => 'Moodle principal'],
            'environment' => ['id' => 'e1', 'kind' => 'prod', 'name' => 'Production'],
            'plan' => ['id' => 'free', 'name' => 'Gratuit', 'paid' => false],
        ];
    }

    /**
     * An answer of GET /account.
     *
     * @param int $available Credits available.
     * @return array
     */
    private function summary(int $available = 21): array {
        return [
            'organization' => ['id' => 'o1', 'name' => 'Université X'],
            'project' => ['id' => 'p1', 'name' => 'Moodle principal'],
            'environment' => ['id' => 'e1', 'kind' => 'staging', 'name' => 'Staging'],
            'plan' => ['id' => 'free', 'name' => 'Gratuit', 'paid' => false, 'monthlyCredits' => 30],
            'credits' => ['available' => $available, 'reserved' => 0,
                'monthly' => ['allocation' => 30, 'remaining' => $available, 'periodEnd' => '2026-11-01T00:00:00Z'],
                'purchased' => ['remaining' => 0, 'nextExpiry' => null]],
            'usage' => ['periodEnd' => '2026-11-01T00:00:00Z', 'generations' => 9, 'creditsConsumed' => 9],
            'offers' => [
                'plans' => [['id' => 'pro', 'name' => 'Pro', 'monthlyCredits' => 500,
                    'price' => ['amount' => 4900, 'currency' => 'eur', 'interval' => 'month']]],
                'creditPacks' => [['id' => 'pack_100', 'credits' => 100, 'price' => ['amount' => 2000, 'currency' => 'eur']]],
            ],
            'urls' => ['dashboard' => 'https://app.brandablelms.com/dashboard/projects/p1',
                'buyCredits' => 'javascript:alert(1)'],
        ];
    }

    public function test_connection_stores_the_key(): void {
        $flow = connection::start_flow();
        $this->answer($this->token());
        $outcome = account::finish_connection($flow['state'], 'code-1');
        $this->assertSame('connected', $outcome['status']);
        $this->assertStringContainsString('Université X', $outcome['message']);
        $this->assertSame(self::KEY, connection::get_key());
        $this->assertSame('e1', connection::get_environment_id());
        $this->assertSame('Moodle principal', connection::get_info()['project']['name']);
        // The flow is over.
        $this->assertSame('expired', account::finish_connection($flow['state'], 'code-1')['status']);
    }

    public function test_connection_failures(): void {
        // Unknown state: nothing is exchanged.
        $this->assertSame('expired', account::finish_connection('forged', 'code-1')['status']);

        $flow = connection::start_flow();
        $this->assertSame('cancelled', account::finish_connection($flow['state'], '', 'access_denied')['status']);
        // The state is used, even when the administrator cancelled.
        $this->assertSame('expired', account::finish_connection($flow['state'], 'code-1')['status']);

        $flow = connection::start_flow();
        $outcome = account::finish_connection($flow['state'], '', 'server_error', 'Mongo down');
        $this->assertDebuggingCalled('Brandable connection failed: server_error: Mongo down');
        $this->assertSame('failed', $outcome['status']);
        // The description is for the logs only.
        $this->assertStringNotContainsString('Mongo', $outcome['message']);

        $flow = connection::start_flow();
        $this->answer(['error' => ['code' => 'invalid_grant', 'message' => 'Code expired']]);
        $outcome = account::finish_connection($flow['state'], 'code-1');
        $this->assertSame(get_string('connect:invalidgrant', 'theme_brandable'), $outcome['message']);

        // A key that does not look like a site key is never stored.
        $flow = connection::start_flow();
        $this->answer($this->token('not-a-key'));
        $this->assertSame('failed', account::finish_connection($flow['state'], 'code-1')['status']);
        $this->assertFalse(connection::is_connected());
    }

    public function test_disconnection(): void {
        // 204: revoked.
        connection::store_key(self::KEY, $this->token());
        \curl::mock_response('');
        account::disconnect();
        $this->assertFalse(connection::is_connected());
        $this->assertSame('e1', connection::get_environment_id());

        // Already revoked, or a copy of the site: forgotten (locally only for the copy).
        foreach (['site_not_connected', 'site_mismatch'] as $code) {
            connection::store_key(self::KEY);
            $this->answer(['error' => ['code' => $code, 'message' => 'm']]);
            account::disconnect();
            $this->assertFalse(connection::is_connected(), $code);
        }

        // Brandable cannot revoke it: kept, unless forced.
        connection::store_key(self::KEY);
        $this->answer(['error' => ['code' => 'internal_error', 'message' => 'Unexpected error']]);
        try {
            account::disconnect();
            $this->fail('The error must be reported');
        } catch (api_exception $e) {
            $this->assertSame('Unexpected error', $e->displaymessage);
        }
        $this->assertTrue(connection::is_connected());
        $this->answer(['error' => ['code' => 'internal_error', 'message' => 'Unexpected error']]);
        account::disconnect(true);
        $this->assertFalse(connection::is_connected());
    }

    public function test_account_is_kept_for_a_minute(): void {
        connection::store_key(self::KEY);
        $this->answer($this->summary(21));
        $this->assertSame(21, account::get()['credits']['available']);
        // From the session: no request (none simulated).
        $this->assertSame(21, account::get()['credits']['available']);
        $this->answer($this->summary(3));
        $this->assertSame(3, account::get(true)['credits']['available']);
    }

    public function test_refused_key_is_forgotten(): void {
        global $CFG;

        connection::store_key(self::KEY);
        $this->answer(['error' => ['code' => 'site_not_connected', 'message' => 'Not connected']]);
        try {
            account::get(true);
            $this->fail('An exception was expected');
        } catch (api_exception $e) {
            $this->assertSame('site_not_connected', $e->apicode);
        }
        $this->assertFalse(connection::is_connected());

        // Never the manual key of dev mode.
        $CFG->theme_brandable_devmode = true;
        set_config('brandable_apikey', 'dev-key', 'theme_brandable');
        connection::store_key(self::KEY);
        account::handle_error('site_not_connected');
        $this->assertTrue(connection::is_connected());
    }

    public function test_account_export(): void {
        $export = get_account::export($this->summary(3));
        $this->assertSame(3, $export['credits']);
        $this->assertTrue($export['lowcredits']);
        $this->assertFalse(get_account::export($this->summary(5))['lowcredits']);
        $this->assertSame('Staging', $export['environment']);
        $this->assertSame('pack_100', $export['creditpacks'][0]['id']);
        $this->assertSame('100 credits, 20.00 €', $export['creditpacks'][0]['label']);
        $this->assertSame('Pro', $export['plans'][0]['name']);
        $this->assertSame('500 credits per month', $export['plans'][0]['credits']);
        $this->assertSame('49.00 € a month', $export['plans'][0]['prices'][0]['price']);
        $this->assertSame('https://app.brandablelms.com/dashboard/projects/p1', $export['dashboardurl']);
        // Only web links.
        $this->assertSame('', $export['buycreditsurl']);
        $this->assertSame(strtotime('2026-11-01T00:00:00Z'), $export['periodend']);

        // Price not set yet in Brandable: the offer without amount.
        $summary = $this->summary(3);
        $summary['offers']['creditPacks'][0]['price'] = null;
        $this->assertSame('100 credits', get_account::export($summary)['creditpacks'][0]['label']);
    }

    public function test_current_plan_is_plan_name_never_an_offer(): void {
        // Essentiel subscribed (monthly); the offers list Essentiel then Pro, Pro last.
        $summary = $this->summary(21);
        $summary['plan'] = ['id' => 'site_local', 'name' => 'Essentiel', 'paid' => true, 'monthlyCredits' => 300,
            'limits' => ['jsReview' => false]];
        $summary['credits']['monthly'] = ['allocation' => 300, 'remaining' => 120, 'periodEnd' => '2026-11-28T00:00:00Z'];
        $offer = fn(string $id, string $planid, string $name, string $interval, bool $current = false) => ['id' => $id,
            'planId' => $planid, 'name' => $name, 'monthlyCredits' => 300, 'maxSites' => 1, 'current' => $current,
            'price' => ['amount' => 2900, 'currency' => 'eur', 'interval' => $interval]];
        $summary['offers']['plans'] = [
            $offer('site_local_monthly', 'site_local', 'Essentiel', 'month', true),
            $offer('site_local_yearly', 'site_local', 'Essentiel', 'year'),
            $offer('site_cloud_monthly', 'site_cloud', 'Pro', 'month'),
        ];
        $export = get_account::export($summary);
        $this->assertSame(['Essentiel', 'site_local'], [$export['plan'], $export['planid']]);
        // The credits of the account, not those of an offer.
        $this->assertSame([300, 120], [$export['monthlyallocation'], $export['monthlyremaining']]);
        $this->assertSame(strtotime('2026-11-01T00:00:00Z'), $export['periodend']);
        // The current offer marked (no "Choose" for it), the others not.
        $this->assertSame([true, false], array_column($export['plans'], 'current'));
        $this->assertSame([true, false], array_column($export['plans'][0]['prices'], 'current'));
        $this->assertFalse($export['plans'][1]['prices'][0]['current']);
        $html = $this->render_offers($export);
        $this->assertStringContainsString('data-region="currentoffer"', $html);
        $this->assertStringNotContainsString('data-item="site_local_monthly"', $html);
        $this->assertStringContainsString('data-item="site_cloud_monthly"', $html);
        $this->assertStringContainsString('data-item="site_local_yearly"', $html);

        // Until the webhook, the same state: the editor keeps showing Essentiel. Then Pro, from plan.name.
        $this->assertSame($export['billingstate'], get_account::export($summary)['billingstate']);
        $summary['plan'] = ['id' => 'site_cloud', 'name' => 'Pro', 'paid' => true, 'limits' => ['jsReview' => true]];
        $summary['offers']['plans'][0]['current'] = false;
        $summary['offers']['plans'][2]['current'] = true;
        $changed = get_account::export($summary);
        $this->assertSame('Pro', $changed['plan']);
        $this->assertNotSame($export['billingstate'], $changed['billingstate']);
        // Credits bought: the state changes too.
        $summary['credits']['available'] = 121;
        $this->assertNotSame($changed['billingstate'], get_account::export($summary)['billingstate']);
        // Valid for the web service.
        \core_external\external_api::clean_returnvalue(get_account::execute_returns(), ['available' => true,
            'connected' => true, 'canmanage' => true, 'errorcode' => '', 'errormessage' => ''] + $changed);
    }

    /**
     * Renders the offers of the account panel.
     *
     * @param array $export Exported account.
     * @return string
     */
    private function render_offers(array $export): string {
        global $OUTPUT;
        return $OUTPUT->render_from_template('theme_brandable/editor_offers', $export + ['showplans' => true,
            'countrysupported' => true, 'showprices' => true, 'canmanage' => true]);
    }

    public function test_plans_in_cards(): void {
        $summary = $this->summary();
        $offer = fn(string $id, string $planid, string $name, int $sites, int $amount, string $interval) => ['id' => $id,
            'planId' => $planid, 'name' => $name, 'description' => $sites > 1 ? null : "About $name", 'monthlyCredits' => 300,
            'maxSites' => $sites, 'price' => ['amount' => $amount, 'currency' => 'eur', 'interval' => $interval]];
        $summary['offers']['plans'] = [
            $offer('site_local_monthly', 'site_local', 'Essentiel', 1, 2900, 'month'),
            $offer('site_local_yearly', 'site_local', 'Essentiel', 1, 29000, 'year'),
            $offer('site_cloud_monthly', 'site_cloud', 'Pro', 1, 4900, 'month'),
            $offer('multisite_10_monthly', 'multisite_10', 'Multi-sites 10', 10, 24900, 'month'),
            $offer('multisite_10_yearly', 'multisite_10', 'Multi-sites 10', 10, 249000, 'year'),
        ];
        $summary['urls']['pricing'] = 'https://app.brandablelms.com/pricing?currency=eur';
        $export = get_account::export($summary);

        // One card per plan for one site, with its monthly and yearly prices.
        $this->assertSame(['site_local', 'site_cloud'], array_column($export['plans'], 'planid'));
        [$essentiel, $pro] = $export['plans'];
        $this->assertSame('About Essentiel', $essentiel['description']);
        $this->assertSame(['site_local_monthly', 'site_local_yearly'], array_column($essentiel['prices'], 'id'));
        $this->assertSame('290.00 € a year', $essentiel['prices'][1]['price']);
        $this->assertSame('that is 24.17 € per month', $essentiel['prices'][1]['note']);
        $this->assertSame([false, false], array_column($essentiel['prices'], 'always'));
        // A plan with a single price shows it whatever the period chosen.
        $this->assertSame([true], array_column($pro['prices'], 'always'));
        $this->assertTrue($export['hasyearly']);

        // The plans for several sites: listed once, bought on the pricing page.
        $this->assertSame([['label' => 'Multi-sites 10: 300 credits per month', 'current' => false]], $export['multisites']);
        $this->assertSame('https://app.brandablelms.com/pricing?currency=eur', $export['pricingurl']);
        $this->assertSame('', $export['licencesurl']);
        $licences = 'https://app.brandablelms.com/dashboard/projects/p1/licences';
        $summary['urls']['licences'] = $licences;
        $this->assertSame($licences, get_account::export($summary)['licencesurl']);
        $this->assertFalse(get_account::export($this->summary())['hasyearly']);
    }

    public function test_prices_come_with_the_tax_mention(): void {
        // The mention of Brandable, in the language asked; ours when it has none.
        $this->assertSame(get_string('account:taxnote', 'theme_brandable'), get_account::export($this->summary())['taxnote']);
        $summary = $this->summary();
        $summary['offers']['taxNote'] = 'Prix hors taxes. Taxes éventuelles en sus selon le pays.';
        $summary['offers']['countrySupported'] = true;
        $summary['urls']['billingContact'] = 'https://app.brandablelms.com/billing/contact?reason=public&organization=o1';
        $export = get_account::export($summary);
        $this->assertSame('Prix hors taxes. Taxes éventuelles en sus selon le pays.', $export['taxnote']);
        $this->assertTrue($export['countrysupported']);
        $this->assertSame($summary['urls']['billingContact'], $export['billingcontacturl']);

        // Country not supported: no offer to pay, the billing contact only.
        $summary['offers']['countrySupported'] = false;
        $export = get_account::export($summary);
        $this->assertFalse($export['countrysupported']);
        $this->assertSame([], $export['creditpacks']);
        $this->assertSame([], $export['plans']);
        $this->assertSame([], $export['multisites']);
        $this->assertSame($summary['urls']['billingContact'], $export['billingcontacturl']);
    }

    public function test_billing_country_is_asked_before_the_first_purchase(): void {
        global $CFG;

        // Brandable has no billing country for the organisation.
        $summary = $this->summary();
        $summary['offers']['billingCountry'] = null;
        $this->assertTrue(get_account::export($summary)['countryrequired']);
        $summary['offers']['billingCountry'] = 'FR';
        $this->assertFalse(get_account::export($summary)['countryrequired']);
        // Older answers without the field: nothing asked.
        $this->assertFalse(get_account::export($this->summary())['countryrequired']);

        // Preselected: the site country, the profile of the administrator, then the region of the language.
        $CFG->country = 'BE';
        $this->assertSame('BE', connection::get_default_billing_country());
        $CFG->country = '';
        $this->assertSame('', connection::get_default_billing_country());
        $this->setUser($this->getDataGenerator()->create_user(['country' => 'CA']));
        $this->assertSame('CA', connection::get_default_billing_country());

        // The purchase is sent with the country chosen; 400 country_required is passed to the editor.
        connection::store_key(self::KEY);
        $this->setAdminUser();
        $this->answer(['error' => ['code' => 'country_required', 'message' => 'Choose a country.']]);
        $result = \theme_brandable\external\billing_checkout::execute('credits', 'pack_100');
        $this->assertSame('country_required', $result['errorcode']);
        $this->assertSame('Choose a country.', $result['errormessage']);
        $this->answer(['url' => 'https://checkout.stripe.com/c/pay/cs_2']);
        $result = \theme_brandable\external\billing_checkout::execute('credits', 'pack_100', 'FR');
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_2', $result['url']);
        // The country goes in the body, only when chosen.
        $client = new class extends api_client {
            /** @var array Bodies sent. */
            public array $bodies = [];

            #[\Override]
            public function request(
                string $method,
                string $path,
                ?array $body = null,
                bool $authenticated = true,
                ?string $raw = null,
                string $contenttype = 'application/json'
            ): array {
                $this->bodies[] = $body;
                return ['url' => 'https://checkout.stripe.com/c/pay/cs_3'];
            }
        };
        account::checkout('credits', 'pack_100', 'FR', $client);
        account::checkout('credits', 'pack_100', '', $client);
        $this->assertSame('FR', $client->bodies[0]['country']);
        $this->assertArrayNotHasKey('country', $client->bodies[1]);

        // Only a country Moodle knows.
        $this->expectException(\invalid_parameter_exception::class);
        \theme_brandable\external\billing_checkout::execute('credits', 'pack_100', 'XX');
    }

    public function test_billing_country_is_fixed_after_the_first_payment(): void {
        // Fixed at the first payment: shown with its name, no longer asked.
        $summary = $this->summary();
        $summary['offers']['billingCountry'] = 'FR';
        $summary['offers']['billingCountryLocked'] = true;
        $export = get_account::export($summary);
        $this->assertTrue($export['countrylocked']);
        $this->assertSame('France', $export['billingcountry']);
        $this->assertFalse($export['countryrequired']);
        $summary['offers']['billingCountryLocked'] = false;
        $export = get_account::export($summary);
        $this->assertFalse($export['countrylocked']);
        $this->assertSame('', $export['billingcountry']);

        // Another country: 409 country_locked, the message of Brandable and the billing contact.
        connection::store_key(self::KEY);
        $this->answer(['error' => ['code' => 'country_locked', 'message' => 'Billing country fixed.',
            'url' => 'https://app.brandablelms.com/billing/contact?organization=o1']]);
        $result = \theme_brandable\external\billing_checkout::execute('credits', 'pack_100', 'BE');
        $this->assertSame('', $result['url']);
        $this->assertSame('country_locked', $result['errorcode']);
        $this->assertSame('Billing country fixed.', $result['errormessage']);
        $this->assertSame('https://app.brandablelms.com/billing/contact?organization=o1', $result['errorurl']);
        $this->assertTrue(connection::is_connected());
    }

    public function test_checkout_from_a_country_not_supported(): void {
        connection::store_key(self::KEY);
        $this->answer(['error' => ['code' => 'country_not_supported', 'message' => 'Not sold in your country.',
            'url' => 'https://app.brandablelms.com/billing/contact?organization=o1']]);
        $result = \theme_brandable\external\billing_checkout::execute('credits', 'pack_100');
        $this->assertSame('', $result['url']);
        $this->assertSame('country_not_supported', $result['errorcode']);
        $this->assertSame('Not sold in your country.', $result['errormessage']);
        $this->assertSame('https://app.brandablelms.com/billing/contact?organization=o1', $result['errorurl']);
        // The site stays connected.
        $this->assertTrue(connection::is_connected());
    }

    public function test_checkout(): void {
        global $CFG;

        connection::store_key(self::KEY);
        $this->answer(['url' => 'https://checkout.stripe.com/c/pay/cs_1', 'expiresAt' => '2026-10-21T10:12:00Z',
            'flow' => 'checkout']);
        $this->assertSame(
            ['url' => 'https://checkout.stripe.com/c/pay/cs_1', 'flow' => 'checkout'],
            account::checkout('credits', 'pack_100')
        );

        // Already subscribed, another price: the Customer Portal of Stripe on the confirmation of the change.
        $this->answer(['url' => 'https://billing.stripe.com/p/session/flow_1', 'expiresAt' => '2026-11-04T06:56:01Z',
            'flow' => 'subscription_update']);
        $result = \theme_brandable\external\billing_checkout::execute('plan', 'site_cloud_monthly');
        $this->assertSame(
            ['https://billing.stripe.com/p/session/flow_1', 'subscription_update', ''],
            [$result['url'], $result['flow'], $result['errorcode']]
        );

        // The price already subscribed (409): said as is, nothing opened.
        $this->answer(['error' => ['code' => 'already_subscribed', 'message' => 'Already your plan.',
            'url' => 'https://app.brandablelms.com/billing/portal?organization=o1']]);
        $result = \theme_brandable\external\billing_checkout::execute('plan', 'site_cloud_monthly');
        $this->assertSame(
            ['', 'already_subscribed', 'Already your plan.'],
            [$result['url'], $result['errorcode'], $result['errormessage']]
        );

        // In dev mode, the local web app may answer in http; never otherwise.
        $CFG->theme_brandable_devmode = true;
        $this->answer(['url' => 'http://localhost:3000/billing/checkout/cs_1']);
        $this->assertSame(
            ['url' => 'http://localhost:3000/billing/checkout/cs_1', 'flow' => 'checkout'],
            account::checkout('credits', 'pack_100')
        );
        $CFG->theme_brandable_devmode = false;
        $this->answer(['url' => 'http://evil.example.com/pay']);
        $this->expectException(api_exception::class);
        account::checkout('credits', 'pack_100');
    }

    public function test_error_urls_are_web_links_only(): void {
        connection::store_key(self::KEY);
        $this->answer(['error' => ['code' => 'insufficient_credits', 'message' => 'Not enough credits',
            'url' => 'https://app.brandablelms.com/billing/credits?organization=o1']]);
        try {
            (new api_client())->request('POST', '/generations', []);
            $this->fail('An exception was expected');
        } catch (api_exception $e) {
            $this->assertSame('https://app.brandablelms.com/billing/credits?organization=o1', $e->url);
        }
        $this->answer(['error' => ['code' => 'plan_required', 'message' => 'm', 'url' => 'javascript:alert(1)']]);
        try {
            (new api_client())->request('POST', '/generations', []);
            $this->fail('An exception was expected');
        } catch (api_exception $e) {
            $this->assertSame('', $e->url);
        }
    }

    public function test_blocked_api_gets_a_clear_message(): void {
        // Default cURL security of Moodle: private addresses and ports other than 80 / 443 are blocked.
        set_config('curlsecurityblockedhosts', 'blocked.example');
        connection::store_key(self::KEY);
        try {
            (new api_client('http://blocked.example:3000/api/v1'))->request('GET', '/account');
            $this->fail('An exception was expected');
        } catch (api_exception $e) {
            $this->assertSame('url_blocked', $e->apicode);
            $this->assertStringContainsString('http://blocked.example:3000', $e->displaymessage);
            $this->assertStringNotContainsString('/api/v1', $e->displaymessage);
        }
        $this->resetDebugging();
    }
}
