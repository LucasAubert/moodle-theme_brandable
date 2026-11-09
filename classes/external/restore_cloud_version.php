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
use theme_brandable\local\cloud\restore;

/**
 * Restores a version of the project (or pulls a version proposed by another environment) as the draft of a new
 * session (contract 13.5, spec C9 to C13), and returns what was left out with the editor state.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_cloud_version extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'versionid' => new external_value(PARAM_ALPHANUMEXT, 'Version of the project'),
            'proposalid' => new external_value(PARAM_ALPHANUMEXT, 'Proposal it comes from', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Restores the version.
     *
     * @param string $versionid Version.
     * @param string $proposalid Proposal.
     * @return array
     */
    public static function execute(string $versionid, string $proposalid = ''): array {
        $params = self::validate_parameters(self::execute_parameters(), ['versionid' => $versionid, 'proposalid' => $proposalid]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('theme/brandable:edit', $context);
        \theme_brandable\local\preview::keep_alive();
        $empty = ['kind' => '', 'number' => 0, 'removed' => [], 'blocked' => [], 'downloaded' => 0, 'renamed' => [],
            'refused' => [], 'findings' => [], 'errorcode' => '', 'errormessage' => ''];
        try {
            $result = (new restore())->run($params['versionid'], $params['proposalid']);
        } catch (api_exception $e) {
            return ['errorcode' => $e->apicode, 'errormessage' => $e->displaymessage, 'state' => editor_state::export()] + $empty;
        }
        return ['kind' => $result['kind'], 'number' => $result['number'], 'state' => editor_state::export()]
            + $result['report'] + $empty;
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'kind' => new external_value(PARAM_ALPHA, 'restore or pull, empty on an error'),
            'number' => new external_value(PARAM_INT, 'Number of the version in the project'),
            'removed' => new external_multiple_structure(new external_single_structure([
                'type' => new external_value(PARAM_ALPHA, 'Type, empty when unknown'),
                'target' => new external_value(PARAM_RAW, 'Target'),
                'reason' => new external_value(PARAM_RAW, 'Why it was left out'),
            ]), 'Changes left out of the draft'),
            'blocked' => new external_multiple_structure(
                new external_value(PARAM_RAW, 'Target'),
                'JavaScript modules refused by the analysis of Brandable, not taken'
            ),
            'downloaded' => new external_value(PARAM_INT, 'Files downloaded into the library'),
            'renamed' => new external_multiple_structure(new external_single_structure([
                'from' => new external_value(PARAM_RAW, 'Slug of the version'),
                'to' => new external_value(PARAM_RAW, 'Slug here (the other one was taken)'),
            ])),
            'refused' => new external_multiple_structure(new external_single_structure([
                'slug' => new external_value(PARAM_RAW, 'Slug'),
                'message' => new external_value(PARAM_RAW, 'Why the file was refused'),
            ])),
            'findings' => new external_multiple_structure(new external_single_structure([
                'code' => new external_value(PARAM_RAW, 'Code (template_source_changed, js_unsigned...)'),
                'severity' => new external_value(PARAM_ALPHA, 'warning or blocking'),
                'target' => new external_value(PARAM_RAW, 'Target, empty if none'),
                'message' => new external_value(PARAM_RAW, 'Message of Brandable'),
            ])),
            'errorcode' => new external_value(PARAM_RAW, 'Error of the cloud, empty if none'),
            'errormessage' => new external_value(PARAM_RAW, 'Shown as is, empty if none'),
            'state' => editor_state::structure(),
        ]);
    }
}
