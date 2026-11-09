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
use theme_brandable\local\account\api_exception;
use theme_brandable\local\js\consent;
use theme_brandable\local\js\modules;

/**
 * Returns the text of the consent to the generated JavaScript (contract 12.7), and its state on the site.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_js_consent extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Returns the text and the state.
     *
     * @return array
     */
    public static function execute(): array {
        $context = context_system::instance();
        self::validate_context($context);
        require_capability(consent::CAPABILITY, $context);
        $terms = ['version' => '', 'text' => ''];
        $error = '';
        try {
            $terms = consent::get_terms(true);
        } catch (api_exception $e) {
            $error = $e->displaymessage;
        }
        return self::export($terms, $error);
    }

    /**
     * Exports the text and the state of the site.
     *
     * @param array $terms ['version', 'text'].
     * @param string $error Why the text could not be read, empty if it was.
     * @return array
     */
    public static function export(array $terms, string $error = ''): array {
        return [
            'version' => (string) $terms['version'],
            'text' => (string) $terms['text'],
            'enabled' => modules::is_enabled(),
            'accepted' => consent::get_version(),
            'current' => modules::is_enabled() && $terms['version'] !== '' && consent::get_version() === $terms['version'],
            'suspended' => modules::is_suspended(),
            'errormessage' => $error,
        ];
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return self::structure();
    }

    /**
     * Describes the text and the state.
     *
     * @return external_single_structure
     */
    public static function structure(): external_single_structure {
        return new external_single_structure([
            'version' => new external_value(PARAM_RAW, 'Version of the current text, empty if it could not be read'),
            'text' => new external_value(PARAM_RAW, 'Current text, paragraphs separated by an empty line (plain text)'),
            'enabled' => new external_value(PARAM_BOOL, 'Whether the generated JavaScript is on'),
            'accepted' => new external_value(PARAM_RAW, 'Version accepted at the last activation, empty if none'),
            'current' => new external_value(PARAM_BOOL, 'Whether it is on with the current version of the text'),
            'suspended' => new external_value(PARAM_BOOL, 'Whether the kill switch is on'),
            'errormessage' => new external_value(PARAM_RAW, 'Why the text could not be read, empty if it was'),
        ]);
    }
}
