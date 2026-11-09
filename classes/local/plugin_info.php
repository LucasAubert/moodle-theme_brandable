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

use theme_brandable\local\overrides\template_source;

/**
 * Identity of the plugin sent to Brandable with every request (contract 15.1 and 15.2, spec U1).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class plugin_info {
    /**
     * @var string[] What the code of the plugin knows how to apply (contract 15.2). Static: what depends on the site
     * (colour modes of 5.3, JavaScript turned on) is sent in the bodies of the requests.
     */
    public const CAPABILITIES = ['scss-chunks', 'templates', 'scope-cm', 'js', 'assets', 'fonts', 'cloud', 'brandable-dark'];

    /** @var array|null Version and release of the code, read once per request. */
    private static ?array $plugin = null;

    /**
     * Returns the version and release of the code running ($plugin of version.php, through the plugin manager).
     *
     * @return array ['version' => int, 'release' => string]
     */
    private static function plugin(): array {
        if (self::$plugin === null) {
            $info = \core_plugin_manager::instance()->get_plugin_info('theme_brandable');
            self::$plugin = [
                'version' => (int) ($info->versiondisk ?? get_config('theme_brandable', 'version')),
                'release' => (string) ($info->release ?? ''),
            ];
        }
        return self::$plugin;
    }

    /**
     * Returns $plugin->version (10 digits).
     *
     * @return int
     */
    public static function version(): int {
        return self::plugin()['version'];
    }

    /**
     * Returns $plugin->release (X.Y.Z).
     *
     * @return string
     */
    public static function release(): string {
        return self::plugin()['release'];
    }

    /**
     * Returns the headers identifying the plugin.
     *
     * @return string[]
     */
    public static function headers(): array {
        // Printable ASCII only in a header.
        $release = preg_replace('~[^\x21-\x7e]~', '', self::release());
        return [
            'X-Brandable-Plugin-Version: ' . self::version(),
            'X-Brandable-Plugin-Release: ' . $release,
            'X-Brandable-Moodle-Version: ' . template_source::get_moodle_version(),
            'X-Brandable-Capabilities: ' . implode(',', self::CAPABILITIES),
        ];
    }

    /**
     * Returns the capabilities of a list that this plugin does not have.
     *
     * @param array $capabilities Capabilities (contract 15.2).
     * @return string[]
     */
    public static function missing(array $capabilities): array {
        $capabilities = array_filter(array_map('strval', $capabilities), fn($c) => preg_match('~^[a-z0-9-]+$~', $c));
        return array_values(array_unique(array_diff($capabilities, self::CAPABILITIES)));
    }

    /**
     * Forgets the version read (tests).
     */
    public static function reset(): void {
        self::$plugin = null;
    }
}
