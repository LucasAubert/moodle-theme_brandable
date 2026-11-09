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
 * Theme config for theme_brandable.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$THEME->name = 'brandable';
// Standard theme_overridden_renderer_factory + Brandable Mustache loader injected into every renderer.
$THEME->rendererfactory = 'theme_brandable\\output\\renderer_factory';
// Brandable SCSS chunks (pre/* and post/*). Core calls the parents' callbacks first, then these ones.
$THEME->prescsscallback = 'theme_brandable_get_pre_scss';
$THEME->extrascsscallback = 'theme_brandable_get_extra_scss';

// Parents, layouts and the non-inherited properties come from the parent theme chosen by the admin.
// The parent's SCSS, SCSS callbacks, sheets and icon system are inherited by core through $THEME->parents.
\theme_brandable\local\parent_theme::apply($THEME);
