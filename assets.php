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
 * Upload of an image or a font to the Brandable library (editor; spec A7).
 *
 * POST multipart, sesskey. Files: file (original), optimized, webp, thumbnail (versions prepared by the editor,
 * optional), licence (font). Fields: kind (image or font), slug (suggested when empty), alt; family, weight, style
 * (font). Answers the asset (JSON), or the error (JSON, as every AJAX script).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');

use theme_brandable\local\assets\file_checker;
use theme_brandable\local\assets\library;

require_login(null, false);
$context = context_system::instance();
require_capability('theme/brandable:edit', $context);
require_sesskey();

$kind = required_param('kind', PARAM_ALPHA);

/**
 * Returns the temporary path of an uploaded file, after the antivirus; null when it was not sent.
 *
 * The only reading of $_FILES: the editor uploads the image and its optimised versions, made in the browser, with
 * fetch (no moodleform, no draft area). The upload is checked with is_uploaded_file(), the name cleaned with
 * PARAM_FILE, the content scanned by the antivirus of Moodle, then checked by file_checker (type, size, SVG
 * sanitised).
 *
 * @param string $name Field name.
 * @param bool $required Whether the file is required.
 * @return string|null
 */
function theme_brandable_uploaded(string $name, bool $required = false): ?string {
    $file = $_FILES[$name] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        if ($required) {
            throw new moodle_exception('asset:error:empty', 'theme_brandable');
        }
        return null;
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        throw new moodle_exception('asset:error:filesize', 'theme_brandable', '', display_size(file_checker::MAX_FILE));
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new moodle_exception('asset:error:upload', 'theme_brandable');
    }
    // Throws (and deletes the file) when a virus is found.
    \core\antivirus\manager::scan_file($file['tmp_name'], clean_param($file['name'], PARAM_FILE), true);
    return $file['tmp_name'];
}

$filename = clean_param($_FILES['file']['name'] ?? '', PARAM_FILE);
$meta = [
    'slug' => optional_param('slug', '', PARAM_RAW_TRIMMED),
    'filename' => $filename,
    'alt' => optional_param('alt', '', PARAM_TEXT),
    'family' => optional_param('family', '', PARAM_TEXT),
    'weight' => optional_param('weight', 0, PARAM_INT),
    'style' => optional_param('style', '', PARAM_ALPHA),
];
if ($kind === 'font') {
    $checked = file_checker::check_font(theme_brandable_uploaded('file', true), theme_brandable_uploaded('licence'));
} else if ($kind === 'image') {
    $checked = file_checker::check_image(
        theme_brandable_uploaded('file', true),
        theme_brandable_uploaded('optimized'),
        theme_brandable_uploaded('webp'),
        theme_brandable_uploaded('thumbnail')
    );
} else {
    throw new moodle_exception('asset:error:type', 'theme_brandable');
}
$record = library::add($checked, $meta);

echo json_encode(['asset' => \theme_brandable\external\get_assets::export_asset($record, [])]);
