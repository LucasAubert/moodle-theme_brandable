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
 * Mirrors the settings of the parent theme into theme_brandable.
 *
 * theme_<parent> stays the source of truth. The copy of the config_plugins rows lets $PAGE->theme->settings,
 * setting_file_url() and the parent's SCSS callbacks behave under Brandable exactly as under the parent.
 * Brandable's own settings (prefixed with {@see parent_theme::RESERVED_PREFIX}) and core keys
 * ('version', 'themerev') are never touched.
 *
 * Setting files are NOT copied: setting_file_url() only builds a theme_brandable URL, which
 * theme_brandable_pluginfile() hands over to the parent's pluginfile callback, and the parent reads its own
 * theme_<parent> files (Boost and Classic hardcode their component). See docs/DECISIONS.md (D5).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings_mirror {
    /** @var string Component of the mirror. */
    private const COMPONENT = 'theme_brandable';

    /** @var string[] Per-plugin config managed by core, never mirrored ('themerev' is the per-theme CSS sub revision). */
    private const CORE_KEYS = ['version', 'themerev'];

    /**
     * Fully resynchronises the config values from the parent.
     *
     * @param string|null $parent Parent theme name, the active one when null.
     * @return bool Whether anything changed.
     */
    public static function sync_all(?string $parent = null): bool {
        return self::sync_config($parent ?? parent_theme::get_name());
    }

    /**
     * Resynchronises a single setting value.
     *
     * @param string $name Setting name in theme_<parent>.
     * @param string|null $parent Parent theme name, the active one when null.
     * @return bool Whether anything changed.
     */
    public static function sync_setting(string $name, ?string $parent = null): bool {
        if (!self::is_mirrorable($name)) {
            return false;
        }
        $value = get_config('theme_' . ($parent ?? parent_theme::get_name()), $name);
        $current = get_config(self::COMPONENT, $name);
        if ($value === false && $current !== false) {
            unset_config($name, self::COMPONENT);
            return true;
        }
        if ($value !== false && $value !== $current) {
            set_config($name, $value, self::COMPONENT);
            return true;
        }
        return false;
    }

    /**
     * Whether a config name belongs to the mirror (and not to Brandable itself).
     *
     * @param string $name Config name.
     * @return bool
     */
    public static function is_mirrorable(string $name): bool {
        return !in_array($name, self::CORE_KEYS, true) && strpos($name, parent_theme::RESERVED_PREFIX) !== 0;
    }

    /**
     * Copies config_plugins rows from theme_<parent> to theme_brandable.
     *
     * @param string $parent Parent theme name.
     * @return bool Whether anything changed.
     */
    private static function sync_config(string $parent): bool {
        $source = (array) get_config('theme_' . $parent);
        $target = (array) get_config(self::COMPONENT);
        $changed = false;

        foreach ($target as $name => $value) {
            if (self::is_mirrorable($name) && !array_key_exists($name, $source)) {
                unset_config($name, self::COMPONENT);
                $changed = true;
            }
        }
        foreach ($source as $name => $value) {
            if (!self::is_mirrorable($name)) {
                if (!in_array($name, self::CORE_KEYS, true)) {
                    debugging("Setting theme_{$parent}/{$name} uses the reserved prefix "
                        . parent_theme::RESERVED_PREFIX . ' and is not mirrored.', DEBUG_DEVELOPER);
                }
                continue;
            }
            if (!array_key_exists($name, $target) || $target[$name] !== $value) {
                set_config($name, $value, self::COMPONENT);
                $changed = true;
            }
        }
        return $changed;
    }
}
