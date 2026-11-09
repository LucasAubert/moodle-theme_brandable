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
 * Settings for theme_brandable.
 *
 * Brandable's own settings use the 'brandable_' prefix. The parent theme's settings are shown as extra
 * tabs by \theme_brandable\admin\settings_page, reusing the objects core loaded from the parent's settings.php.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// The editor (Appearance > Themes > Brandable editor).
$ADMIN->add('themes', new admin_externalpage(
    'theme_brandable_editor',
    new lang_string('editor', 'theme_brandable'),
    new moodle_url('/theme/brandable/editor.php'),
    'theme/brandable:edit'
));

$ADMIN->add('themes', new admin_externalpage(
    'theme_brandable_connect',
    new lang_string('connect', 'theme_brandable'),
    new moodle_url('/theme/brandable/connect.php'),
    'moodle/site:config'
));

$ADMIN->add('themes', new admin_externalpage(
    'theme_brandable_export',
    new lang_string('export', 'theme_brandable'),
    new moodle_url('/theme/brandable/export.php'),
    'moodle/site:config'
));

$ADMIN->add('themes', new admin_externalpage(
    'theme_brandable_import',
    new lang_string('import', 'theme_brandable'),
    new moodle_url('/theme/brandable/import.php'),
    'moodle/site:config'
));

// Generated JavaScript: consent, deactivation, kill switch (spec B1, B2).
$ADMIN->add('themes', new admin_externalpage(
    'theme_brandable_jsconsent',
    new lang_string('jsconsent', 'theme_brandable'),
    new moodle_url('/theme/brandable/jsconsent.php'),
    'theme/brandable:managejs'
));

$settings = new \theme_brandable\admin\settings_page(
    'themesettingbrandable',
    new lang_string('configtitle', 'theme_brandable'),
    'moodle/site:config'
);

if ($ADMIN->fulltree) {
    $setting = new admin_setting_configselect(
        'theme_brandable/' . \theme_brandable\local\parent_theme::SETTING,
        new lang_string('brandable_parent', 'theme_brandable'),
        new lang_string('brandable_parent_desc', 'theme_brandable'),
        \theme_brandable\local\parent_theme::DEFAULT_PARENT,
        \theme_brandable\local\parent_theme::get_available_parents()
    );
    $setting->set_updatedcallback('theme_brandable_parent_changed');
    $settings->add($setting);

    // Generated JavaScript: read only here, turned on or off with the text of the consent (spec B1).
    $jsstate = !get_config('theme_brandable', \theme_brandable\local\js\modules::ENABLED_CONFIG)
        ? get_string('jsconsent:stateoff', 'theme_brandable')
        : get_string('jsconsent:stateon', 'theme_brandable', s(\theme_brandable\local\js\consent::get_version()))
            . (get_config('theme_brandable', \theme_brandable\local\js\modules::SUSPENDED_CONFIG)
                ? ' ' . get_string('jsconsent:suspendedstate', 'theme_brandable') : '');
    $settings->add(new admin_setting_heading(
        'theme_brandable/brandable_jsheading',
        new lang_string('brandable_js', 'theme_brandable'),
        html_writer::tag('p', $jsstate) . html_writer::link(
            new moodle_url('/theme/brandable/jsconsent.php'),
            get_string('jsconsent:manage', 'theme_brandable'),
            ['class' => 'btn btn-secondary']
        )
    ));

    // Manual API URL and key, demo generator: dev mode only ($CFG->theme_brandable_devmode = true in config.php).
    if (\theme_brandable\local\account\connection::is_devmode()) {
        $settings->add(new admin_setting_heading(
            'theme_brandable/brandable_generatorheading',
            new lang_string('generatorheading', 'theme_brandable'),
            ''
        ));
        $settings->add(new admin_setting_configselect(
            'theme_brandable/' . \theme_brandable\local\generator\factory::SETTING,
            new lang_string('brandable_generator', 'theme_brandable'),
            new lang_string('brandable_generator_desc', 'theme_brandable'),
            \theme_brandable\local\generator\factory::DEFAULT,
            \theme_brandable\local\generator\factory::get_choices()
        ));
        $settings->add(new admin_setting_configtext(
            'theme_brandable/brandable_weburl',
            new lang_string('brandable_weburl', 'theme_brandable'),
            new lang_string('brandable_weburl_desc', 'theme_brandable'),
            '',
            PARAM_URL
        ));
        $settings->add(new admin_setting_configtext(
            'theme_brandable/brandable_apiurl',
            new lang_string('brandable_apiurl', 'theme_brandable'),
            new lang_string('brandable_apiurl_desc', 'theme_brandable'),
            '',
            PARAM_URL
        ));
        $settings->add(new admin_setting_configpasswordunmask(
            'theme_brandable/brandable_apikey',
            new lang_string('brandable_apikey', 'theme_brandable'),
            new lang_string('brandable_apikey_desc', 'theme_brandable'),
            ''
        ));
    }
}
