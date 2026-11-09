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
use theme_brandable\local\inspector\inspector;
use theme_brandable\local\overrides\scope;

/**
 * Returns the templates rendered on a page of the preview (page inspector).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_page_templates extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'url' => new external_value(PARAM_URL, 'Page URL, the last inspected page when empty', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Returns the templates of an inspected page.
     *
     * @param string $url Page URL.
     * @return array
     */
    public static function execute(string $url = ''): array {
        $params = self::validate_parameters(self::execute_parameters(), ['url' => $url]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('theme/brandable:edit', $context);

        $page = inspector::get_page($params['url'] !== '' ? $params['url'] : null);

        // Scopes the editor can offer for this page, from the whole site to the activity.
        $scopes = [new scope()];
        if (!empty($page['pagetype'])) {
            $scopes[] = new scope(scope::PAGETYPE, $page['pagetype']);
        }
        if (!empty($page['pagekey'])) {
            $scopes[] = new scope(scope::PAGE, $page['pagekey']);
        }
        if (!empty($page['courseid'])) {
            $scopes[] = new scope(scope::COURSE, (string) $page['courseid']);
        }
        if (!empty($page['cmid'])) {
            $scopes[] = new scope(scope::CM, (string) $page['cmid']);
        }

        return [
            'scopes' => array_map(fn(scope $scope) => [
                'level' => $scope->level,
                'value' => (string) $scope->value,
                'label' => $scope->label(),
            ], $scopes),
            'found' => $page !== null,
            'url' => $page['url'] ?? '',
            'pagetype' => $page['pagetype'] ?? '',
            'pagekey' => (string) ($page['pagekey'] ?? ''),
            'courseid' => (int) ($page['courseid'] ?? 0),
            'cmid' => (int) ($page['cmid'] ?? 0),
            'time' => $page['time'] ?? 0,
            'templates' => array_map(fn(array $template) => [
                'name' => $template['name'],
                'samplecontext' => json_encode($template['sampleContext']),
            ], $page['templates'] ?? []),
        ];
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'found' => new external_value(PARAM_BOOL, 'Whether the page was inspected'),
            'scopes' => new external_multiple_structure(new external_single_structure([
                'level' => new external_value(PARAM_ALPHA, 'Scope level'),
                'value' => new external_value(PARAM_RAW, 'Scope value, empty for the site'),
                'label' => new external_value(PARAM_TEXT, 'Scope, human readable'),
            ]), 'Scopes available for this page'),
            'url' => new external_value(PARAM_RAW, 'Page URL'),
            'pagetype' => new external_value(PARAM_RAW, 'Page type'),
            'pagekey' => new external_value(PARAM_RAW, 'Brandable page key (bp-*)'),
            'courseid' => new external_value(PARAM_INT, 'Course id, 0 if none'),
            'cmid' => new external_value(PARAM_INT, 'Course module id, 0 if none'),
            'time' => new external_value(PARAM_INT, 'Inspection time'),
            'templates' => new external_multiple_structure(new external_single_structure([
                'name' => new external_value(PARAM_RAW, 'Template name'),
                'samplecontext' => new external_value(PARAM_RAW, 'Sample of the context (JSON), null for partials'),
            ])),
        ]);
    }
}
