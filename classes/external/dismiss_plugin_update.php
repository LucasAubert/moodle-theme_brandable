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
use theme_brandable\local\plugin_updates;

/**
 * Closes the banner of a new version of the plugin for the current user (spec U3; never for a security update).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dismiss_plugin_update extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'version' => new external_value(PARAM_INT, 'Version of the banner ($plugin->version)'),
        ]);
    }

    /**
     * Closes the banner.
     *
     * @param int $version Version.
     * @return array
     */
    public static function execute(int $version): array {
        $params = self::validate_parameters(self::execute_parameters(), ['version' => $version]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);
        $latest = plugin_updates::available();
        // Only the version offered, and never a security update.
        $dismissed = $latest !== null && !$latest['security'] && $latest['version'] === $params['version'];
        if ($dismissed) {
            plugin_updates::dismiss($params['version']);
        }
        return ['dismissed' => $dismissed];
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'dismissed' => new external_value(PARAM_BOOL, 'Whether the banner was closed'),
        ]);
    }
}
