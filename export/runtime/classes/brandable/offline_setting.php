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

namespace theme_brandablert\brandable;

/**
 * Offline licence pasted by the administrator: checked when saved, its end shown.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class offline_setting extends \admin_setting_configtextarea {
    /**
     * Checks the token pasted.
     *
     * @param string $data Token.
     * @return bool|string True, or the error shown.
     */
    public function validate($data) {
        $data = trim((string) $data);
        if ($data === '') {
            return true;
        }
        $error = licence::check_offline($data);
        return $error === null ? true : get_string($error, licence::COMPONENT);
    }

    /**
     * Saves the token, then records the state of the licence.
     *
     * @param string $data Token.
     * @return string Empty, or the error.
     */
    public function write_setting($data) {
        $result = parent::write_setting(trim((string) $data));
        if ($result === '') {
            licence::refresh();
        }
        return $result;
    }
}
