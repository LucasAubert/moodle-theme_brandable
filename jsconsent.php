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
 * Generated JavaScript (spec B1 to B4): state, consent to the text of Brandable, deactivation, kill switch and history.
 *
 * The only place, with the editor, where the JavaScript is turned on: always through the text of the consent.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use theme_brandable\local\account\api_exception;
use theme_brandable\local\js\consent;
use theme_brandable\local\js\modules;

admin_externalpage_setup('theme_brandable_jsconsent');
$context = context_system::instance();
require_capability(consent::CAPABILITY, $context);
$url = new moodle_url('/theme/brandable/jsconsent.php');

$action = optional_param('action', '', PARAM_ALPHA);
$warning = '';
if ($action !== '' && confirm_sesskey()) {
    try {
        switch ($action) {
            case 'enable':
                $current = consent::enable(required_param('version', PARAM_NOTAGS));
                if ($current !== null) {
                    // The text changed since it was shown: shown again below.
                    $warning = get_string('jsconsent:changed', 'theme_brandable');
                    break;
                }
                redirect($url, get_string('jsconsent:enabled', 'theme_brandable'), null, \core\output\notification::NOTIFY_SUCCESS);
                break;
            case 'disable':
                $recorded = consent::disable();
                redirect(
                    $url,
                    get_string('jsconsent:disabled', 'theme_brandable')
                        . ($recorded ? '' : ' ' . get_string('jsconsent:notrecorded', 'theme_brandable')),
                    null,
                    $recorded ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
                );
                break;
            case 'suspend':
            case 'resume':
                consent::set_suspended($action === 'suspend');
                $done = $action === 'suspend' ? 'jsconsent:suspendeddone' : 'jsconsent:resumed';
                redirect($url, get_string($done, 'theme_brandable'));
                break;
        }
    } catch (api_exception $e) {
        $warning = $e->displaymessage;
    }
}

$terms = null;
$termserror = '';
try {
    $terms = consent::get_terms(true);
} catch (api_exception $e) {
    $termserror = $e->displaymessage;
}

$enabled = modules::is_enabled();
$suspended = modules::is_suspended();
$accepted = consent::get_version();
$outdated = $enabled && $terms !== null && $accepted !== $terms['version'];

// A form posting an action of the page.
$form = function (string $action, string $label, string $class, array $fields = []) use ($url): string {
    $inputs = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $action]);
    foreach ($fields as $name => $value) {
        $inputs .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
    }
    return html_writer::tag(
        'form',
        $inputs . html_writer::tag('button', $label, ['type' => 'submit', 'class' => 'btn ' . $class]),
        ['method' => 'post', 'action' => $url->out(false), 'class' => 'd-inline-block me-2 mr-2 mb-2']
    );
};

\theme_brandable\local\brand::require_styles();
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('jsconsent', 'theme_brandable'));
// Main action (acceptance) in the colours of the brand (spec of the brand, E11).
echo html_writer::start_div('tbe-page');
if ($warning !== '') {
    echo $OUTPUT->notification($warning, \core\output\notification::NOTIFY_WARNING);
}

// State.
$state = $enabled ? get_string('jsconsent:stateon', 'theme_brandable', s($accepted))
    : get_string('jsconsent:stateoff', 'theme_brandable');
echo html_writer::tag('p', $state, ['class' => 'lead']);
if ($enabled && $suspended) {
    echo $OUTPUT->notification(
        get_string('jsconsent:suspendedstate', 'theme_brandable'),
        \core\output\notification::NOTIFY_WARNING
    );
}
if ($outdated) {
    echo $OUTPUT->notification(get_string('jsconsent:outdated', 'theme_brandable'), \core\output\notification::NOTIFY_INFO);
}

// Text of the consent (plain text, paragraphs separated by an empty line), and activation.
if (!$enabled || $outdated) {
    if ($terms !== null) {
        $paragraphs = array_filter(array_map('trim', preg_split('/\R\s*\R/', $terms['text'])), 'strlen');
        echo html_writer::div(
            implode('', array_map(fn($paragraph) => html_writer::tag('p', nl2br(s($paragraph))), $paragraphs))
                . html_writer::tag('p', get_string('jsconsent:version', 'theme_brandable', s($terms['version'])), [
                    'class' => 'text-muted small mb-0',
                ]),
            'border rounded p-3 mb-3',
            ['data-region' => 'jsconsent-text']
        );
        echo $form(
            'enable',
            get_string('jsconsent:accept', 'theme_brandable'),
            'tbe-btn-accent',
            ['version' => $terms['version']]
        );
    } else {
        echo $OUTPUT->notification(
            get_string('jsconsent:noterms', 'theme_brandable', s($termserror)),
            \core\output\notification::NOTIFY_ERROR
        );
    }
}
if ($enabled) {
    echo $form('disable', get_string('jsconsent:disable', 'theme_brandable'), 'btn-outline-danger');
}

// Kill switch (no call to Brandable).
echo $OUTPUT->heading(get_string('jsconsent:killswitch', 'theme_brandable'), 3, 'mt-4');
echo html_writer::tag('p', get_string('jsconsent:killswitch_desc', 'theme_brandable', modules::NOJS_PARAM));
echo $suspended
    ? $form('resume', get_string('jsconsent:resume', 'theme_brandable'), 'btn-primary')
    : $form('suspend', get_string('jsconsent:suspend', 'theme_brandable'), 'btn-danger');

// History.
echo $OUTPUT->heading(get_string('jsconsent:history', 'theme_brandable'), 3, 'mt-4');
$history = consent::get_history();
if (!$history) {
    echo html_writer::tag('p', get_string('jsconsent:nohistory', 'theme_brandable'), ['class' => 'text-muted']);
} else {
    $table = new html_table();
    $table->head = [get_string('date'), get_string('jsconsent:action', 'theme_brandable'),
        get_string('jsconsent:versioncolumn', 'theme_brandable'), get_string('user')];
    $table->attributes['class'] = 'generaltable';
    foreach ($history as $entry) {
        $table->data[] = [
            userdate($entry['time'], get_string('strftimedatetimeshort', 'core_langconfig')),
            get_string($entry['enabled'] ? 'jsconsent:actionon' : 'jsconsent:actionoff', 'theme_brandable'),
            s($entry['version']),
            s($entry['user']),
        ];
    }
    echo html_writer::table($table);
}
echo html_writer::end_div();
echo $OUTPUT->footer();
