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
use theme_brandable\local\preview;

/**
 * Turns the template markers of the preview on or off (editor switch, for pages that would not display well).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_template_markers extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'enabled' => new external_value(PARAM_BOOL, 'Whether the template markers are enabled'),
        ]);
    }

    /**
     * Sets the template markers switch.
     *
     * @param bool $enabled Enabled.
     * @return array
     */
    public static function execute(bool $enabled): array {
        $params = self::validate_parameters(self::execute_parameters(), ['enabled' => $enabled]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('theme/brandable:edit', $context);
        preview::set_markers($params['enabled']);
        return ['enabled' => preview::markers_enabled()];
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'enabled' => new external_value(PARAM_BOOL, 'Whether the template markers are enabled'),
        ]);
    }
}
