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
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\resolver;
use theme_brandable\local\overrides\revision_manager;
use theme_brandable\local\overrides\template_source;

/**
 * Returns the original (non overridden) source of a template, and its draft override if any.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_template_source extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'name' => new external_value(PARAM_PATH, 'Template name (component/name)'),
        ]);
    }

    /**
     * Returns the sources of a template.
     *
     * @param string $name Template name.
     * @return array
     */
    public static function execute(string $name): array {
        $params = self::validate_parameters(self::execute_parameters(), ['name' => $name]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('theme/brandable:edit', $context);

        $source = template_source::get_original($params['name']);
        // The site-wide override of the draft (scoped variants are listed in the editor).
        $override = resolver::get_map(revision_manager::get_draft())[change::TYPE_TEMPLATE][$params['name']] ?? null;
        return [
            'name' => $params['name'],
            'exists' => $source !== null,
            'source' => (string) $source,
            'sourcehash' => (string) template_source::hash($source),
            'overridden' => $override !== null,
            'override' => $override['content'] ?? '',
        ];
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'name' => new external_value(PARAM_RAW, 'Template name'),
            'exists' => new external_value(PARAM_BOOL, 'Whether a file provides the template'),
            'source' => new external_value(PARAM_RAW, 'Original source (theme chain, then component)'),
            'sourcehash' => new external_value(PARAM_RAW, 'sha256 of the original source (normalised line endings)'),
            'overridden' => new external_value(PARAM_BOOL, 'Whether the draft overrides the template'),
            'override' => new external_value(PARAM_RAW, 'Draft override'),
        ]);
    }
}
