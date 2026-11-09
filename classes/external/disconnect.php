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

namespace theme_brandable\external;

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use theme_brandable\local\account\account;
use theme_brandable\local\account\api_exception;

/**
 * Disconnects the site from its Brandable account (revokes its key).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class disconnect extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'force' => new external_value(PARAM_BOOL, 'Forget the key even if Brandable cannot revoke it', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Disconnects the site.
     *
     * @param bool $force Forget the key even if Brandable cannot revoke it.
     * @return array ['disconnected' => bool, 'errormessage' => string].
     */
    public static function execute(bool $force = false): array {
        $params = self::validate_parameters(self::execute_parameters(), ['force' => $force]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);
        try {
            account::disconnect($params['force']);
        } catch (api_exception $e) {
            return ['disconnected' => false, 'errormessage' => $e->displaymessage];
        }
        return ['disconnected' => true, 'errormessage' => ''];
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'disconnected' => new external_value(PARAM_BOOL, 'Whether the key is forgotten'),
            'errormessage' => new external_value(PARAM_RAW, 'Why Brandable could not revoke the key, shown as is'),
        ]);
    }
}
