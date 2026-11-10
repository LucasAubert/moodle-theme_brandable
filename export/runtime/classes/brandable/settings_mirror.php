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

namespace theme_brandablert\brandable;

/**
 * Mirror of the parent settings: the parent's SCSS and layouts read their settings from the theme in use, so the
 * settings of the parent (brand colour, preset, logo...) are copied into this theme's config. Edited on the settings
 * page of the parent; copied at the installation, on every change (event) and by an hourly task.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings_mirror {
    /** @var string This theme. */
    private const COMPONENT = 'theme_brandablert';

    /** @var string Prefix of this theme's own settings (licence), never mirrored. */
    public const RESERVED_PREFIX = 'brandable_';

    /** @var string[] Keys managed by core, never mirrored. */
    private const CORE_KEYS = ['version', 'themerev'];

    /**
     * Copies all the parent settings; removes the mirrored ones the parent no longer has.
     *
     * @return bool Whether something changed.
     */
    public static function sync_all(): bool {
        $source = (array) get_config('theme_' . manifest::get_parent());
        $target = (array) get_config(self::COMPONENT);
        $changed = false;
        foreach ($target as $name => $value) {
            if (self::is_mirrorable($name) && !array_key_exists($name, $source)) {
                unset_config($name, self::COMPONENT);
                $changed = true;
            }
        }
        foreach ($source as $name => $value) {
            if (self::is_mirrorable($name) && (!array_key_exists($name, $target) || $target[$name] !== $value)) {
                set_config($name, $value, self::COMPONENT);
                $changed = true;
            }
        }
        return $changed;
    }

    /**
     * Handles a config change: a parent setting is copied, then the theme caches are reset.
     *
     * @param \core\event\config_log_created $event Event.
     */
    public static function config_log_created(\core\event\config_log_created $event): void {
        if (($event->other['plugin'] ?? '') !== 'theme_' . manifest::get_parent()) {
            return;
        }
        if (self::sync_all()) {
            theme_reset_all_caches();
        }
    }

    /**
     * Whether a setting is copied from the parent.
     *
     * @param string $name Setting name.
     * @return bool
     */
    private static function is_mirrorable(string $name): bool {
        return !in_array($name, self::CORE_KEYS, true) && strpos($name, self::RESERVED_PREFIX) !== 0;
    }
}
