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
use theme_brandable\local\assets\markers;
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\resolver;
use theme_brandable\local\overrides\revision_manager;

/**
 * Returns the template overrides of a revision (all scopes), for the templates rendered in JavaScript.
 *
 * The published revision is public (what every page renders anyway, login not required, cacheable since a
 * revision never changes). Any other revision (the draft, for the preview) requires theme/brandable:edit.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_template_overrides extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'revisionid' => new external_value(PARAM_INT, 'Revision id'),
            'assets' => new external_value(PARAM_INT, 'Revision of the library (part of the HTTP cache key: the contents '
                . 'carry the URLs of the assets)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Returns the template overrides of a revision.
     *
     * @param int $revisionid Revision id.
     * @param int $assets Revision of the library (cache key only).
     * @return array
     */
    public static function execute(int $revisionid, int $assets = 0): array {
        global $PAGE;

        $params = self::validate_parameters(self::execute_parameters(), ['revisionid' => $revisionid, 'assets' => $assets]);
        $context = context_system::instance();
        if ($params['revisionid'] === revision_manager::get_published()) {
            // Public, as core_output_load_template: validate_context() would require a login.
            $PAGE->set_context($context);
        } else {
            self::validate_context($context);
            require_capability('theme/brandable:edit', $context);
        }

        $templates = [];
        foreach (resolver::get_map($params['revisionid'])[change::TYPE_TEMPLATE] as $entry) {
            $templates[] = [
                'name' => $entry['target'],
                'scopelevel' => $entry['scopelevel'],
                'scopevalue' => (string) $entry['scopevalue'],
                'contenthash' => $entry['contenthash'],
                // Markers of the library resolved (contract 11.3).
                'content' => markers::template($entry['content']),
            ];
        }
        return ['revisionid' => $params['revisionid'], 'templates' => $templates];
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'revisionid' => new external_value(PARAM_INT, 'Revision id'),
            'templates' => new external_multiple_structure(new external_single_structure([
                'name' => new external_value(PARAM_RAW, 'Template name'),
                'scopelevel' => new external_value(PARAM_ALPHA, 'Scope level'),
                'scopevalue' => new external_value(PARAM_RAW, 'Scope value, empty for the site'),
                'contenthash' => new external_value(PARAM_ALPHANUM, 'sha256 of the content (the page picks its variant by hash)'),
                'content' => new external_value(PARAM_RAW, 'Override content'),
            ])),
        ]);
    }
}
