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

use theme_brandable\local\cloud\backup;
use theme_brandable\local\cloud\cloud;

/**
 * Sends the versions queued by a publication, at the next cron (spec C3): the publication answered at once; the task
 * cloud_sync stays the safety net.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cloud_send extends \core\task\adhoc_task {
    #[\Override]
    public function get_name(): string {
        return get_string('task:cloudsend', 'theme_brandable');
    }

    #[\Override]
    public function execute(): void {
        if (cloud::get_access() !== cloud::FULL) {
            return;
        }
        $counts = backup::process(120);
        mtrace("theme_brandable: cloud: {$counts['sent']} sent, {$counts['waiting']} waiting, {$counts['stopped']} stopped");
    }
}
