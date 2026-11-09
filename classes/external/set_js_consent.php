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
use theme_brandable\local\generator\generation_manager;
use theme_brandable\local\js\consent;

/**
 * Turns the generated JavaScript on (consent to the text read) or off (contract 12.7, spec B3); once on, sends again
 * the request that needed it.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_js_consent extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'enabled' => new external_value(PARAM_BOOL, 'Turn the JavaScript on (consent) or off'),
            'version' => new external_value(PARAM_RAW, 'Version of the text read (activation)', VALUE_DEFAULT, ''),
            'resendid' => new external_value(PARAM_INT, 'Generation to send again once on, 0 if none', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Turns the JavaScript on or off.
     *
     * @param bool $enabled Whether to turn it on.
     * @param string $version Version of the text read.
     * @param int $resendid Generation to send again once on.
     * @return array
     */
    public static function execute(bool $enabled, string $version = '', int $resendid = 0): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['enabled' => $enabled, 'version' => $version, 'resendid' => $resendid]
        );
        $context = context_system::instance();
        self::validate_context($context);
        require_capability(consent::CAPABILITY, $context);

        if (!$params['enabled']) {
            $recorded = consent::disable();
            return ['status' => 'disabled', 'warning' => $recorded ? '' : get_string('jsconsent:notrecorded', 'theme_brandable'),
                'consent' => get_js_consent::export(['version' => consent::get_version(), 'text' => ''])];
        }
        $version = clean_param(trim($params['version']), PARAM_NOTAGS);
        if ($version === '') {
            throw new \invalid_parameter_exception('version');
        }
        $current = consent::enable($version);
        if ($current !== null) {
            // The text changed since it was shown: shown again, nothing recorded.
            return ['status' => 'conflict', 'warning' => get_string('jsconsent:changed', 'theme_brandable'),
                'consent' => get_js_consent::export($current)];
        }
        $result = ['status' => 'enabled', 'warning' => '',
            'consent' => get_js_consent::export(['version' => $version, 'text' => ''])];
        if ($params['resendid']) {
            require_capability('theme/brandable:edit', $context);
            \theme_brandable\local\preview::keep_alive();
            $result['generation'] = generation_manager::export(generation_manager::resend($params['resendid']));
        }
        return $result;
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $generation = generation_exporter::structure();
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'enabled, disabled, or conflict (the text changed: read it again)'),
            'warning' => new external_value(PARAM_RAW, 'Shown to the administrator, empty if none'),
            'consent' => get_js_consent::structure(),
            'generation' => new external_single_structure(
                $generation->keys,
                'Request sent again once on',
                VALUE_OPTIONAL
            ),
        ]);
    }
}
