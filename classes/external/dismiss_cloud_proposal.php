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
use theme_brandable\local\account\api_exception;
use theme_brandable\local\cloud\proposals;

/**
 * Dismisses the proposal waiting for the environment of this site (contract 13.7, spec C15).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dismiss_cloud_proposal extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'proposalid' => new external_value(PARAM_ALPHANUMEXT, 'Proposal'),
        ]);
    }

    /**
     * Dismisses the proposal.
     *
     * @param string $proposalid Proposal.
     * @return array
     */
    public static function execute(string $proposalid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['proposalid' => $proposalid]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('theme/brandable:edit', $context);
        try {
            proposals::dismiss($params['proposalid']);
        } catch (api_exception $e) {
            return ['errormessage' => $e->displaymessage];
        }
        return ['errormessage' => ''];
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'errormessage' => new external_value(PARAM_RAW, 'Shown as is, empty if none'),
        ]);
    }
}
