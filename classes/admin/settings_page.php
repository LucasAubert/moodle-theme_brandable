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

namespace theme_brandable\admin;

use admin_root;
use admin_settingpage;
use theme_brandable\local\parent_theme;
use theme_brandable\local\settings_proxy;

/**
 * Brandable settings page: Brandable's own settings plus, as tabs, the settings of the parent theme.
 *
 * Only Brandable's own settings are held by this page ($this->settings), so admin search, defaults and
 * "new settings" keep working without duplicates. The parent's settings are resolved lazily, once the
 * admin tree is complete (the parent may be loaded after Brandable, e.g. Classic).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings_page extends admin_settingpage {
    /**
     * Locates this page; when found in a complete tree, prepares the parent's settings for saving.
     *
     * admin/settings.php locates the section before writing the submitted data, so wrapping the
     * parent's updatedcallbacks here makes saves from this page resync the mirror immediately.
     *
     * @param string $name Page name.
     * @param bool $findpath Whether to fill the path information.
     * @return mixed
     */
    public function locate($name, $findpath = false) {
        $result = parent::locate($name, $findpath);
        if ($result === $this && ($root = $this->get_loaded_root()) !== null) {
            $parent = parent_theme::get_name();
            settings_proxy::wrap_callbacks(settings_proxy::get_parent_pages($root, $parent, $this->name), $parent);
            $this->import_parent_dependencies($root, $parent);
        }
        return $result;
    }

    /**
     * Copies the dependencies (hide_if) of the parent's settings page and of its tabs onto this page: admin/settings.php
     * reads them from the page it shows only (Boost 5.3 puts the one of its default colour mode on its tabs page).
     * Idempotent.
     *
     * @param admin_root $root Admin tree.
     * @param string $parent Parent theme.
     */
    private function import_parent_dependencies(admin_root $root, string $parent): void {
        $top = $root->locate('themesetting' . $parent);
        if (!$top instanceof admin_settingpage || $top === $this) {
            return;
        }
        $pages = array_merge([$top], method_exists($top, 'get_tabs') ? $top->get_tabs() : []);
        $known = [];
        foreach ($this->dependencies as $dependency) {
            $known[$dependency->settingname . '|' . $dependency->dependenton] = true;
        }
        foreach ($pages as $page) {
            if (!$page instanceof admin_settingpage) {
                continue;
            }
            foreach ($page->dependencies as $dependency) {
                $key = $dependency->settingname . '|' . $dependency->dependenton;
                if (!isset($known[$key])) {
                    $known[$key] = true;
                    $this->dependencies[] = $dependency;
                }
            }
        }
    }

    /**
     * Renders Brandable's settings and the parent's settings as tabs.
     *
     * @return string
     */
    public function output_html() {
        global $OUTPUT;

        $parent = parent_theme::get_name();
        $parentname = get_string('pluginname', 'theme_' . $parent);
        $tabs = [[
            'name' => 'theme_brandable_general',
            'displayname' => get_string('generalsettings', 'theme_brandable'),
            'html' => parent::output_html(),
        ]];

        $root = $this->get_loaded_root() ?? admin_get_root();
        $pages = settings_proxy::get_parent_pages($root, $parent, $this->name);
        $notice = $OUTPUT->notification(get_string('parentsettings_desc', 'theme_brandable', $parentname), 'info', false);
        foreach ($pages as $page) {
            $tabs[] = [
                'name' => $page->name,
                'displayname' => get_string('parentsettings', 'theme_brandable', (object) [
                    'theme' => $parentname,
                    'page' => $page->visiblename,
                ]),
                'html' => $notice . $page->output_html(),
            ];
        }

        $activetab = optional_param('activetab', '', PARAM_TEXT);
        $names = array_column($tabs, 'name');
        $active = in_array($activetab, $names, true) ? $activetab : $names[0];
        foreach ($tabs as $i => $tab) {
            $tabs[$i]['active'] = $tab['name'] === $active;
        }

        if (count($tabs) === 1) {
            return $tabs[0]['html'];
        }
        return $OUTPUT->render_from_template('theme_boost/admin_setting_tabs', ['tabs' => $tabs]);
    }

    /**
     * Returns the admin tree when it is completely loaded.
     *
     * @return admin_root|null
     */
    private function get_loaded_root(): ?admin_root {
        global $ADMIN;
        return ($ADMIN instanceof admin_root && $ADMIN->loaded) ? $ADMIN : null;
    }
}
