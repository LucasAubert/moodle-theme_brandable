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
 * Serves the theme CSS compiled from a revision, for the preview session only.
 *
 * Not subject to the themerev cache: the URL carries a hash of what the CSS depends on, so the
 * browser can cache it for good.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$revisionid = required_param('rev', PARAM_INT);
$rtl = optional_param('rtl', 0, PARAM_BOOL);

require_login(null, false);
require_capability('theme/brandable:edit', context_system::instance());
// Compiling can take a few seconds: do not block the other requests of the iframe.
\core\session\manager::write_close();

$css = \theme_brandable\local\preview::get_css($revisionid, (bool) $rtl);

header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: private, max-age=31536000, immutable');
header('Content-Length: ' . strlen($css));
echo $css;
