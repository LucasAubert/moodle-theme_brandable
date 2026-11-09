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
 * Brandable editor: chat and revision history on the left, live preview of the site on the right.
 *
 * The editor itself is rendered with the parent theme, so that a broken draft can never break the
 * editor used to fix it. Opening the editor enables the preview mode for the session.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$path = optional_param('path', '/', PARAM_LOCALURL);

// Not admin_externalpage_setup(): building the admin tree initialises the theme, which must be forced first.
require_login(null, false);
$PAGE->set_course($SITE);
$PAGE->force_theme(\theme_brandable\local\parent_theme::get_name());
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/theme/brandable/editor.php', ['path' => $path]));
$PAGE->set_pagelayout('embedded');
require_capability('theme/brandable:edit', $context);

\theme_brandable\local\preview::enable();

$previewurl = new moodle_url($path);
$PAGE->set_title(get_string('editor', 'theme_brandable'));
$PAGE->add_body_class('theme-brandable-editor-page');
\theme_brandable\local\brand::require_styles();
$PAGE->requires->js_call_amd('theme_brandable/editor', 'init', ['[data-region="theme-brandable-editor"]']);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_brandable/editor', [
    'previewurl' => $previewurl->out(false),
    'wwwroot' => $CFG->wwwroot,
    'exiturl' => (new moodle_url('/theme/brandable/preview.php', [
        'sesskey' => sesskey(),
        'returnurl' => '/admin/search.php',
    ]))->out(false),
    'settingsurl' => (new moodle_url('/admin/settings.php', ['section' => 'themesettingbrandable']))->out(false),
    'generatoravailable' => true,
    'connected' => \theme_brandable\local\account\connection::is_ready(),
    'canconnect' => has_capability('moodle/site:config', $context),
    'connecturl' => (new moodle_url('/theme/brandable/connect.php'))->out(false),
    // What the site sends, terms and privacy policy, before the connection (template connect_disclosure).
    'termsurl' => \theme_brandable\local\account\connection::legal_urls()['termsurl'],
    'privacyurl' => \theme_brandable\local\account\connection::legal_urls()['privacyurl'],
    'exporturl' => has_capability('moodle/site:config', $context)
        ? (new moodle_url('/theme/brandable/export.php'))->out(false) : '',
    'importurl' => has_capability('moodle/site:config', $context)
        ? (new moodle_url('/theme/brandable/import.php'))->out(false) : '',
    'sesskey' => sesskey(),
    // Billing country asked before the first purchase.
    'countries' => has_capability('moodle/site:config', $context) ? json_encode(array_map(
        fn($code, $name) => ['code' => $code, 'name' => $name],
        array_keys($countries = get_string_manager()->get_list_of_countries()),
        $countries
    )) : '[]',
    'defaultcountry' => \theme_brandable\local\account\connection::get_default_billing_country(),
    'generatorname' => get_string('generator_' . \theme_brandable\local\generator\factory::get_name(), 'theme_brandable'),
    'markers' => \theme_brandable\local\preview::markers_enabled(),
    // New version of the plugin (spec U3): administrators of the site only.
    'pluginupdate' => \theme_brandable\local\plugin_updates::banner(),
    // Colour modes of Moodle 5.3 (spec CM5): the light / dark switch of the preview, and the checks in both modes.
    'colourmodes' => json_encode(\theme_brandable\local\colour_modes::to_context()),
    'colourmodesenabled' => \theme_brandable\local\colour_modes::enabled(),
    // Removed from the elements captured by the picker before they are sent to the generator.
    'user' => json_encode([
        'fullname' => fullname($USER),
        'firstname' => $USER->firstname,
        'lastname' => $USER->lastname,
        'username' => $USER->username,
        'email' => $USER->email,
    ]),
]);
echo $OUTPUT->footer();
