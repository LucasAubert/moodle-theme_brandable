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
 * Sends a prompt to the active generator. The editor then polls get_generation.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submit_prompt extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'prompt' => new external_value(PARAM_RAW, 'Prompt'),
            'pageurl' => new external_value(PARAM_URL, 'Page shown in the preview', VALUE_DEFAULT, ''),
            'selection' => new external_value(
                PARAM_RAW,
                'Element targeted in the preview (JSON), empty if none',
                VALUE_DEFAULT,
                ''
            ),
            'questionid' => new external_value(
                PARAM_INT,
                'Generation whose question the prompt answers, 0 if none',
                VALUE_DEFAULT,
                0
            ),
            'attachments' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Image of the library attached to the prompt (its thumbnail is sent)'),
                'Images attached to the prompt, 4 at most',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Sends a prompt.
     *
     * @param string $prompt Prompt.
     * @param string $pageurl Page shown in the preview.
     * @param string $selection Element targeted in the preview (JSON).
     * @param int $questionid Generation whose question the prompt answers, 0 if none.
     * @param int[] $attachments Images of the library attached to the prompt.
     * @return array Generation.
     */
    public static function execute(
        string $prompt,
        string $pageurl = '',
        string $selection = '',
        int $questionid = 0,
        array $attachments = []
    ): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['prompt' => $prompt, 'pageurl' => $pageurl, 'selection' => $selection, 'questionid' => $questionid,
                'attachments' => $attachments]
        );
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('theme/brandable:edit', $context);
        // The editor is in use: its iframe must show the draft.
        \theme_brandable\local\preview::keep_alive();
        $selection = $params['selection'] !== '' ? json_decode($params['selection'], true) : null;
        $record = generation_manager::submit(
            $params['prompt'],
            $params['pageurl'] !== '' ? $params['pageurl'] : null,
            null,
            is_array($selection) ? $selection : null,
            $params['questionid'],
            array_slice($params['attachments'], 0, \theme_brandable\local\assets\request::MAX_THUMBNAILS)
        );
        return generation_manager::export($record);
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
