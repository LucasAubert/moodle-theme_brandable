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
 * File of the generated JavaScript modules of the theme: an AMD module returning their compiled functions, as Brandable
 * delivered them (the theme never evaluates them; the browser loads this file through RequireJS).
 *
 * Public (the pages of every visitor load it) and immutable at its URL (hash); nothing when the modules are turned off.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- public: the pages of every visitor load it.
require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

use theme_brandablert\brandable\js;

$hash = required_param('h', PARAM_ALPHANUM);
$PAGE->set_context(context_system::instance());
if (!js::is_enabled() || $hash !== js::bundle_hash()) {
    // Turned off since the page was built, or another version of the file: nothing runs.
    send_header_404();
    die();
}

header('Content-Type: application/javascript; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=' . YEARSECS . ', immutable');
echo js::bundle();
