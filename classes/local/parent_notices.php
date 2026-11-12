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

/**
 * What the parent theme applies after Brandable, told in the editor.
 *
 * - Custom CSS: a parent with a custom CSS setting added by its csspostprocess, so after the SCSS of Brandable.
 * - Dark mode: a parent with a dark mode of its own (not the colour modes of Moodle), which recolours the pages in
 *   JavaScript, the changes of Brandable included.
 * Neither is changed by Brandable: the editor only says so, while the setting is on.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class parent_notices {
    /**
     * @var array[] Parent themes with a dark mode of their own: its setting, and the value that turns it off (empty or
     *     any other value: on).
     */
    private const DARK_MODES = [
        'remui' => ['setting' => 'enabledarkmode', 'off' => 'disable'],
    ];

    /**
     * The notices of the parent theme of Brandable.
     *
     * @param string|null $parent Parent theme, the one of Brandable when null.
     * @return array[] [['key' => string, 'text' => string]], in a fixed order.
     */
    public static function get(?string $parent = null): array {
        $notices = [];
        foreach (parent_theme::get_chain($parent ?? parent_theme::get_name()) as $name) {
            $config = parent_theme::load_config($name);
            if ($config === null) {
                continue;
            }
            if (!empty($config->csspostprocess) && trim((string) get_config('theme_' . $name, 'customcss')) !== '') {
                $notices['customcss'] = true;
            }
            $dark = self::DARK_MODES[$name] ?? null;
            if ($dark !== null && get_config('theme_' . $name, $dark['setting']) !== $dark['off']) {
                $notices['darkmode'] = true;
            }
        }
        return array_map(fn(string $key): array => [
            'key' => $key,
            'text' => get_string('parentnotice:' . $key, 'theme_brandable'),
        ], array_values(array_intersect(['customcss', 'darkmode'], array_keys($notices))));
    }
}
