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
use theme_brandable\local\overrides\version_note;

/**
 * Proposes the note of the version about to be published, for the administrator to edit.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class propose_version_note extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Proposes the note.
     *
     * @return array ['note' => proposed note, 'maxlength' => int].
     */
    public static function execute(): array {
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('theme/brandable:edit', $context);
        return ['note' => version_note::propose(), 'maxlength' => \theme_brandable\local\overrides\revision_manager::MAX_NOTE];
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'note' => new external_value(PARAM_TEXT, 'Proposed note, plain text (lines separated by new lines)'),
            'maxlength' => new external_value(PARAM_INT, 'Maximum length of the note'),
        ]);
    }
}
