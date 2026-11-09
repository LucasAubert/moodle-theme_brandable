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
use theme_brandable\local\js\review;

/**
 * Records that the user reviewed the source of a JavaScript module of the draft ("I reviewed this code", spec B13):
 * those managing the JavaScript only (managejs).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mark_js_reviewed extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'key' => new external_value(PARAM_RAW, 'Key of the variant (target and scope)'),
            'hash' => new external_value(PARAM_ALPHANUM, 'sha256 of the source read'),
        ]);
    }

    /**
     * Records the review.
     *
     * @param string $key Key of the variant.
     * @param string $hash sha256 of the source read.
     * @return array Modules to review.
     */
    public static function execute(string $key, string $hash): array {
        $params = self::validate_parameters(self::execute_parameters(), ['key' => $key, 'hash' => $hash]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('theme/brandable:edit', $context);
        // Only those managing the JavaScript record a review.
        require_capability(\theme_brandable\local\js\consent::CAPABILITY, $context);
        review::record($params['key'], $params['hash']);
        return get_js_review::export();
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return get_js_review::structure();
    }
}
