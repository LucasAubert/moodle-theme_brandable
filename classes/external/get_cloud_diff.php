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
use theme_brandable\local\cloud\diff;

/**
 * Compares two versions of the project, or a version with the one published here (contract 13.6, spec C14).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_cloud_diff extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'from' => new external_value(PARAM_ALPHANUMEXT, 'Version, or published (the one published here)'),
            'to' => new external_value(PARAM_ALPHANUMEXT, 'Version'),
        ]);
    }

    /**
     * Reads the diff.
     *
     * @param string $from Version, or published.
     * @param string $to Version.
     * @return array
     */
    public static function execute(string $from, string $to): array {
        $params = self::validate_parameters(self::execute_parameters(), ['from' => $from, 'to' => $to]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('theme/brandable:edit', $context);
        $empty = ['fromnumber' => 0, 'tonumber' => 0, 'changes' => [], 'assets' => [], 'findings' => [],
            'moodleversion' => '', 'indexed' => true, 'exact' => true, 'errormessage' => ''];
        try {
            $result = diff::read($params['from'], $params['to']);
        } catch (api_exception $e) {
            return ['errormessage' => $e->displaymessage] + $empty;
        }
        return [
            'fromnumber' => $result['from']['number'] ?? 0,
            'tonumber' => $result['to']['number'],
            'changes' => $result['changes'],
            'assets' => $result['assets'],
            'findings' => $result['findings'],
            'moodleversion' => $result['compatibility']['moodleversion'],
            'indexed' => $result['compatibility']['indexed'],
            'exact' => $result['compatibility']['exact'],
        ] + $empty;
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'fromnumber' => new external_value(PARAM_INT, 'Number of the version compared from, 0 when nothing is published'),
            'tonumber' => new external_value(PARAM_INT, 'Number of the version compared'),
            'changes' => new external_multiple_structure(new external_single_structure([
                'status' => new external_value(PARAM_ALPHA, 'added, removed or modified'),
                'type' => new external_value(PARAM_ALPHA, 'scss, template, setting, js...'),
                'target' => new external_value(PARAM_RAW, 'Target'),
                'scopelabel' => new external_value(PARAM_RAW, 'Scope, human readable'),
                'before' => new external_value(PARAM_RAW, 'Complete content before, empty if none'),
                'after' => new external_value(PARAM_RAW, 'Complete content after, empty if none'),
            ])),
            'assets' => new external_multiple_structure(new external_single_structure([
                'status' => new external_value(PARAM_ALPHA, 'added, removed or modified'),
                'key' => new external_value(PARAM_RAW, 'Slug, or family of a licence'),
                'kind' => new external_value(PARAM_ALPHA, 'image, font or license'),
                'before' => new external_value(PARAM_RAW, 'Text of a licence before, empty otherwise'),
                'after' => new external_value(PARAM_RAW, 'Text of a licence after, empty otherwise'),
            ])),
            'findings' => new external_multiple_structure(new external_single_structure([
                'code' => new external_value(PARAM_RAW, 'Code'),
                'severity' => new external_value(PARAM_ALPHA, 'warning or blocking'),
                'target' => new external_value(PARAM_RAW, 'Target, empty if none'),
                'message' => new external_value(PARAM_RAW, 'Message of Brandable'),
            ])),
            'moodleversion' => new external_value(PARAM_RAW, 'Moodle release of this site, as Brandable read it'),
            'indexed' => new external_value(PARAM_BOOL, 'Whether Brandable knows the templates of this release'),
            'exact' => new external_value(PARAM_BOOL, 'Whether the templates were compared with this exact release'),
            'errormessage' => new external_value(PARAM_RAW, 'Shown as is, empty if none'),
        ]);
    }
}
