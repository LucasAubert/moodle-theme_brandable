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

namespace theme_brandable\output;

use core\output\renderer_factory\theme_overridden_renderer_factory;
use moodle_page;
use theme_brandable\output\mustache\injector;

/**
 * Renderer factory of Brandable: the standard theme_overridden_renderer_factory, plus the injection of the
 * Brandable Mustache loader into every renderer it creates (core, plugins, blocks...). See D1.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer_factory extends theme_overridden_renderer_factory {
    /**
     * Returns a renderer whose templates go through the Brandable loader.
     *
     * @param moodle_page $page The page the renderer is outputting content for.
     * @param string $component Component name.
     * @param string|null $subtype Optional subtype.
     * @param string|null $target One of the rendering target constants.
     * @return \core\output\renderer_base
     */
    public function get_renderer(moodle_page $page, $component, $subtype = null, $target = null) {
        $renderer = parent::get_renderer($page, $component, $subtype, $target);
        injector::inject($renderer);
        return $renderer;
    }
}
