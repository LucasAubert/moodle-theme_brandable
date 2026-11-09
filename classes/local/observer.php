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

namespace theme_brandable\local;

use core\event\config_log_created;

/**
 * Event observers of theme_brandable.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Resyncs the mirror when a setting of the parent theme is changed through the admin UI.
     *
     * Changes made with set_config() directly are not logged by core: the scheduled task covers them.
     *
     * @param config_log_created $event The event.
     */
    public static function config_log_created(config_log_created $event): void {
        $plugin = $event->other['plugin'] ?? null;
        $name = $event->other['name'] ?? null;
        if (!is_string($plugin) || !is_string($name)) {
            return;
        }
        $parent = parent_theme::get_name();
        if ($plugin === 'theme_' . $parent) {
            settings_mirror::sync_setting($name, $parent);
        }
    }
}
