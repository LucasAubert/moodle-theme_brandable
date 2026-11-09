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
use theme_brandable\local\account\api_exception;
use theme_brandable\local\js\review;

/**
 * Asks Brandable for its security review of a JavaScript module of the draft (POST /js/review, contract 12.6): an aid
 * to the review, never a validation; its notice is always shown.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ask_js_review extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'key' => new external_value(PARAM_RAW, 'Key of the variant (target and scope)'),
        ]);
    }

    /**
     * Asks for the review.
     *
     * @param string $key Key of the variant.
     * @return array
     */
    public static function execute(string $key): array {
        $params = self::validate_parameters(self::execute_parameters(), ['key' => $key]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('theme/brandable:edit', $context);
        $result = ['apis' => [], 'findings' => [], 'errors' => [], 'explanation' => '', 'concerns' => [], 'notice' => '',
            'errorcode' => '', 'errormessage' => '', 'errorurl' => ''];
        try {
            $review = review::ask_brandable($params['key']);
        } catch (api_exception $e) {
            return ['errorcode' => $e->apicode, 'errormessage' => $e->displaymessage, 'errorurl' => $e->url] + $result;
        }
        $list = fn(string $name) => array_values(array_map(fn($item) => [
            'line' => (int) ($item['line'] ?? 0),
            'message' => (string) (is_array($item) ? ($item['message'] ?? '') : $item),
        ], array_filter((array) ($review['report'][$name] ?? []), fn($item) => is_array($item) || is_scalar($item))));
        return [
            'apis' => array_values(array_map('strval', array_filter((array) ($review['report']['apis'] ?? []), 'is_scalar'))),
            'findings' => $list('findings'),
            'errors' => $list('errors'),
            'explanation' => $review['explanation'],
            'concerns' => $review['concerns'],
            'notice' => $review['notice'],
        ] + $result;
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $item = fn(string $what) => new external_multiple_structure(new external_single_structure([
            'line' => new external_value(PARAM_INT, 'Line in the source, 0 if unknown'),
            'message' => new external_value(PARAM_RAW, 'Message'),
        ]), $what);
        return new external_single_structure([
            'apis' => new external_multiple_structure(new external_value(PARAM_RAW, 'API of b'), 'APIs used (current analysis)'),
            'findings' => $item('Findings of the current analysis'),
            'errors' => $item('Errors of the current analysis (stricter analyzer since the generation)'),
            'explanation' => new external_value(PARAM_RAW, 'What the module does (plain text)'),
            'concerns' => new external_multiple_structure(new external_value(PARAM_RAW, 'Point to check'), 'Points to check'),
            'notice' => new external_value(PARAM_RAW, 'Always shown: an aid, never a guarantee'),
            'errorcode' => new external_value(PARAM_RAW, 'Error code (plan_required, signature_invalid...), empty if none'),
            'errormessage' => new external_value(PARAM_RAW, 'Error shown as is, empty if none'),
            'errorurl' => new external_value(PARAM_RAW, 'Page where the administrator can act (plan), empty if none'),
        ]);
    }
}
