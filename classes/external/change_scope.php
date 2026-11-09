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
use theme_brandable\local\overrides\revision_manager;
use theme_brandable\local\overrides\scope;

/**
 * Changes the scope of an override: new draft revision with the same content and the new scope (validated).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class change_scope extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'changeid' => new external_value(PARAM_INT, 'Change whose override is moved'),
            'level' => new external_value(PARAM_ALPHA, 'New scope level: site, pagetype, page, course or cm'),
            'value' => new external_value(PARAM_RAW, 'New scope value, empty for the site', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Changes the scope of an override.
     *
     * @param int $changeid Change id.
     * @param string $level Scope level.
     * @param string $value Scope value.
     * @return array Editor state.
     */
    public static function execute(int $changeid, string $level, string $value = ''): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['changeid' => $changeid, 'level' => $level, 'value' => $value]
        );
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('theme/brandable:edit', $context);
        // The editor is in use: its iframe must show the draft.
        \theme_brandable\local\preview::keep_alive();

        revision_manager::rescope(
            $params['changeid'],
            scope::from_array(['level' => $params['level'], 'value' => $params['value']])
        );
        return editor_state::export();
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return editor_state::structure();
    }
}
