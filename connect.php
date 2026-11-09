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
 * Connection of the site to a Brandable account: state of the connection, sign-in on the Brandable web app (PKCE),
 * relink after a site mismatch, disconnection.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use theme_brandable\local\account\account;
use theme_brandable\local\account\api_exception;
use theme_brandable\local\account\connection;

admin_externalpage_setup('theme_brandable_connect');
$url = new moodle_url('/theme/brandable/connect.php');
$action = optional_param('action', '', PARAM_ALPHA);

if ($action === 'start' || $action === 'relink') {
    require_sesskey();
    redirect(connection::get_connect_url(connection::start_flow(), $action === 'relink'));
}
$disconnecterror = '';
if ($action === 'disconnect' || $action === 'forget') {
    require_sesskey();
    try {
        account::disconnect($action === 'forget');
        redirect($url, get_string('connect:disconnected', 'theme_brandable'), null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (api_exception $e) {
        $disconnecterror = $e->displaymessage;
    }
}

// Back from a payment (Brandable adds checkout=success or cancel to the return URL).
$checkout = optional_param('checkout', '', PARAM_ALPHA);
if ($checkout === 'success' || $checkout === 'cancel') {
    account::clear_cache();
    // The editor, open in another tab, reads the account again until the change shows (contract 5).
    $PAGE->requires->js_amd_inline('try { window.localStorage.setItem("theme_brandable_checkout", JSON.stringify({result: '
        . json_encode($checkout) . ', at: Date.now()})); } catch (e) { /* Storage blocked: the editor reads it when shown. */ }');
    \core\notification::add(
        get_string('account:checkout' . $checkout, 'theme_brandable'),
        $checkout === 'success' ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_INFO
    );
}
$info = connection::get_info();
$account = null;
if (connection::is_connected()) {
    try {
        $account = \theme_brandable\external\get_account::export(account::get());
    } catch (api_exception $e) {
        $account = null;
    }
}
\theme_brandable\local\brand::require_styles();
echo $OUTPUT->header();
// Symbol of Brandable before the title (spec of the brand, E10); the rest of the page is the one of Moodle.
echo html_writer::div($OUTPUT->heading(
    \theme_brandable\local\brand::mark() . html_writer::span(get_string('connect', 'theme_brandable')),
    2,
    'd-flex align-items-center'
), 'tbe-page');
echo $OUTPUT->render_from_template('theme_brandable/connect', [
    'connected' => connection::is_connected(),
    'canrelink' => connection::get_environment_id() !== '',
    'organization' => $account['organization'] ?? (string) ($info['organization']['name'] ?? ''),
    'project' => $account['project'] ?? (string) ($info['project']['name'] ?? ''),
    'environment' => $account['environment'] ?? (string) ($info['environment']['name'] ?? ''),
    'plan' => $account['plan'] ?? (string) ($info['plan']['name'] ?? ''),
    'credits' => $account['credits'] ?? null,
    'hascredits' => isset($account['credits']),
    'dashboardurl' => $account['dashboardurl'] ?? '',
    'disconnecterror' => $disconnecterror,
    'devmode' => connection::is_devmode(),
    'actionurl' => $url->out(false),
    'sesskey' => sesskey(),
    'editorurl' => (new moodle_url('/theme/brandable/editor.php'))->out(false),
] + connection::legal_urls());
echo $OUTPUT->footer();
