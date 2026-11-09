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
use theme_brandable\local\plugin_updates;

/**
 * Looks for a new version of the plugin once a day (GET /plugin/latest, contract 15.6, spec U3), on a connected site
 * only, and notifies the administrators of the site. Never installs anything.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_plugin_update extends \core\task\scheduled_task {
    #[\Override]
    public function get_name(): string {
        return get_string('task:checkpluginupdate', 'theme_brandable');
    }

    #[\Override]
    public function execute(): void {
        if (!connection::is_connected()) {
            return;
        }
        try {
            $latest = plugin_updates::check();
            mtrace('theme_brandable: latest version of the plugin: ' . ($latest ? $latest['release'] . ' (' . $latest['version']
                . ')' : 'none'));
        } catch (api_exception $e) {
            // Tried again tomorrow; the last answer read is kept.
            mtrace('theme_brandable: latest version of the plugin not read (' . $e->apicode . ')');
        }
        $notified = plugin_updates::notify();
        if ($notified) {
            mtrace("theme_brandable: update of the plugin notified to $notified user(s)");
        }
    }
}
