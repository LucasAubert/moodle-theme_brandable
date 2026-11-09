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
use theme_brandable\local\assets\library;

/**
 * Updates the description of an asset (slug while unused, alternative text, font family, weight and style).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_asset extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Asset'),
            'slug' => new external_value(PARAM_RAW_TRIMMED, 'Slug'),
            'alt' => new external_value(PARAM_TEXT, 'Alternative text (images)', VALUE_DEFAULT, ''),
            'family' => new external_value(PARAM_TEXT, 'Font family', VALUE_DEFAULT, ''),
            'weight' => new external_value(PARAM_INT, 'Font weight', VALUE_DEFAULT, 0),
            'style' => new external_value(PARAM_ALPHA, 'Font style', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Updates an asset.
     *
     * @param int $id Asset.
     * @param string $slug Slug.
     * @param string $alt Alternative text.
     * @param string $family Font family.
     * @param int $weight Font weight.
     * @param string $style Font style.
     * @return array Library.
     */
    public static function execute(
        int $id,
        string $slug,
        string $alt = '',
        string $family = '',
        int $weight = 0,
        string $style = ''
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), compact('id', 'slug', 'alt', 'family', 'weight', 'style'));
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('theme/brandable:edit', $context);

        $data = ['slug' => $params['slug'], 'alt' => $params['alt']];
        if ($params['family'] !== '') {
            $data += ['family' => $params['family'], 'weight' => $params['weight'], 'style' => $params['style']];
        }
        library::update($params['id'], $data);
        return get_assets::export_library();
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return get_assets::library_structure();
    }
}
