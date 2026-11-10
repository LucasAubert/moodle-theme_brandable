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
 * Callbacks of theme_brandablert (exported by Brandable, static runtime).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Adds the page key (bp-*) to the body classes: scoped SCSS chunks and variants use it.
 *
 * @param moodle_page $page Page.
 */
function theme_brandablert_page_init(moodle_page $page): void {
    if (during_initial_install() || (defined('AJAX_SCRIPT') && AJAX_SCRIPT)) {
        return;
    }
    $pagekey = \theme_brandablert\brandable\page_key::current();
    if ($pagekey !== null) {
        $page->add_body_class($pagekey);
    }
    // Generated JavaScript: the modules of the page, when they run (settings of the theme).
    \theme_brandablert\brandable\js::page_init($page, $pagekey);
}

/**
 * Pre-SCSS of the version (variables), after the parent's, behind the brandable-dark mixin.
 *
 * @param theme_config $theme Theme.
 * @return string
 */
function theme_brandablert_get_pre_scss($theme): string {
    return \theme_brandablert\brandable\scss::DARK_MIXIN . \theme_brandablert\brandable\scss::get_code('pre');
}

/**
 * SCSS of the version, after the parent's.
 *
 * @param theme_config $theme Theme.
 * @return string
 */
function theme_brandablert_get_extra_scss($theme): string {
    return \theme_brandablert\brandable\scss::get_code('post');
}

/**
 * Serves the files of the parent settings (logo, background...) through the parent.
 *
 * @param stdClass $course Course.
 * @param stdClass $cm Course module.
 * @param context $context Context.
 * @param string $filearea File area.
 * @param array $args Arguments.
 * @param bool $forcedownload Whether to force the download.
 * @param array $options Options.
 * @return bool
 */
function theme_brandablert_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    $function = component_callback_exists('theme_' . \theme_brandablert\brandable\manifest::get_parent(), 'pluginfile');
    if ($function === false) {
        send_file_not_found();
    }
    return $function($course, $cm, $context, $filearea, $args, $forcedownload, $options);
}

/**
 * Records the state of the licence once the daily signal is turned on or off.
 *
 * @param string $fullname Setting name.
 */
function theme_brandablert_licence_updated(string $fullname): void {
    \theme_brandablert\brandable\licence::refresh();
}
