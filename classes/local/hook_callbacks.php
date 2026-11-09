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

use core\hook\output\before_http_headers;
use core\hook\output\before_standard_top_of_body_html_generation;
use moodle_url;
use theme_brandable\local\account\connection;

/**
 * Hook callbacks of theme_brandable.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Adds the preview banner to the pages rendered with Brandable in preview mode.
     *
     * @param before_standard_top_of_body_html_generation $hook The hook.
     */
    public static function before_standard_top_of_body_html_generation(before_standard_top_of_body_html_generation $hook): void {
        global $PAGE;

        if ($PAGE->theme->name !== 'brandable' || !preview::is_active()) {
            return;
        }
        $exiturl = new moodle_url('/theme/brandable/preview.php', [
            'sesskey' => sesskey(),
            'returnurl' => $PAGE->has_set_url() ? $PAGE->url->out_as_local_url(false) : '/',
        ]);
        $hook->add_html($hook->renderer->render_from_template('theme_brandable/preview_banner', [
            'revision' => preview::get_revision(),
            'exiturl' => $exiturl->out(false),
        ]));
    }

    /**
     * Reminds the administrators, on the admin pages, that the site is not connected to Brandable.
     *
     * @param before_http_headers $hook The hook.
     */
    public static function before_http_headers(before_http_headers $hook): void {
        global $CFG;

        if (during_initial_install() || !empty($CFG->upgraderunning) || AJAX_SCRIPT || CLI_SCRIPT) {
            return;
        }
        self::remind_connection();
    }

    /**
     * Adds the reminder to connect the site, on an admin page, for an administrator, while it is not connected.
     */
    public static function remind_connection(): void {
        global $PAGE;

        $connecturl = new moodle_url('/theme/brandable/connect.php');
        if ($PAGE->pagelayout !== 'admin' || ($PAGE->has_set_url() && $PAGE->url->compare($connecturl, URL_MATCH_BASE))) {
            return;
        }
        if (!has_capability('moodle/site:config', \context_system::instance()) || connection::is_ready()) {
            return;
        }
        $button = \html_writer::link(
            $connecturl,
            get_string('connect:button', 'theme_brandable'),
            ['class' => 'btn btn-sm btn-primary text-white ms-2 ml-2']
        );
        \core\notification::warning(get_string('connect:banner', 'theme_brandable') . ' ' . $button);
    }
}
