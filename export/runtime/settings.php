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
 * Settings of theme_brandablert: the Brandable licence (the design follows the settings of the parent theme).
 *
 * @package    theme_brandablert
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Core creates a hidden page for the settings of a theme: the theme provides its own.
$settings = new admin_settingpage('themesettingbrandablert', get_string('configtitle', 'theme_brandablert'));
if ($ADMIN->fulltree) {
    $parent = \theme_brandablert\brandable\manifest::get_parent();
    $settings->add(new admin_setting_heading(
        'theme_brandablert/parentsettings',
        '',
        get_string('parentsettings', 'theme_brandablert', (object) [
            'parent' => get_string('pluginname', 'theme_' . $parent),
            'url' => (new moodle_url('/admin/settings.php', ['section' => 'themesetting' . $parent]))->out(false),
        ])
    ));
    // Generated JavaScript: shown when the theme has modules.
    if (\theme_brandablert\brandable\js::get_modules()) {
        $settings->add(new admin_setting_heading(
            'theme_brandablert/jsheading',
            get_string('js', 'theme_brandablert'),
            get_string('js_desc', 'theme_brandablert', \theme_brandablert\brandable\js::NOJS_PARAM)
        ));
        $settings->add(new admin_setting_configcheckbox(
            'theme_brandablert/' . \theme_brandablert\brandable\js::ENABLED_CONFIG,
            get_string('js:enabled', 'theme_brandablert'),
            get_string('js:enabled_desc', 'theme_brandablert'),
            !empty(\theme_brandablert\brandable\manifest::get()['js']['enabled']) ? 1 : 0
        ));
        $settings->add(new admin_setting_configcheckbox(
            'theme_brandablert/' . \theme_brandablert\brandable\js::SUSPENDED_CONFIG,
            get_string('js:suspended', 'theme_brandablert'),
            get_string('js:suspended_desc', 'theme_brandablert'),
            0
        ));
    }
    $settings->add(new admin_setting_heading(
        'theme_brandablert/licence',
        get_string('licence', 'theme_brandablert'),
        \theme_brandablert\brandable\licence::status_html()
    ));
    $setting = new admin_setting_configcheckbox(
        'theme_brandablert/brandable_heartbeat',
        get_string('licence:heartbeat', 'theme_brandablert'),
        get_string('licence:heartbeat_desc', 'theme_brandablert'),
        1
    );
    $setting->set_updatedcallback('theme_brandablert_licence_updated');
    $settings->add($setting);
    $settings->add(new \theme_brandablert\brandable\offline_setting(
        'theme_brandablert/brandable_offlinelicence',
        get_string('licence:offline', 'theme_brandablert'),
        get_string('licence:offline_desc', 'theme_brandablert'),
        '',
        PARAM_RAW_TRIMMED,
        60,
        4
    ));
}
