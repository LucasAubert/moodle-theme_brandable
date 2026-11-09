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
use core_external\external_single_structure;
use core_external\external_value;
use theme_brandable\local\account\account;
use theme_brandable\local\account\api_exception;

/**
 * Starts the purchase of a plan or a credit pack: returns the payment page, opened in a new tab by the editor.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class billing_checkout extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'kind' => new external_value(PARAM_ALPHA, 'plan or credits'),
            'item' => new external_value(PARAM_ALPHANUMEXT, 'Offer id (account offers)'),
            'country' => new external_value(PARAM_ALPHA, 'Billing country (ISO 3166-1 alpha-2), when asked', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Starts the purchase.
     *
     * @param string $kind plan or credits.
     * @param string $item Offer id.
     * @param string $country Billing country, when the organisation has none.
     * @return array ['url' => payment page, 'errorcode', 'errormessage', 'errorurl' => page offered with the error].
     */
    public static function execute(string $kind, string $item, string $country = ''): array {
        $params = ['kind' => $kind, 'item' => $item, 'country' => $country];
        $params = self::validate_parameters(self::execute_parameters(), $params);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);
        if (!in_array($params['kind'], ['plan', 'credits'], true)) {
            throw new \invalid_parameter_exception('kind');
        }
        $countries = get_string_manager()->get_list_of_countries();
        if ($params['country'] !== '' && !array_key_exists($params['country'], $countries)) {
            throw new \invalid_parameter_exception('country');
        }
        try {
            $result = account::checkout($params['kind'], $params['item'], $params['country']);
            return $result + ['errorcode' => '', 'errormessage' => '', 'errorurl' => ''];
        } catch (api_exception $e) {
            // Country required: the editor asks for it. Country not supported or locked: the billing contact, no payment
            // page. Price already subscribed: said as is.
            return ['url' => '', 'flow' => '', 'errorcode' => $e->apicode, 'errormessage' => $e->displaymessage,
                'errorurl' => $e->url];
        }
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'url' => new external_value(PARAM_URL, 'Payment page, or Customer Portal on the change of plan; empty on error'),
            'flow' => new external_value(PARAM_ALPHAEXT, 'checkout or subscription_update, empty on error'),
            'errorcode' => new external_value(PARAM_ALPHANUMEXT, 'Error code, empty on success'),
            'errormessage' => new external_value(PARAM_RAW, 'Error message, shown as is'),
            'errorurl' => new external_value(PARAM_URL, 'Page offered with the error (billing contact), may be empty'),
        ]);
    }
}
