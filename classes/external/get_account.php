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

namespace theme_brandable\external;

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use theme_brandable\local\account\account;
use theme_brandable\local\account\api_exception;
use theme_brandable\local\account\connection;

/**
 * Returns the Brandable account of the site, for the account panel of the editor.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_account extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'refresh' => new external_value(PARAM_BOOL, 'Read it again from Brandable', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Returns the account.
     *
     * @param bool $refresh Read it again from Brandable.
     * @return array
     */
    public static function execute(bool $refresh = false): array {
        $params = self::validate_parameters(self::execute_parameters(), ['refresh' => $refresh]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('theme/brandable:edit', $context);

        $result = [
            'available' => false,
            'connected' => connection::is_connected(),
            'canmanage' => has_capability('moodle/site:config', $context),
            'errorcode' => '',
            'errormessage' => '',
        ];
        try {
            $data = account::get($params['refresh']);
        } catch (api_exception $e) {
            // The key may have been forgotten (401).
            return ['connected' => connection::is_connected(), 'errorcode' => $e->apicode,
                'errormessage' => $e->displaymessage] + $result;
        }
        return ['available' => true] + self::export($data) + $result;
    }

    /**
     * Exports the summary returned by GET /api/v1/account.
     *
     * @param array $data Account.
     * @return array
     */
    public static function export(array $data): array {
        $credits = $data['credits'] ?? [];
        $plan = $data['plan'] ?? [];
        $available = (int) ($credits['available'] ?? 0);
        $url = fn(string $name) => preg_match('~^https?://~i', (string) ($data['urls'][$name] ?? ''))
            ? (string) $data['urls'][$name] : '';
        // One card per plan (its monthly and yearly prices); the plans for several sites are only listed.
        $plans = [];
        $multisites = [];
        // The current plan is plan.name and plan.limits only, never an entry of the offers (contract 5); the variables
        // of the offers below never reuse $plan nor $credits.
        foreach ($data['offers']['plans'] ?? [] as $offer) {
            $offercredits = get_string('account:monthlycredits', 'theme_brandable', (int) ($offer['monthlyCredits'] ?? 0));
            $current = !empty($offer['current']);
            if ((int) ($offer['maxSites'] ?? 1) > 1) {
                $name = (string) ($offer['name'] ?? '');
                $a = (object) ['name' => $name, 'credits' => $offercredits];
                $multisites[$name] = ['label' => get_string('account:multisiteoffer', 'theme_brandable', $a),
                    'current' => $current || !empty($multisites[$name]['current'])];
                continue;
            }
            $planid = (string) ($offer['planId'] ?? $offer['id'] ?? '');
            $plans[$planid] ??= [
                'planid' => $planid,
                'name' => (string) ($offer['name'] ?? ''),
                'description' => trim((string) ($offer['description'] ?? '')),
                'credits' => $offercredits,
                'current' => false,
                'prices' => [],
            ];
            // The price of the subscription in progress: shown as the current offer, without "Choose".
            $plans[$planid]['current'] = $plans[$planid]['current'] || $current;
            $interval = (string) ($offer['price']['interval'] ?? '');
            $plans[$planid]['prices'][] = [
                'id' => (string) ($offer['id'] ?? ''),
                'interval' => $interval === 'year' ? 'year' : 'month',
                'price' => self::price($offer['price'] ?? null),
                'note' => $interval === 'year' && isset($offer['price']['amount'])
                    ? get_string('account:yearlypermonth', 'theme_brandable', self::price([
                        'amount' => (int) round($offer['price']['amount'] / 12),
                        'currency' => $offer['price']['currency'] ?? '',
                    ])) : '',
                'always' => false,
                'current' => $current,
            ];
        }
        // The yearly switch is offered when a plan has both prices; a plan with a single price always shows it.
        $hasyearly = false;
        foreach ($plans as $planid => $card) {
            $intervals = array_unique(array_column($card['prices'], 'interval'));
            if (count($intervals) > 1) {
                $hasyearly = true;
                continue;
            }
            foreach ($card['prices'] as $i => $price) {
                $plans[$planid]['prices'][$i]['always'] = true;
            }
        }
        $plans = array_values($plans);
        $multisites = array_values($multisites);
        $packs = [];
        foreach ($data['offers']['creditPacks'] ?? [] as $offer) {
            $packs[] = [
                'id' => (string) ($offer['id'] ?? ''),
                'label' => self::label('account:packoffer', [
                    'credits' => (int) ($offer['credits'] ?? 0),
                    'price' => self::price($offer['price'] ?? null),
                ]),
            ];
        }
        // Purchases are not offered where Brandable does not sell: contact the billing team instead.
        $countrysupported = (bool) ($data['offers']['countrySupported'] ?? true);
        if (!$countrysupported) {
            $plans = [];
            $multisites = [];
            $packs = [];
        }
        $taxnote = trim((string) ($data['offers']['taxNote'] ?? ''));
        // No billing country on the organisation yet: the editor asks for it with the offers.
        $countryrequired = is_array($data['offers'] ?? null) && array_key_exists('billingCountry', $data['offers'])
            && $data['offers']['billingCountry'] === null;
        // Country fixed at the first payment: shown, no longer asked (changed by the Brandable support).
        $billingcountry = strtoupper(trim((string) ($data['offers']['billingCountry'] ?? '')));
        $countrylocked = !empty($data['offers']['billingCountryLocked']) && $billingcountry !== '';
        if ($countrylocked) {
            $countryrequired = false;
            $countries = get_string_manager()->get_list_of_countries();
            $billingcountry = $countries[$billingcountry] ?? $billingcountry;
        }
        // What changes after a purchase or a change of plan (webhook): the plan, the price subscribed, the credits.
        $subscribed = array_values(array_filter(array_map(
            fn($offer) => !empty($offer['current']) ? (string) ($offer['id'] ?? '') : null,
            $data['offers']['plans'] ?? []
        )));
        $billingstate = sha1(json_encode([$plan['id'] ?? null, $plan['limits'] ?? null, $subscribed, $available,
            $credits['purchased']['remaining'] ?? null]));
        $periodend = strtotime((string) ($data['usage']['periodEnd'] ?? $credits['monthly']['periodEnd'] ?? ''));
        return [
            'organization' => (string) ($data['organization']['name'] ?? ''),
            'project' => (string) ($data['project']['name'] ?? ''),
            'environment' => (string) ($data['environment']['name'] ?? ''),
            'environmentkind' => (string) ($data['environment']['kind'] ?? ''),
            'plan' => (string) ($plan['name'] ?? ''),
            'planid' => (string) ($plan['id'] ?? ''),
            'billingstate' => $billingstate,
            'planpaid' => !empty($plan['paid']),
            'credits' => $available,
            'lowcredits' => $available < account::RESERVATION,
            'monthlyallocation' => (int) ($credits['monthly']['allocation'] ?? 0),
            'monthlyremaining' => (int) ($credits['monthly']['remaining'] ?? 0),
            'purchasedremaining' => (int) ($credits['purchased']['remaining'] ?? 0),
            'generations' => (int) ($data['usage']['generations'] ?? 0),
            'creditsconsumed' => (int) ($data['usage']['creditsConsumed'] ?? 0),
            'periodend' => $periodend ?: 0,
            'dashboardurl' => $url('dashboard'),
            'billingportalurl' => $url('billingPortal'),
            'buycreditsurl' => $url('buyCredits'),
            'changeplanurl' => $url('changePlan'),
            'billingcontacturl' => $url('billingContact'),
            'countrysupported' => $countrysupported,
            'countryrequired' => $countryrequired,
            'countrylocked' => $countrylocked,
            'billingcountry' => $countrylocked ? $billingcountry : '',
            'taxnote' => $taxnote !== '' ? $taxnote : get_string('account:taxnote', 'theme_brandable'),
            'pricingurl' => $url('pricing'),
            'licencesurl' => $url('licences'),
            'plans' => $plans,
            'hasyearly' => $hasyearly,
            'multisites' => $multisites,
            'creditpacks' => $packs,
        ];
    }

    /**
     * Formats a price of the offers (amount in cents).
     *
     * @param array|null $price ['amount' => int, 'currency' => string, 'interval' => month|year|null], null while the
     *     price is not set in Brandable.
     * @return string Empty when there is no price.
     */
    private static function price(?array $price): string {
        if ($price === null || !isset($price['amount'])) {
            return '';
        }
        $currency = strtoupper((string) ($price['currency'] ?? ''));
        $symbols = ['EUR' => '€', 'USD' => '$', 'GBP' => '£'];
        $amount = format_float(((int) ($price['amount'] ?? 0)) / 100, 2);
        $text = $amount . ' ' . ($symbols[$currency] ?? $currency);
        $interval = (string) ($price['interval'] ?? '');
        return in_array($interval, ['month', 'year'], true)
            ? get_string('account:per' . $interval, 'theme_brandable', $text) : $text;
    }

    /**
     * Labels an offer, without its price when Brandable has none yet.
     *
     * @param string $key String of the offer with its price (the one without price ends with "_noprice").
     * @param array $a Values.
     * @return string
     */
    private static function label(string $key, array $a): string {
        return get_string($a['price'] !== '' ? $key : $key . '_noprice', 'theme_brandable', (object) $a);
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $offer = new external_single_structure([
            'id' => new external_value(PARAM_ALPHANUMEXT, 'Offer id, sent to billing_checkout'),
            'label' => new external_value(PARAM_TEXT, 'Offer, human readable'),
        ]);
        $plan = new external_single_structure([
            'planid' => new external_value(PARAM_ALPHANUMEXT, 'Plan id'),
            'name' => new external_value(PARAM_TEXT, 'Name of the plan, as given by Brandable'),
            'description' => new external_value(PARAM_TEXT, 'Presentation of the plan, may be empty'),
            'credits' => new external_value(PARAM_TEXT, 'Monthly credits, human readable'),
            'current' => new external_value(PARAM_BOOL, 'Whether a price of this plan is the subscription in progress'),
            'prices' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_ALPHANUMEXT, 'Offer id, sent to billing_checkout'),
                'interval' => new external_value(PARAM_ALPHA, 'month or year'),
                'price' => new external_value(PARAM_TEXT, 'Price, human readable (may be empty)'),
                'note' => new external_value(PARAM_TEXT, 'Monthly equivalent of a yearly price, may be empty'),
                'always' => new external_value(PARAM_BOOL, 'Shown whatever the interval chosen (single price)'),
                'current' => new external_value(PARAM_BOOL, 'Price of the subscription in progress (offers.plans[].current)'),
            ])),
        ]);
        $multisite = new external_single_structure([
            'label' => new external_value(PARAM_TEXT, 'Plan for several sites, human readable'),
            'current' => new external_value(PARAM_BOOL, 'Whether it is the subscription in progress'),
        ]);
        $optional = fn(string $type, string $description) => new external_value($type, $description, VALUE_OPTIONAL);
        return new external_single_structure([
            'available' => new external_value(PARAM_BOOL, 'Whether the account could be read'),
            'connected' => new external_value(PARAM_BOOL, 'Whether the site holds a site key'),
            'canmanage' => new external_value(PARAM_BOOL, 'Whether the user can buy and disconnect (moodle/site:config)'),
            'errorcode' => new external_value(PARAM_ALPHANUMEXT, 'Error code when not available'),
            'errormessage' => new external_value(PARAM_RAW, 'Error message when not available, shown as is'),
            'organization' => $optional(PARAM_TEXT, 'Organisation'),
            'project' => $optional(PARAM_TEXT, 'Project'),
            'environment' => $optional(PARAM_TEXT, 'Environment'),
            'environmentkind' => $optional(PARAM_ALPHA, 'dev, staging or prod'),
            'plan' => $optional(PARAM_TEXT, 'Current plan: plan.name, never an entry of the offers'),
            'planid' => $optional(PARAM_ALPHANUMEXT, 'Current plan id (plan.id)'),
            'billingstate' => $optional(PARAM_ALPHANUM, 'Changes when the plan, the price subscribed or the credits change'),
            'planpaid' => $optional(PARAM_BOOL, 'Whether the plan is paid'),
            'credits' => $optional(PARAM_INT, 'Credits available (may be slightly negative)'),
            'lowcredits' => $optional(PARAM_BOOL, 'Fewer credits than a generation reserves'),
            'monthlyallocation' => $optional(PARAM_INT, 'Monthly credits of the plan'),
            'monthlyremaining' => $optional(PARAM_INT, 'Monthly credits left'),
            'purchasedremaining' => $optional(PARAM_INT, 'Purchased credits left'),
            'generations' => $optional(PARAM_INT, 'Generations this month'),
            'creditsconsumed' => $optional(PARAM_INT, 'Credits consumed this month'),
            'periodend' => $optional(PARAM_INT, 'End of the period (timestamp)'),
            'dashboardurl' => $optional(PARAM_URL, 'Brandable dashboard'),
            'billingportalurl' => $optional(PARAM_URL, 'Billing portal'),
            'buycreditsurl' => $optional(PARAM_URL, 'Credits page'),
            'changeplanurl' => $optional(PARAM_URL, 'Plan page'),
            'billingcontacturl' => $optional(PARAM_URL, 'Billing contact (country not supported, public bodies)'),
            'countrysupported' => $optional(PARAM_BOOL, 'Whether Brandable sells in the billing country'),
            'countryrequired' => $optional(PARAM_BOOL, 'Whether the billing country must be chosen before a purchase'),
            'countrylocked' => $optional(PARAM_BOOL, 'Whether the billing country is fixed (after the first payment)'),
            'billingcountry' => $optional(PARAM_TEXT, 'Billing country fixed at the first payment (name), empty otherwise'),
            'taxnote' => $optional(PARAM_TEXT, 'Mention shown with every price (taxes)'),
            'pricingurl' => $optional(PARAM_URL, 'Public pricing page (compare the plans, plans for several sites)'),
            'licencesurl' => $optional(PARAM_URL, 'Licences and deployments of the exported themes'),
            'plans' => new external_multiple_structure($plan, 'Paid plans for one site', VALUE_OPTIONAL),
            'hasyearly' => $optional(PARAM_BOOL, 'Whether plans have a monthly and a yearly price'),
            'multisites' => new external_multiple_structure($multisite, 'Plans for several sites (pricing page)', VALUE_OPTIONAL),
            'creditpacks' => new external_multiple_structure($offer, 'Credit packs', VALUE_OPTIONAL),
        ]);
    }
}
