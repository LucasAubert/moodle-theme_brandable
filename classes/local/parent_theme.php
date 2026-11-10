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

use core_component;
use stdClass;

/**
 * Resolves the dynamic parent theme of Brandable and derives the Brandable theme config from it.
 *
 * The parent is chosen by the administrator ({@see self::SETTING}). Brandable inherits its whole
 * parent chain ($THEME->parents), its layouts and the theme properties that core does not
 * inherit from parent configs by itself.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class parent_theme {
    /** @var string Theme used when no valid parent is configured. */
    public const DEFAULT_PARENT = 'boost';

    /** @var string Name of the Brandable setting holding the parent theme. */
    public const SETTING = 'brandable_parent';

    /** @var string Prefix reserved for Brandable's own settings (never mirrored from the parent). */
    public const RESERVED_PREFIX = 'brandable_';

    /**
     * Theme properties copied from the parent config because core only reads them from the theme itself.
     *
     * Properties that core already resolves through the parent configs (sheets, editor_sheets, editor_scss,
     * javascripts, scss, prescsscallback, extrascsscallback, precompiledcsscallback, iconsystem, parents_exclude_*,
     * pix and fonts) are deliberately NOT copied: their callbacks would otherwise run twice, and editor_scss copied
     * would be looked for in the directory of Brandable (theme_config::editor_scss_to_css()).
     */
    public const COPIED_PROPERTIES = [
        'usefallback', 'enablecourseajax', 'requiredblocks', 'haseditswitch', 'usescourseindex',
        'activityheaderconfig', 'removedprimarynavitems', 'addblockposition', 'blockrendermethod',
        'csspostprocess', 'csstreepostprocessor', 'doctype', 'yuicssmodules',
        'rarrow', 'larrow', 'uarrow', 'darrow',
    ];

    /** @var stdClass[] Isolated parent configs, keyed by theme name. */
    private static array $configs = [];

    /**
     * Returns the name of the active parent theme.
     *
     * @param stdClass|null $settings Brandable settings (as found in $THEME->settings); read from config when null.
     * @return string
     */
    public static function get_name(?stdClass $settings = null): string {
        if ($settings === null) {
            $name = get_config('theme_brandable', self::SETTING);
        } else {
            $name = $settings->{self::SETTING} ?? null;
        }
        if (is_string($name) && $name !== '' && self::is_valid_parent($name)) {
            return $name;
        }
        return self::DEFAULT_PARENT;
    }

    /**
     * Whether a theme can be used as the Brandable parent.
     *
     * @param string $name Theme name.
     * @return bool
     */
    public static function is_valid_parent(string $name): bool {
        if ($name === 'brandable' || clean_param($name, PARAM_PLUGIN) !== $name) {
            return false;
        }
        $config = self::load_config($name);
        if ($config === null) {
            return false;
        }
        // Never accept a theme that itself inherits from Brandable.
        return !in_array('brandable', $config->parents, true);
    }

    /**
     * Lists the installed themes that can be selected as the Brandable parent.
     *
     * @return string[] Theme names indexed by theme name.
     */
    public static function get_available_parents(): array {
        $themes = [];
        foreach (array_keys(core_component::get_plugin_list('theme')) as $name) {
            if (self::is_valid_parent($name)) {
                $themes[$name] = $name;
            }
        }
        return $themes;
    }

    /**
     * Returns the parent chain for Brandable: the parent first, then the parent's own parents.
     *
     * @param string $parent Parent theme name.
     * @return string[]
     */
    public static function get_chain(string $parent): array {
        $config = self::load_config($parent);
        if ($config === null) {
            return [$parent];
        }
        return array_values(array_unique(array_merge([$parent], $config->parents)));
    }

    /**
     * Loads a theme config.php in an isolated scope, the same way core does it.
     *
     * The included file only sees $THEME (and $CFG), never Brandable's own $THEME.
     *
     * @param string $name Theme name.
     * @return stdClass|null The theme config, or null when the theme is missing or broken.
     */
    public static function load_config(string $name): ?stdClass {
        if (!array_key_exists($name, self::$configs)) {
            self::$configs[$name] = self::include_config($name);
        }
        return self::$configs[$name];
    }

    /**
     * Applies the parent theme to the Brandable theme config being built.
     *
     * Called from Brandable's config.php with its $THEME object.
     *
     * @param stdClass $theme Brandable $THEME.
     */
    public static function apply(stdClass $theme): void {
        $parent = self::get_name($theme->settings ?? new stdClass());
        $chain = self::get_chain($parent);
        $theme->parents = $chain;

        // Layouts: merge the chain from the root ancestor down to the parent, the closest theme winning.
        // Core cascades layouts differently in 4.5 and 5.1; resolving them here gives the same result on both.
        $layouts = [];
        foreach (array_reverse($chain) as $name) {
            $config = self::load_config($name);
            if ($config !== null && !empty($config->layouts) && is_array($config->layouts)) {
                foreach ($config->layouts as $layout => $value) {
                    if (!empty($value)) {
                        $layouts[$layout] = $value;
                    }
                }
            }
        }
        $theme->layouts = $layouts;

        // Non-inherited properties: the closest theme of the chain that defines one wins.
        foreach (self::COPIED_PROPERTIES as $property) {
            foreach ($chain as $name) {
                $config = self::load_config($name);
                if ($config !== null && property_exists($config, $property)) {
                    $theme->$property = $config->$property;
                    break;
                }
            }
        }
    }

    /**
     * Returns the core renderer class Brandable's core_renderer must extend.
     *
     * Same resolution order as theme_overridden_renderer_factory on the parent chain:
     * \theme_X\output\core_renderer, then the legacy theme_X_core_renderer, then core.
     *
     * @param string $parent Parent theme name.
     * @return string Fully qualified class name.
     */
    public static function get_core_renderer_class(string $parent): string {
        global $CFG; // Needed by the included renderers.php files.

        foreach (self::get_chain($parent) as $name) {
            $class = '\\theme_' . $name . '\\output\\core_renderer';
            if (class_exists($class)) {
                return $class;
            }
            $dir = core_component::get_plugin_directory('theme', $name);
            if ($dir !== null && is_readable("$dir/renderers.php")) {
                // Legacy renderers; theme_config includes this file too, hence include_once.
                include_once("$dir/renderers.php");
            }
            $class = 'theme_' . $name . '_core_renderer';
            if (class_exists($class, false)) {
                return '\\' . $class;
            }
        }
        return '\\' . \core\output\core_renderer::class;
    }

    /**
     * Resets the static caches (for unit tests and parent changes).
     */
    public static function reset_caches(): void {
        self::$configs = [];
    }

    /**
     * Includes a theme config.php file.
     *
     * @param string $name Theme name.
     * @return stdClass|null
     */
    private static function include_config(string $name): ?stdClass {
        global $CFG;

        $dir = core_component::get_plugin_directory('theme', $name);
        if ($dir === null || !is_readable("$dir/config.php") || file_exists("$dir/styles.php")) {
            return null;
        }

        // The variable name must be $THEME: this is what theme config.php files use.
        $THEME = new stdClass();
        $THEME->name = $name;
        $THEME->dir = $dir;
        // Brandable mirrors the parent settings, so they are what the parent expects to find here.
        $THEME->settings = get_config('theme_brandable');
        include("$dir/config.php");

        if (!isset($THEME->parents) || !is_array($THEME->parents)) {
            return null;
        }
        return $THEME;
    }
}
