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
use theme_brandable\local\generator\generation_manager;

/**
 * Polled by the editor: relays the generator status and returns the generation (and the editor state
 * once a revision was created).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_generation extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Generation id'),
        ]);
    }

    /**
     * Returns a generation, after relaying the generator status.
     *
     * @param int $id Generation id.
     * @return array Generation.
     */
    public static function execute(int $id): array {
        $params = self::validate_parameters(self::execute_parameters(), ['id' => $id]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('theme/brandable:edit', $context);
        // The editor is in use: its iframe must show the draft.
        \theme_brandable\local\preview::keep_alive();
        $generation = generation_manager::export(generation_manager::refresh($params['id']));
        if ($generation['status'] !== generation_manager::RUNNING) {
            $generation['state'] = editor_state::export();
        }
        return $generation;
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return generation_exporter::structure();
    }
}
