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
 * Export of the published version as a standalone theme (zip downloaded once: it holds the licence key).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use theme_brandable\local\account\api_exception;
use theme_brandable\local\export\export_manager;
use theme_brandable\local\export\exporter;
use theme_brandable\local\overrides\revision_manager;

admin_externalpage_setup('theme_brandable_export');
$url = new moodle_url('/theme/brandable/export.php');
$version = revision_manager::get_current_version();

$form = new \theme_brandable\form\export_form($url, [
    'note' => $version->note ?? '',
    'targets' => $version ? exporter::get_scoped_targets() : [],
]);
if ($form->is_cancelled()) {
    redirect(new moodle_url('/theme/brandable/editor.php'));
}
if ($version && ($data = $form->get_data())) {
    try {
        $result = export_manager::export($data->name, (string) $data->note, (string) $data->author, $form->get_mapping($data));
        send_file($result['path'], $result['filename'], 0, 0, false, true, 'application/zip');
        die();
    } catch (api_exception $e) {
        // 403 plan_required: export is for paid plans; the page of Brandable to change the plan.
        $message = s($e->displaymessage);
        if ($e->url !== '') {
            $message .= ' ' . html_writer::link(
                $e->url,
                get_string('export:changeplan', 'theme_brandable'),
                ['target' => '_blank', 'rel' => 'noopener']
            );
        }
        \core\notification::error($message);
    } catch (moodle_exception $e) {
        \core\notification::error($e->getMessage());
    }
}

\theme_brandable\local\brand::require_styles();
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('export', 'theme_brandable'));
if (!$version) {
    echo $OUTPUT->notification(get_string('export:error:nothingpublished', 'theme_brandable'), 'info');
} else {
    echo html_writer::tag('p', get_string('export:from', 'theme_brandable', (object) ['number' => $version->number,
        'date' => userdate($version->timecreated)]));
    // Main action in the colours of the brand (spec of the brand, E11).
    echo html_writer::start_div('tbe-page tbe-page-form');
    $form->display();
    echo html_writer::end_div();
}
echo $OUTPUT->footer();
