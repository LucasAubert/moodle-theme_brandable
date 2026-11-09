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

use theme_brandable\local\account\account;
use theme_brandable\local\account\api_exception;
use theme_brandable\local\account\connection;
use theme_brandable\local\js\consent;
use theme_brandable\local\js\modules;

/**
 * Reads the version of the text of the consent to the generated JavaScript, and notifies the administrators when it
 * changed while the JavaScript is on (spec B4). Nothing is done while it is off.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_js_consent extends \core\task\scheduled_task {
    #[\Override]
    public function get_name(): string {
        return get_string('task:checkjsconsent', 'theme_brandable');
    }

    #[\Override]
    public function execute(): void {
        if (!modules::is_enabled() || consent::is_mock() || !connection::is_ready()) {
            return;
        }
        try {
            // Read again: account::get() checks the version.
            account::get(true);
        } catch (api_exception $e) {
            mtrace('theme_brandable: account not read (' . $e->apicode . ')');
        }
    }
}
