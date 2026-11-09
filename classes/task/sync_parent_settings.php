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

use theme_brandable\local\settings_mirror;

/**
 * Safety net: fully resynchronises the parent theme settings mirror.
 *
 * Catches changes the observer cannot see: set_config() calls (CLI, upgrade steps, admin presets) and
 * custom admin_setting classes that write without logging, used on the parent's own settings page.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_parent_settings extends \core\task\scheduled_task {
    /**
     * Returns the task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_syncparentsettings', 'theme_brandable');
    }

    /**
     * Runs the task.
     */
    public function execute() {
        if (settings_mirror::sync_all()) {
            // The change bypassed the parent's callbacks: only rebuild Brandable's CSS.
            theme_set_sub_revision_for_theme('brandable', theme_get_next_sub_revision_for_theme('brandable'));
            mtrace('Brandable: parent theme settings resynchronised.');
        }
    }
}
