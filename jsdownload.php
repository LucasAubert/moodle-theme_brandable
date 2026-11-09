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
 * Download of the generated JavaScript of a version, or of the draft (spec B14), for a review out of Moodle: a zip with,
 * for each module, its source, its compiled function, its analysis report and its signature.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

use theme_brandable\local\js\jsdownload;

require_login(null, false);
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/theme/brandable/jsdownload.php'));
require_capability('theme/brandable:edit', $context);

$number = optional_param('version', 0, PARAM_INT);
$draft = optional_param('draft', 0, PARAM_BOOL);
[$revisionid, $label] = jsdownload::find_revision($number, $draft);

$path = make_request_directory() . '/js.zip';
jsdownload::build($revisionid, $label, $path);
send_temp_file($path, 'brandable-js-' . $label . '.zip');
