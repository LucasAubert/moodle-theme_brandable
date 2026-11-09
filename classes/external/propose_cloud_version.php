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
use theme_brandable\local\cloud\cloud;
use theme_brandable\local\cloud\proposals;

/**
 * Proposes a version of the project to another environment (contract 13.7, spec C15): its site shows a banner and
 * notifies its administrators.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class propose_cloud_version extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'versionid' => new external_value(PARAM_ALPHANUMEXT, 'Version of the project'),
            'environmentid' => new external_value(PARAM_ALPHANUMEXT, 'Environment proposed to'),
            'message' => new external_value(PARAM_TEXT, 'Message, 500 characters at most', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Proposes the version.
     *
     * @param string $versionid Version.
     * @param string $environmentid Environment.
     * @param string $message Message.
     * @return array
     */
    public static function execute(string $versionid, string $environmentid, string $message = ''): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['versionid' => $versionid, 'environmentid' => $environmentid, 'message' => $message]
        );
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('theme/brandable:edit', $context);
        if (cloud::get_access() !== cloud::FULL) {
            // Reading only, or no cloud: never a refused writing (spec C1).
            return ['environment' => '', 'errormessage' => get_string('cloud:error:plan', 'theme_brandable')];
        }
        try {
            $proposal = proposals::propose($params['versionid'], $params['environmentid'], $params['message']);
        } catch (api_exception $e) {
            return ['environment' => '', 'errormessage' => $e->displaymessage];
        }
        return ['environment' => (string) ($proposal['to']['name'] ?? ''), 'errormessage' => ''];
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'environment' => new external_value(PARAM_RAW, 'Environment proposed to, empty on an error'),
            'errormessage' => new external_value(PARAM_RAW, 'Shown as is, empty if none'),
        ]);
    }
}
