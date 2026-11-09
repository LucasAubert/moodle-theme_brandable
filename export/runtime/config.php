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
 * Theme config for theme_brandablert (exported by Brandable, static runtime).
 *
 * @package    theme_brandablert
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$THEME->name = 'brandablert';
$THEME->sheets = [];
$THEME->editor_sheets = [];
// SCSS of the version (pre/* and post/* chunks). Core calls the parents' callbacks first, then these ones.
$THEME->prescsscallback = 'theme_brandablert_get_pre_scss';
$THEME->extrascsscallback = 'theme_brandablert_get_extra_scss';

// Parent fixed at the export, layouts and the non-inherited properties of the parent chain.
\theme_brandablert\brandable\parent_config::apply($THEME);

// Renderers of the parent chain. Templates of the whole site are standard files (templates/); only the variants for a
// page type, a page, a course or an activity need the Brandable loader.
$THEME->rendererfactory = \theme_brandablert\brandable\manifest::has_variants()
    ? 'theme_brandablert\\brandable\\renderer_factory' : 'theme_overridden_renderer_factory';
