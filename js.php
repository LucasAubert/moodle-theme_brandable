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
 * File of the generated JavaScript modules of a revision (spec B7): an AMD module returning the compiled functions.
 *
 * The published revision is public (the pages of every visitor load it) and immutable at its URL (rev and hash); any
 * other revision is for the editors only (preview). The plugin never evaluates the code: the browser loads this file
 * as a script, through RequireJS.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- public for the published revision, login checked otherwise.
require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

use theme_brandable\local\js\modules;
use theme_brandable\local\overrides\revision_manager;

$revisionid = required_param('rev', PARAM_INT);
$hash = required_param('h', PARAM_ALPHANUM);

$published = $revisionid === revision_manager::get_published();
if (!$published) {
    require_login(null, false);
    require_capability('theme/brandable:edit', context_system::instance());
}
$PAGE->set_context(context_system::instance());
if (!modules::is_enabled() || modules::is_suspended() || $hash !== modules::bundle_hash($revisionid)) {
    // Turned off since the page was built, or another version of the file: nothing runs.
    send_header_404();
    die();
}

header('Content-Type: application/javascript; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header($published ? 'Cache-Control: public, max-age=' . YEARSECS . ', immutable' : 'Cache-Control: private, no-store');
echo modules::bundle($revisionid);
