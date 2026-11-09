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

namespace theme_brandable\local\generator;

use theme_brandable\local\account\connection;

/**
 * Selects the active generator (setting brandable_generator).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class factory {
    /** @var string Setting holding the generator name. */
    public const SETTING = 'brandable_generator';

    /** @var string Default generator in dev mode. */
    public const DEFAULT = 'mock';

    /** @var string[] Generators, by name. */
    private const GENERATORS = [
        'mock' => mock_generator::class,
        'remote' => remote_generator::class,
    ];

    /**
     * Returns the name of the active generator: the Brandable API, or the one chosen in dev mode.
     *
     * @return string
     */
    public static function get_name(): string {
        if (!connection::is_devmode()) {
            return 'remote';
        }
        $name = (string) get_config('theme_brandable', self::SETTING);
        return isset(self::GENERATORS[$name]) ? $name : self::DEFAULT;
    }

    /**
     * Returns a generator.
     *
     * @param string|null $name Generator name, the active one when null.
     * @return generator_interface
     */
    public static function get(?string $name = null): generator_interface {
        $class = self::GENERATORS[$name ?? self::get_name()] ?? self::GENERATORS[self::DEFAULT];
        return new $class();
    }

    /**
     * Choices for the settings page.
     *
     * @return array
     */
    public static function get_choices(): array {
        return [
            'mock' => new \lang_string('generator_mock', 'theme_brandable'),
            'remote' => new \lang_string('generator_remote', 'theme_brandable'),
        ];
    }
}
