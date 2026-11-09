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

namespace theme_brandable\task;

use theme_brandable\local\generator\generation_manager;

/**
 * Cleans up generations.
 *
 * Generations that created a revision are kept: their steps are part of the revision history (deleted with
 * the user's data by the privacy provider). Failed attempts are deleted after 30 days, and generations
 * left running for a day are marked as interrupted.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_generations extends \core\task\scheduled_task {
    /** @var int Age after which failed generations are deleted. */
    public const LIFETIME = 30 * DAYSECS;

    /** @var int Age after which a running generation is considered interrupted. */
    public const STALE = DAYSECS;

    /**
     * Returns the task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_cleanupgenerations', 'theme_brandable');
    }

    /**
     * Runs the task.
     */
    public function execute() {
        global $DB;
        $DB->delete_records_select(
            'theme_brandable_generation',
            'status = ? AND timecreated < ?',
            [generation_manager::FAILED, time() - self::LIFETIME]
        );
        $DB->set_field_select(
            'theme_brandable_generation',
            'errormessage',
            get_string('error:interrupted', 'theme_brandable'),
            'status = ? AND timemodified < ?',
            [generation_manager::RUNNING, time() - self::STALE]
        );
        $DB->set_field_select(
            'theme_brandable_generation',
            'errorcode',
            'interrupted',
            'status = ? AND timemodified < ?',
            [generation_manager::RUNNING, time() - self::STALE]
        );
        $DB->set_field_select(
            'theme_brandable_generation',
            'status',
            generation_manager::FAILED,
            'status = ? AND timemodified < ?',
            [generation_manager::RUNNING, time() - self::STALE]
        );
    }
}
