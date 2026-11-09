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
 * Library functions for theme_brandable.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Page initialisation: Brandable page key on the body (bp-*), and template overrides for the templates rendered
 * in JavaScript.
 *
 * For each overridden template, the content hash of the variant applying to this page (scope) is put in the
 * page head; the theme_brandable/template_loader module plugs a loader into core/templates that fetches the
 * overrides of the revision when one of them is rendered, and picks the variant by hash.
 *
 * @param moodle_page $page The page.
 */
function theme_brandable_page_init(moodle_page $page): void {
    if (during_initial_install() || (defined('AJAX_SCRIPT') && AJAX_SCRIPT)) {
        return;
    }
    // Brandable's own page key (pagetype and body id are not unique per page).
    $pagekey = \theme_brandable\local\page_key::current();
    if ($pagekey !== null) {
        $page->add_body_class($pagekey);
    }

    // Overridden templates: for each name, the content hash of the variant (scope) that applies to this page.
    $context = new \theme_brandable\local\overrides\page_context(
        $pagekey,
        $page->pagetype ?: null,
        (int) $page->course->id !== (int) SITEID ? (int) $page->course->id : null,
        $page->cm ? (int) $page->cm->id : null
    );
    $templates = [];
    foreach (\theme_brandable\local\overrides\context::get_active_map()['template'] as $entry) {
        $name = $entry['target'];
        if (!isset($templates[$name])) {
            $selected = \theme_brandable\local\overrides\context::get_template_entry($name, $context);
            if ($selected !== null) {
                $templates[$name] = $selected['contenthash'];
            }
        }
    }
    // Generated JavaScript (spec B8): the modules of the page, only when they run here.
    if (\theme_brandable\local\js\modules::run_on($page)) {
        $config = \theme_brandable\local\js\modules::page_config($page, $context, $pagekey);
        if ($config !== null) {
            $page->requires->js_call_amd('theme_brandable/runtime', 'init', [$config]);
        }
    }

    // Preview: the templates rendered in JavaScript carry the template markers too (element picker).
    $markers = \theme_brandable\local\inspector\markers::is_active();
    if (!$templates && !$markers) {
        return;
    }
    $revision = \theme_brandable\local\overrides\context::get_active_revision();
    $page->requires->data_for_js('theme_brandable_overrides', [
        'revision' => $revision,
        // Revision of the library: the contents carry the URLs of the assets.
        'assets' => \theme_brandable\local\assets\library::get_revision(),
        'public' => $revision === \theme_brandable\local\overrides\revision_manager::get_published(),
        'templates' => (object) $templates,
        'markers' => $markers,
    ], true);
    $page->requires->js_call_amd('theme_brandable/template_loader', 'init');
}

/**
 * Pre-SCSS callback: the brandable-dark mixin, then Brandable's pre/* SCSS chunks (after the parent's pre-SCSS, before
 * the main SCSS).
 *
 * @param theme_config $theme The theme config.
 * @return string
 */
function theme_brandable_get_pre_scss($theme): string {
    return \theme_brandable\local\colour_modes::MIXIN . \theme_brandable\local\overrides\scss::get_code('pre');
}

/**
 * Extra SCSS callback: Brandable's post/* SCSS chunks (after the main SCSS).
 *
 * @param theme_config $theme The theme config.
 * @return string
 */
function theme_brandable_get_extra_scss($theme): string {
    return \theme_brandable\local\overrides\scss::get_code('post');
}

/**
 * Called when the parent theme setting changes: resyncs the mirror and purges the theme caches.
 *
 * @param string $fullname Full name of the setting.
 */
function theme_brandable_parent_changed(string $fullname): void {
    \theme_brandable\local\parent_theme::reset_caches();
    \theme_brandable\local\settings_mirror::sync_all();
    theme_reset_all_caches();
    // Templates and layouts now resolve through another theme chain: client-side caches are stale too.
    template_reset_all_caches();
}

/**
 * updatedcallback of the parent settings shown in Brandable's settings page.
 *
 * @param string $fullname Full name of the setting.
 */
function theme_brandable_proxied_setting_updated(string $fullname): void {
    \theme_brandable\local\settings_proxy::setting_updated($fullname);
}

/**
 * Serves the theme setting files.
 *
 * URLs built by setting_file_url() under Brandable point to theme_brandable. Access rules are those
 * of the parent theme: the request is delegated to the parent's pluginfile callback.
 *
 * @param stdClass $course Course object.
 * @param stdClass $cm Course module object.
 * @param context $context Context.
 * @param string $filearea File area.
 * @param array $args Extra arguments.
 * @param bool $forcedownload Whether to force download.
 * @param array $options Additional options affecting the file serving.
 * @return bool
 */
function theme_brandable_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($filearea === \theme_brandable\local\assets\library::FILEAREA && $context->contextlevel === CONTEXT_SYSTEM) {
        // Library of images and fonts (public, see assets\server).
        \theme_brandable\local\assets\server::serve($args, $options);
    }
    $parent = \theme_brandable\local\parent_theme::get_name();
    $function = component_callback_exists('theme_' . $parent, 'pluginfile');
    if ($function === false) {
        send_file_not_found();
    }
    return $function($course, $cm, $context, $filearea, $args, $forcedownload, $options);
}

/**
 * Lets the preview session load the CSS compiled from the draft instead of the published theme CSS.
 *
 * @param moodle_url[] $urls Theme CSS URLs.
 */
function theme_brandable_alter_css_urls(&$urls) {
    $urls = \theme_brandable\local\preview::alter_css_urls($urls);
}
