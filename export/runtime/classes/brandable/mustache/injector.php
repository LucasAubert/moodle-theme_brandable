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

namespace theme_brandablert\brandable\mustache;

use core\output\renderer_base;

/**
 * Makes every renderer use the loader of the template variants.
 *
 * renderer_base keeps its engine in a private property, created lazily by get_mustache(). The injector
 * puts a {@see lazy_engine} in that property; on first use it lets core build its engine (same helpers
 * and options as core, whatever the Moodle version), switches its loader, and steps aside.
 *
 * If a future Moodle version drops the property, injection is disabled (degraded mode): the variants no longer
 * apply, the templates of the whole site (files of the theme) still do.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class injector {
    /** @var bool|null Whether renderer_base still has the private $mustache property. */
    private static ?bool $supported = null;

    /**
     * Whether injection is possible on this Moodle version.
     *
     * @return bool
     */
    public static function is_supported(): bool {
        if (self::$supported === null) {
            self::$supported = property_exists(renderer_base::class, 'mustache');
            if (!self::$supported) {
                debugging(
                    'theme_brandablert: renderer_base::$mustache not found, template variants limited to the core renderer.',
                    DEBUG_DEVELOPER
                );
            }
        }
        return self::$supported;
    }

    /**
     * Injects the lazy engine into a renderer that has not built its engine yet.
     *
     * @param renderer_base $renderer Renderer.
     */
    public static function inject(renderer_base $renderer): void {
        if (!self::is_supported()) {
            return;
        }
        // Closure scoped to renderer_base: the only way to reach its private property.
        $set = \Closure::bind(static function (renderer_base $renderer): void {
            if ($renderer->mustache === null) {
                $renderer->mustache = new lazy_engine($renderer);
            }
        }, null, renderer_base::class);
        $set($renderer);
    }

    /**
     * Builds the real engine of a renderer (through core) and switches it to the Brandable loader.
     *
     * Called by {@see lazy_engine} on first use. Replaces the lazy engine in the renderer.
     *
     * @param renderer_base $renderer Renderer.
     * @return \Mustache_Engine
     */
    public static function build(renderer_base $renderer): \Mustache_Engine {
        $build = \Closure::bind(static function (renderer_base $renderer): \Mustache_Engine {
            $renderer->mustache = null;
            return $renderer->get_mustache();
        }, null, renderer_base::class);
        $engine = $build($renderer);
        self::use_loader($engine);
        return $engine;
    }

    /**
     * Switches an engine to the Brandable loader (idempotent).
     *
     * @param \Mustache_Engine $engine Engine.
     */
    public static function use_loader(\Mustache_Engine $engine): void {
        if (!$engine instanceof lazy_engine && !$engine->getLoader() instanceof loader) {
            $engine->setLoader(new loader());
            $engine->setPartialsLoader(new loader());
        }
    }
}
