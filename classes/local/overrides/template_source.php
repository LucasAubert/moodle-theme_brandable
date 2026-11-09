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

namespace theme_brandable\local\overrides;

use core_component;
use theme_brandable\local\parent_theme;

/**
 * Access to the original (non overridden) templates, as resolved from the filesystem for Brandable:
 * the parent theme chain first, then the component.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_source {
    /**
     * Returns the original source of a template, or null when no file provides it.
     *
     * @param string $name Template name (component/name).
     * @return string|null
     */
    public static function get_original(string $name): ?string {
        $path = self::get_original_path($name);
        if ($path === null) {
            return null;
        }
        $source = file_get_contents($path);
        return $source === false ? null : $source;
    }

    /**
     * Returns the file providing a template for Brandable, or null.
     *
     * Same order as core\output\mustache_template_finder (theme, its parents, then the component), but
     * without touching $PAGE: the finder reads $PAGE->theme, which would initialise the theme of the
     * current page as a side effect (AJAX, web services, CLI).
     *
     * @param string $name Template name (component/name).
     * @return string|null
     */
    public static function get_original_path(string $name): ?string {
        global $CFG;

        if (strpos($name, '/') === false) {
            return null;
        }
        [$component, $template] = explode('/', $name, 2);
        if (clean_param($component, PARAM_COMPONENT) !== $component || strpos($template, '..') !== false) {
            return null;
        }
        $componentdir = core_component::get_component_directory($component);
        if ($componentdir === null) {
            return null;
        }

        $dirs = [];
        foreach (array_merge(['brandable'], parent_theme::get_chain(parent_theme::get_name())) as $theme) {
            $dirs[] = "{$CFG->dirroot}/theme/{$theme}/templates/{$component}/";
            if (!empty($CFG->themedir)) {
                $dirs[] = "{$CFG->themedir}/{$theme}/templates/{$component}/";
            }
        }
        $dirs[] = "{$componentdir}/templates/";

        foreach ($dirs as $dir) {
            if (is_readable($dir . $template . '.mustache')) {
                return $dir . $template . '.mustache';
            }
        }
        return null;
    }

    /**
     * sha256 of a template source, line endings normalised to "\n" (null for null).
     *
     * @param string|null $source Source.
     * @return string|null
     */
    public static function hash(?string $source): ?string {
        if ($source === null) {
            return null;
        }
        return hash('sha256', str_replace(["\r\n", "\r"], "\n", $source));
    }

    /**
     * Returns the source hash of the original template (null for a new template).
     *
     * @param string $name Template name.
     * @return string|null
     */
    public static function get_original_hash(string $name): ?string {
        return self::hash(self::get_original($name));
    }

    /**
     * Returns the installed Moodle release as X.Y.Z.
     *
     * @return string
     */
    public static function get_moodle_version(): string {
        global $CFG;
        if (!preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', (string) $CFG->release, $matches)) {
            return '0.0.0';
        }
        return $matches[1] . '.' . $matches[2] . '.' . ($matches[3] ?? '0');
    }
}
