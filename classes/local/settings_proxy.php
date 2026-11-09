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

use admin_category;
use admin_root;
use admin_setting;
use admin_settingpage;

/**
 * Exposes the parent theme's settings inside Brandable's settings page.
 *
 * The parent's settings.php is never included a second time: core already loaded it into the admin tree.
 * Once the tree is complete, this class finds the admin_setting objects that belong to theme_<parent>
 * (whatever page, tabs page or category the parent put them in) and reuses them for rendering.
 * Because they keep their theme_<parent>/xxx names, saving them writes to the parent; the settings
 * mirror is then refreshed by the config log observer and by their wrapped updatedcallback. The latter
 * covers custom admin_setting classes (third-party themes) that write with set_config() without logging.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings_proxy {
    /** @var string Function set as updatedcallback on the proxied settings. */
    public const CALLBACK = 'theme_brandable_proxied_setting_updated';

    /** @var array[] Wrapped settings, keyed by full name: [original callback, plugin, setting name]. */
    private static array $wrapped = [];

    /**
     * Finds the pages holding the parent's settings in a fully loaded admin tree.
     *
     * Tabs pages (any page exposing get_tabs(), e.g. theme_boost_admin_settingspage_tabs) are expanded
     * into their tabs, so that the parent's structure is kept.
     *
     * @param admin_root $root Fully loaded admin tree.
     * @param string $parent Parent theme name.
     * @param string $exclude Name of a page to ignore (Brandable's own page).
     * @return admin_settingpage[] Pages (or tabs) indexed by name.
     */
    public static function get_parent_pages(admin_root $root, string $parent, string $exclude = ''): array {
        $pages = [];
        self::collect_pages($root, 'theme_' . $parent, $exclude, $pages);
        return $pages;
    }

    /**
     * Wraps the updatedcallback of every setting found in the given pages.
     *
     * admin_setting_configstoredfile only calls string callbacks (function_exists()), hence a plain
     * function plus a registry of the original callbacks. Idempotent.
     *
     * @param admin_settingpage[] $pages Pages returned by {@see self::get_parent_pages()}.
     * @param string $parent Parent theme name: only its settings are wrapped.
     */
    public static function wrap_callbacks(array $pages, string $parent): void {
        foreach ($pages as $page) {
            foreach ($page->settings as $setting) {
                if ($setting->plugin !== 'theme_' . $parent || $setting->updatedcallback === self::CALLBACK) {
                    continue;
                }
                self::$wrapped[$setting->get_full_name()] = [$setting->updatedcallback, $setting->plugin, $setting->name];
                $setting->set_updatedcallback(self::CALLBACK);
            }
        }
    }

    /**
     * Called when a proxied setting has been updated: runs the parent's callback, then resyncs the mirror.
     *
     * @param string $fullname Full name of the setting (s_theme_<parent>_xxx).
     */
    public static function setting_updated(string $fullname): void {
        if (!isset(self::$wrapped[$fullname])) {
            return;
        }
        [$original, $plugin, $name] = self::$wrapped[$fullname];
        if (!empty($original) && is_callable($original)) {
            $original($fullname);
        }
        $parent = substr((string) $plugin, strlen('theme_'));
        if ($parent !== '' && $parent === parent_theme::get_name()) {
            settings_mirror::sync_setting($name, $parent);
        }
    }

    /**
     * Resets the registry of wrapped callbacks (unit tests).
     */
    public static function reset(): void {
        self::$wrapped = [];
    }

    /**
     * Recursively collects pages that contain settings of a plugin.
     *
     * @param admin_category $node Node to explore.
     * @param string $plugin Plugin (component) owning the settings.
     * @param string $exclude Page name to ignore.
     * @param admin_settingpage[] $pages Collected pages.
     */
    private static function collect_pages(admin_category $node, string $plugin, string $exclude, array &$pages): void {
        foreach ($node->children as $child) {
            if ($child instanceof admin_category) {
                self::collect_pages($child, $plugin, $exclude, $pages);
                continue;
            }
            if (!$child instanceof admin_settingpage || $child->name === $exclude || !$child->check_access()) {
                continue;
            }
            $candidates = method_exists($child, 'get_tabs') ? $child->get_tabs() : [$child];
            foreach ($candidates as $page) {
                if ($page instanceof admin_settingpage && self::has_settings_of($page, $plugin)) {
                    $pages[$page->name] = $page;
                }
            }
        }
    }

    /**
     * Whether a page holds at least one setting of the plugin.
     *
     * @param admin_settingpage $page Page.
     * @param string $plugin Plugin name.
     * @return bool
     */
    private static function has_settings_of(admin_settingpage $page, string $plugin): bool {
        foreach ($page->settings as $setting) {
            if ($setting instanceof admin_setting && $setting->plugin === $plugin) {
                return true;
            }
        }
        return false;
    }
}
