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

use theme_brandablert\brandable\variants;

/**
 * Mustache loader: the variant of a template that applies to the page, otherwise the standard filesystem resolution
 * (templates of this theme, of its parents, then of the component).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class loader extends \core\output\mustache_filesystem_loader {
    /**
     * Loads a template by name.
     *
     * @param string $name Template name (component/name).
     * @return string Template source.
     */
    public function load($name) {
        return variants::get($name) ?? parent::load($name);
    }
}
