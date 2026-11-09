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
use theme_brandable\local\account\connection;
use theme_brandable\local\cloud\cloud;
use theme_brandable\local\cloud\proposals;

/**
 * Returns the proposal waiting for the environment of this site (contract 13.7, spec C15), read again at the opening
 * of the editor and after each publication: the banner of the editor.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_cloud_proposal extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Reads the state of the project.
     *
     * @return array
     */
    public static function execute(): array {
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('theme/brandable:edit', $context);
        $result = ['access' => cloud::NONE, 'hasproposal' => false, 'id' => '', 'versionid' => '', 'number' => 0, 'note' => '',
            'from' => '', 'author' => '', 'message' => '', 'createdat' => 0];
        if (!connection::is_connected() || cloud::get_access() === cloud::NONE) {
            return $result;
        }
        try {
            $state = cloud::get_state(true);
        } catch (api_exception $e) {
            // No banner while the cloud cannot be read: read again at the next opening.
            return $result;
        }
        $result['access'] = (string) ($state['access'] ?? cloud::NONE);
        $proposal = $result['access'] !== cloud::NONE ? proposals::pending($state) : null;
        return $proposal === null ? $result : ['hasproposal' => true] + $proposal + $result;
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'access' => new external_value(PARAM_ALPHA, 'full, read or none'),
            'hasproposal' => new external_value(PARAM_BOOL, 'Whether a proposal waits for this environment'),
            'id' => new external_value(PARAM_RAW, 'Proposal'),
            'versionid' => new external_value(PARAM_RAW, 'Version proposed'),
            'number' => new external_value(PARAM_INT, 'Number of the version in the project'),
            'note' => new external_value(PARAM_RAW, 'Note of the version'),
            'from' => new external_value(PARAM_RAW, 'Environment it comes from'),
            'author' => new external_value(PARAM_RAW, 'Author of the proposal, as given by Brandable'),
            'message' => new external_value(PARAM_RAW, 'Message of the proposal'),
            'createdat' => new external_value(PARAM_INT, 'Date of the proposal'),
        ]);
    }
}
