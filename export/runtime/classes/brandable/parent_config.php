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

use core_component;
use stdClass;

/**
 * Parent theme of the exported theme (fixed at the export): theme chain, layouts and the properties core only reads
 * from the theme itself. Same resolution as theme_brandable on Moodle 4.5 and 5.1.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class parent_config {
    /** @var string[] Theme properties copied from the parent chain (not inherited by core; editor_scss is). */
    public const COPIED_PROPERTIES = [
        'usefallback', 'enablecourseajax', 'requiredblocks', 'haseditswitch', 'usescourseindex',
        'activityheaderconfig', 'removedprimarynavitems', 'addblockposition', 'blockrendermethod',
        'csspostprocess', 'csstreepostprocessor', 'doctype', 'yuicssmodules',
        'rarrow', 'larrow', 'uarrow', 'darrow',
    ];

    /** @var stdClass[] Isolated parent configs, keyed by theme name. */
    private static array $configs = [];

    /**
     * Applies the parent chain to the theme config being built (from config.php).
     *
     * @param stdClass $theme $THEME of the exported theme.
     */
    public static function apply(stdClass $theme): void {
        $chain = self::get_chain(manifest::get_parent());
        $theme->parents = $chain;

        // Layouts: from the root ancestor down to the parent, the closest theme winning.
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
     * Returns the theme chain: the parent first, then its own parents.
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
     * @param string $name Theme name.
     * @return stdClass|null
     */
    private static function load_config(string $name): ?stdClass {
        global $CFG;

        if (array_key_exists($name, self::$configs)) {
            return self::$configs[$name];
        }
        $dir = core_component::get_plugin_directory('theme', $name);
        self::$configs[$name] = null;
        if ($dir === null || !is_readable("$dir/config.php")) {
            return null;
        }
        // The variable name must be $THEME: this is what theme config.php files use.
        $THEME = new stdClass();
        $THEME->name = $name;
        $THEME->dir = $dir;
        // The settings of the parent are mirrored here: they are what the parent expects to find.
        $THEME->settings = get_config('theme_brandablert');
        include("$dir/config.php");
        if (isset($THEME->parents) && is_array($THEME->parents)) {
            self::$configs[$name] = $THEME;
        }
        return self::$configs[$name];
    }

    /**
     * Returns the class of the core renderer of the parent chain: the first theme of the chain that has one (namespaced
     * or legacy renderers.php), else core.
     *
     * @return string
     */
    public static function get_core_renderer_class(): string {
        global $CFG; // Needed by the included renderers.php files.

        foreach (self::get_chain(manifest::get_parent()) as $name) {
            $class = '\\theme_' . $name . '\\output\\core_renderer';
            if (class_exists($class)) {
                return $class;
            }
            $dir = \core_component::get_plugin_directory('theme', $name);
            if ($dir !== null && is_readable("$dir/renderers.php")) {
                include_once("$dir/renderers.php");
            }
            $class = 'theme_' . $name . '_core_renderer';
            if (class_exists($class, false)) {
                return '\\' . $class;
            }
        }
        return '\\' . \core\output\core_renderer::class;
    }
}
