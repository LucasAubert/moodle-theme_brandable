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
use theme_brandable\local\js\consent;
use theme_brandable\local\js\modules;

/**
 * Kill switch of the generated JavaScript (spec B2): stops or starts again the execution of every module, without
 * calling Brandable.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_js_suspended extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'suspended' => new external_value(PARAM_BOOL, 'Whether the modules stop'),
        ]);
    }

    /**
     * Turns the kill switch on or off.
     *
     * @param bool $suspended Whether the modules stop.
     * @return array
     */
    public static function execute(bool $suspended): array {
        $params = self::validate_parameters(self::execute_parameters(), ['suspended' => $suspended]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability(consent::CAPABILITY, $context);
        consent::set_suspended($params['suspended']);
        return ['suspended' => modules::is_suspended()];
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'suspended' => new external_value(PARAM_BOOL, 'Whether the kill switch is on'),
        ]);
    }
}
