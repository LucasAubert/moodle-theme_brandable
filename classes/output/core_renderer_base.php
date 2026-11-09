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

/**
 * Dynamic base class of Brandable's core renderer.
 *
 * This file declares no class: when \theme_brandable\output\core_renderer_base is autoloaded, it is
 * aliased to the core renderer of the active parent theme, so that Brandable's core_renderer extends
 * whatever renderer the parent (or its own parents) provides. See parent_theme::get_core_renderer_class().
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class_alias(
    ltrim(\theme_brandable\local\parent_theme::get_core_renderer_class(\theme_brandable\local\parent_theme::get_name()), '\\'),
    'theme_brandable\output\core_renderer_base'
);
