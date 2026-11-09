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
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\revision_manager;
use theme_brandable\local\validation\validation_exception;

/**
 * Lets a remote backend push changes directly (web service): validated, then stored as a new draft revision.
 *
 * Validation errors are returned (not thrown) in the repair format, so that the caller can fix them.
 * JavaScript changes are refused (D10).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class apply_changes extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'changes' => new external_multiple_structure(new external_single_structure([
                'type' => new external_value(PARAM_ALPHA, 'template, scss, setting or js'),
                'target' => new external_value(PARAM_RAW, 'Template name or SCSS chunk'),
                'content' => new external_value(PARAM_RAW, 'Content, empty when deleted', VALUE_DEFAULT, ''),
                'deleted' => new external_value(PARAM_BOOL, 'Whether the override is removed', VALUE_DEFAULT, false),
                'sourceHash' => new external_value(
                    PARAM_ALPHANUM,
                    'sha256 of the original template, empty if none',
                    VALUE_DEFAULT,
                    ''
                ),
                'moodleVersion' => new external_value(
                    PARAM_RAW,
                    'Moodle version the change was made for (informative)',
                    VALUE_DEFAULT,
                    ''
                ),
            ])),
            'summary' => new external_value(PARAM_RAW, 'Summary of the changes'),
            'prompt' => new external_value(PARAM_RAW, 'Prompt that produced the changes', VALUE_DEFAULT, ''),
            'expecteddraft' => new external_value(
                PARAM_INT,
                'Fails if the draft is not this revision (-1: no check)',
                VALUE_DEFAULT,
                -1
            ),
        ]);
    }

    /**
     * Validates and stores the changes as a new draft revision.
     *
     * @param array $changes Changes.
     * @param string $summary Summary.
     * @param string $prompt Prompt.
     * @param int $expecteddraft Expected draft revision, -1 for no check.
     * @return array
     */
    public static function execute(array $changes, string $summary, string $prompt = '', int $expecteddraft = -1): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'changes' => $changes, 'summary' => $summary, 'prompt' => $prompt, 'expecteddraft' => $expecteddraft,
        ]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('theme/brandable:edit', $context);

        $items = array_map(function (array $item): change {
            if ($item['sourceHash'] === '') {
                // An empty hash means "new template" (null in the contract).
                $item['sourceHash'] = null;
            }
            return change::from_array($item);
        }, $params['changes']);
        // JavaScript modules only come from the generations of Brandable (compiled and signed, spec B5): never pushed
        // here, by anyone with theme/brandable:edit. Removing one is allowed.
        $errors = [];
        foreach ($items as $item) {
            if ($item->type === change::TYPE_JS && !$item->deleted) {
                $errors[] = ['target' => $item->target, 'message' => get_string('error:jsgenerationonly', 'theme_brandable')];
            }
        }
        if ($errors) {
            return ['success' => false, 'revisionid' => 0, 'errors' => $errors, 'state' => editor_state::export()];
        }

        try {
            $revisionid = revision_manager::create(
                $items,
                $params['prompt'],
                $params['summary'],
                null,
                $params['expecteddraft'] >= 0 ? $params['expecteddraft'] : null
            );
        } catch (validation_exception $e) {
            return ['success' => false, 'revisionid' => 0, 'errors' => $e->errors, 'state' => editor_state::export()];
        } catch (\moodle_exception $e) {
            if ($e->errorcode !== 'error:draftchanged') {
                throw $e;
            }
            $errors = [['target' => '', 'message' => $e->getMessage()]];
            return ['success' => false, 'revisionid' => 0, 'errors' => $errors, 'state' => editor_state::export()];
        }
        return ['success' => true, 'revisionid' => $revisionid, 'errors' => [], 'state' => editor_state::export()];
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether a revision was created'),
            'revisionid' => new external_value(PARAM_INT, 'Created revision, 0 if none'),
            'errors' => new external_multiple_structure(new external_single_structure([
                'target' => new external_value(PARAM_RAW, 'Target of the error, empty when global'),
                'message' => new external_value(PARAM_RAW, 'Message'),
            ])),
            'state' => editor_state::structure(),
        ]);
    }
}
