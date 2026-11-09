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
use theme_brandable\local\overrides\revision_manager;

/**
 * Undoes the draft (moves it to its parent revision).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class undo extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Undoes the draft (moves it to its parent revision).
     *
     * @return array Editor state.
     */
    public static function execute(): array {
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('theme/brandable:edit', $context);
        // The editor is in use: its iframe must show the draft.
        \theme_brandable\local\preview::keep_alive();

        revision_manager::undo();
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
