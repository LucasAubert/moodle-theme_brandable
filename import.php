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
 * Import of an exported theme as a new published version (spec section 4): the zip, then the confirmation.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use theme_brandable\form\import_confirm_form;
use theme_brandable\form\import_form;
use theme_brandable\local\export\importer;
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\resolver;
use theme_brandable\local\overrides\revision_manager;
use theme_brandable\local\validation\validation_exception;
use theme_brandable\local\validation\validator;

admin_externalpage_setup('theme_brandable_import');
$url = new moodle_url('/theme/brandable/import.php');
$editorurl = new moodle_url('/theme/brandable/editor.php');

/**
 * Copies the zip of a draft area of the current user to a file of the request.
 *
 * @param int $itemid Draft item id.
 * @return string|null Path, null when the draft area has no file.
 */
function theme_brandable_import_zip(int $itemid): ?string {
    global $USER;

    $files = get_file_storage()->get_area_files(context_user::instance($USER->id)->id, 'user', 'draft', $itemid, 'id DESC', false);
    if (!$files) {
        return null;
    }
    $path = make_request_directory() . '/import.zip';
    reset($files)->copy_content_to($path);
    return $path;
}

$upload = new import_form($url);
if ($upload->is_cancelled() || optional_param('cancel', '', PARAM_RAW) !== '') {
    redirect($editorurl);
}

$itemid = 0;
if (optional_param('confirm', 0, PARAM_BOOL)) {
    require_sesskey();
    $itemid = required_param('zip', PARAM_INT);
} else if ($data = $upload->get_data()) {
    $itemid = (int) $data->zip;
}

$read = null;
$errors = [];
if ($itemid) {
    try {
        $path = theme_brandable_import_zip($itemid);
        if ($path === null) {
            throw new moodle_exception('import:error:zip', 'theme_brandable');
        }
        $read = importer::read($path);
    } catch (moodle_exception $e) {
        \core\notification::error($e->getMessage());
    }
}

if ($read) {
    $confirm = new import_confirm_form($url, [
        'note' => get_string('import:note', 'theme_brandable', (object) ['name' => $read['theme'], 'version' => $read['version']]),
        'discards' => revision_manager::get_draft() !== revision_manager::get_published(),
    ]);
    $confirm->set_data(['zip' => $itemid]);
    try {
        if ($data = $confirm->get_data()) {
            // Images and fonts of the zip into the library first (the changes designate them).
            $changes = importer::apply_assets($read);
            $modules = array_values(array_filter($changes, fn($change) => $change->type === change::TYPE_JS));
            if ($modules) {
                // JavaScript of another site: the draft of the editor, published once reviewed here (spec B15).
                revision_manager::import_as_draft($changes, (string) $data->note);
                \theme_brandable\local\js\review::mark_imported(array_map(
                    fn($change) => \theme_brandable\local\js\review::hash((string) $change->content),
                    $modules
                ));
                redirect(
                    $editorurl,
                    get_string('import:jsdone', 'theme_brandable', count($modules)),
                    null,
                    \core\output\notification::NOTIFY_WARNING
                );
            }
            $version = revision_manager::import($changes, (string) $data->note);
            // Saved in the cloud of the Pro offer after the response (spec C3).
            \theme_brandable\local\cloud\backup::send_after_response();
            $number = (int) $DB->get_field('theme_brandable_version', 'number', ['id' => $version]);
            $message = get_string('import:done', 'theme_brandable', $number);
            redirect($editorurl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
        }
        // Checked before the confirmation too (nothing is stored). Settings designate images of the zip, added to the
        // library only when confirmed: checked then.
        $templatesandscss = array_values(array_filter($read['changes'], fn($change) => $change->type !== change::TYPE_SETTING));
        if ($templatesandscss) {
            validator::validate($templatesandscss, resolver::empty_map());
        }
    } catch (validation_exception $e) {
        $errors = $e->errors;
    } catch (moodle_exception $e) {
        \core\notification::error($e->getMessage());
        $read = null;
    }
}

\theme_brandable\local\brand::require_styles();
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('import', 'theme_brandable'));
// Main action of the forms in the colours of the brand (spec of the brand, E11).
echo html_writer::start_div('tbe-page tbe-page-form');
if (!$read) {
    $upload->display();
} else {
    $counts = ['template' => 0, 'variant' => 0, 'scss' => 0];
    foreach ($read['changes'] as $change) {
        if (in_array($change->type, [change::TYPE_SETTING, change::TYPE_JS], true)) {
            continue;
        }
        $counts[$change->type === change::TYPE_SCSS ? 'scss' : ($change->scope->is_site() ? 'template' : 'variant')]++;
    }
    echo html_writer::tag('p', get_string('import:summary', 'theme_brandable', (object) [
        'name' => s($read['theme']),
        'version' => s($read['version']),
        'moodle' => s((string) ($read['source']['moodle'] ?? '?')),
        'templates' => $counts['template'],
        'variants' => $counts['variant'],
        'scss' => $counts['scss'],
    ]));
    if ($read['assets']) {
        $kinds = array_count_values(array_map(fn($asset) => $asset['entry']['kind'], $read['assets']));
        echo html_writer::tag('p', get_string('import:assets', 'theme_brandable', (object) [
            'images' => $kinds['image'] ?? 0, 'fonts' => $kinds['font'] ?? 0]));
    }
    $jsmodules = count(array_filter($read['changes'], fn($change) => $change->type === change::TYPE_JS));
    if ($jsmodules) {
        echo $OUTPUT->notification(get_string('import:jsdraft', 'theme_brandable', $jsmodules), 'info');
    }
    if ($read['warnings']) {
        echo $OUTPUT->notification(get_string('import:warnings', 'theme_brandable')
            . html_writer::alist(array_map('s', $read['warnings'])), 'warning');
    }
    if ($errors) {
        $items = array_map(
            fn(array $error) => s(($error['target'] !== '' ? $error['target'] . ': ' : '') . $error['message']),
            $errors
        );
        echo $OUTPUT->notification(get_string('import:error:invalid', 'theme_brandable') . html_writer::alist($items), 'error');
        echo $OUTPUT->single_button($url, get_string('import:again', 'theme_brandable'), 'get');
    } else {
        $confirm->display();
    }
}
echo html_writer::end_div();
echo $OUTPUT->footer();
