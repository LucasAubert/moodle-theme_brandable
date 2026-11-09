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

/**
 * Back from the Brandable web app: checks the state, exchanges the code for a site key (by the Moodle server).
 *
 * Reached by a redirection from Brandable (GET): the state, kept in the session of the administrator, stands for
 * the session key (see DECISIONS).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login(null, false);
require_capability('moodle/site:config', context_system::instance());

$outcome = \theme_brandable\local\account\account::finish_connection(
    optional_param('state', '', PARAM_RAW_TRIMMED),
    optional_param('code', '', PARAM_RAW_TRIMMED),
    optional_param('error', '', PARAM_ALPHANUMEXT),
    optional_param('error_description', '', PARAM_TEXT)
);
redirect(
    new moodle_url('/theme/brandable/connect.php'),
    $outcome['message'],
    null,
    $outcome['status'] === 'connected' ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR
);
