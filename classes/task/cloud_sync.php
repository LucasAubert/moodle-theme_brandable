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

use theme_brandable\local\account\api_exception;
use theme_brandable\local\account\connection;
use theme_brandable\local\cloud\backup;
use theme_brandable\local\cloud\cloud;
use theme_brandable\local\cloud\proposals;

/**
 * Cloud of the Pro offer (spec C7, C16): reads the state of the project, catches up when Brandable knows no version
 * for this environment, then sends the queue of the versions to store. Nothing without a connected site.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cloud_sync extends \core\task\scheduled_task {
    #[\Override]
    public function get_name(): string {
        return get_string('task:cloudsync', 'theme_brandable');
    }

    #[\Override]
    public function execute(): void {
        if (!connection::is_connected()) {
            return;
        }
        try {
            $state = cloud::get_state(true);
        } catch (api_exception $e) {
            // Cloud not available (503), no cloud (403): tried again at the next run.
            mtrace('theme_brandable: cloud state not read (' . $e->apicode . ')');
            return;
        }
        $access = $state['access'] ?? cloud::NONE;
        if ($access !== cloud::NONE) {
            // A new proposal for this environment: its administrators notified once (spec C16).
            $notified = proposals::notify($state);
            if ($notified) {
                mtrace("theme_brandable: cloud: proposal notified to $notified user(s)");
            }
        }
        if ($access !== cloud::FULL) {
            return;
        }
        $queued = backup::catch_up($state);
        $counts = backup::process(240);
        mtrace("theme_brandable: cloud: $queued version(s) queued to catch up, {$counts['sent']} sent, "
            . "{$counts['waiting']} waiting, {$counts['stopped']} stopped");
    }
}
