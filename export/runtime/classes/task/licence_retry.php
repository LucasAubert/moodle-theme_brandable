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

namespace theme_brandablert\task;

use theme_brandablert\brandable\licence;

/**
 * Daily signal tried again after a failure: 1 h, 4 h, then 12 h later at most (or after Retry-After).
 *
 * @package    theme_brandablert
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class licence_retry extends \core\task\adhoc_task {
    /** @var int[] Delays of the retries. */
    private const DELAYS = [HOURSECS, 4 * HOURSECS, 12 * HOURSECS];

    /**
     * Queues the next try after a failure.
     *
     * @param int|null $retry Result of licence::heartbeat(): null when done, 0 or the seconds to wait.
     * @param int $attempt Retries already made.
     */
    public static function schedule(?int $retry, int $attempt): void {
        if ($retry === null || $attempt >= count(self::DELAYS)) {
            return;
        }
        $task = new self();
        $task->set_custom_data(['attempt' => $attempt + 1]);
        $task->set_next_run_time(time() + max($retry, self::DELAYS[$attempt]));
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Runs the task.
     */
    public function execute(): void {
        self::schedule(licence::heartbeat(), (int) ($this->get_custom_data()->attempt ?? 1));
    }
}
