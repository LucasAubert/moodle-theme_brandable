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

use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Structure of a generation returned to the editor.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generation_exporter {
    /**
     * Describes a generation.
     *
     * @return external_single_structure
     */
    public static function structure(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Generation id'),
            'status' => new external_value(PARAM_ALPHA, 'running, completed, failed, question, jsactivation or jsresent'),
            'prompt' => new external_value(PARAM_RAW, 'Prompt'),
            'summary' => new external_value(PARAM_RAW, 'Summary of the created revision, or the question asked'),
            'revisionid' => new external_value(PARAM_INT, 'Created revision, 0 if none'),
            'repairs' => new external_value(PARAM_INT, 'Repairs requested by the plugin'),
            'events' => new external_multiple_structure(new external_single_structure([
                'type' => new external_value(PARAM_ALPHANUMEXT, 'Event type'),
                'message' => new external_value(PARAM_RAW, 'Message, shown as is'),
                'at' => new external_value(PARAM_RAW, 'ISO 8601 date'),
                'run' => new external_value(PARAM_INT, '0: initial generation, 1..n: repairs'),
            ])),
            'errorcode' => new external_value(PARAM_RAW, 'Error code when failed'),
            'errormessage' => new external_value(PARAM_RAW, 'Error message when failed, shown as is'),
            'retryable' => new external_value(PARAM_BOOL, 'The same prompt can be sent again (service failure, credits, plan)'),
            'waitinguntil' => new external_value(PARAM_INT, 'Brandable unavailable: seconds before the progress is read again'),
            'retryat' => new external_value(PARAM_INT, 'Brandable unavailable at the start: time of the new attempt, 0 if none'),
            'action' => new external_value(PARAM_ALPHA, 'Account error: connect, relink, credits, plan, or empty'),
            'errorurl' => new external_value(PARAM_RAW, 'Page where the administrator can act on the error, empty if none'),
            'selection' => new external_value(PARAM_RAW, 'Element targeted in the preview (JSON), empty if none'),
            'pageurl' => new external_value(PARAM_RAW, 'Page shown in the preview when the prompt was sent'),
            'questionid' => new external_value(PARAM_INT, 'Generation whose question the prompt answers, 0 if none'),
            'resumable' => new external_value(PARAM_BOOL, 'The fonts could not be downloaded: can be tried again as is'),
            'attachments' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Image of the library attached to the prompt')
            ),
            'state' => new external_single_structure(editor_state::structure()->keys, 'Editor state', VALUE_OPTIONAL),
        ]);
    }
}
