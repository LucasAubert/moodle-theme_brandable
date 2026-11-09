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

namespace theme_brandable\tests;

/**
 * Tests of a parent other than Boost, made with Classic: skipped where Classic is not installed (removed from core in
 * Moodle 5.3, where it is a plugin of the Plugins Directory).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait requires_classic {
    /**
     * Whether Classic is installed.
     *
     * @return bool
     */
    protected static function has_classic(): bool {
        return \core_component::get_component_directory('theme_classic') !== null
            && is_dir((string) \core_component::get_component_directory('theme_classic'));
    }

    /**
     * Whether Classic is installed and its SCSS compiles on this Moodle (not the case of its version for 5.2 on 5.3,
     * which lacks the design system tokens of Boost 5.3). Compiled once per process; the debugging message of a failed
     * compilation is cleared.
     *
     * @return bool
     */
    protected function classic_compiles(): bool {
        static $compiles = null;
        if ($compiles === null) {
            $compiles = false;
            if (self::has_classic()) {
                $theme = \theme_config::load('classic');
                $method = new \ReflectionMethod($theme, 'get_css_content_from_scss');
                $css = $method->invoke($theme, false);
                $compiles = is_string($css) && $css !== '';
                $this->resetDebugging();
            }
        }
        return $compiles;
    }

    /**
     * Skips the test where Classic is not installed.
     */
    protected function require_classic(): void {
        if (!self::has_classic()) {
            $this->markTestSkipped('Classic is not installed (removed from core in Moodle 5.3).');
        }
    }
}
