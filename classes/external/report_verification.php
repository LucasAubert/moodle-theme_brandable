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
use theme_brandable\local\generator\generation_manager;

/**
 * Receives the verification of a generated revision in the preview (selectors run against the real DOM).
 *
 * Selectors matching no element (when conclusive) send the draft back to where the generation started and ask
 * for a repair, within the repair budget; selectors matching elements out of the targeted one are warnings.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_verification extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Generation id'),
            'failures' => new external_multiple_structure(new external_single_structure([
                'target' => new external_value(PARAM_RAW, 'Chunk target'),
                'selector' => new external_value(PARAM_RAW, 'Selector matching no element'),
            ]), 'Selectors matching no element', VALUE_DEFAULT, []),
            'warnings' => new external_multiple_structure(new external_single_structure([
                'target' => new external_value(PARAM_RAW, 'Chunk target'),
                'selector' => new external_value(PARAM_RAW, 'Selector'),
                'count' => new external_value(PARAM_INT, 'Elements matched out of the targeted element'),
            ]), 'Selectors matching elements out of the targeted element', VALUE_DEFAULT, []),
            'checked' => new external_value(PARAM_INT, 'Number of selectors checked', VALUE_DEFAULT, 0),
            'jserrors' => new external_multiple_structure(new external_single_structure([
                'target' => new external_value(PARAM_RAW, 'Target of the module (js/...)'),
                'message' => new external_value(PARAM_RAW, 'Message of the error, 500 characters at most'),
            ]), 'Errors of the JavaScript modules in the preview', VALUE_DEFAULT, []),
            'colourwarnings' => new external_multiple_structure(new external_single_structure([
                'target' => new external_value(PARAM_RAW, 'Chunk target'),
                'selector' => new external_value(PARAM_RAW, 'Selector'),
                'kind' => new external_value(PARAM_ALPHA, 'fixed (colour without a dark variant) or contrast'),
                'mode' => new external_value(PARAM_ALPHA, 'Mode of a contrast warning: light or dark', VALUE_DEFAULT, ''),
                'ratio' => new external_value(PARAM_FLOAT, 'Contrast ratio of a contrast warning', VALUE_DEFAULT, 0),
            ]), 'Colour warnings of the colour modes (Moodle 5.3), never blocking', VALUE_DEFAULT, []),
        ]);
    }

    /**
     * Records the verification.
     *
     * @param int $id Generation id.
     * @param array $failures Selectors matching no element.
     * @param array $warnings Selectors matching elements out of the targeted element.
     * @param int $checked Number of selectors checked.
     * @param array $jserrors Errors of the JavaScript modules in the preview.
     * @param array $colourwarnings Colour warnings of the colour modes.
     * @return array Generation, with the editor state.
     */
    public static function execute(
        int $id,
        array $failures = [],
        array $warnings = [],
        int $checked = 0,
        array $jserrors = [],
        array $colourwarnings = []
    ): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['id' => $id, 'failures' => $failures, 'warnings' => $warnings, 'checked' => $checked, 'jserrors' => $jserrors,
                'colourwarnings' => $colourwarnings]
        );
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('theme/brandable:edit', $context);
        \theme_brandable\local\preview::keep_alive();

        $record = generation_manager::verify(
            $params['id'],
            array_slice($params['failures'], 0, 50),
            array_slice($params['warnings'], 0, 50),
            $params['checked'],
            null,
            array_slice($params['jserrors'], 0, 20),
            array_slice($params['colourwarnings'], 0, 30)
        );
        $generation = generation_manager::export($record);
        $generation['state'] = editor_state::export();
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
