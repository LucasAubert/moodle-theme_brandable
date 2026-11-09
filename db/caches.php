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
 * Cache definitions of theme_brandable.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    // Resolved overrides of a revision. Revisions are immutable, so entries never need invalidating.
    'resolved' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'staticacceleration' => true,
        'staticaccelerationsize' => 4,
    ],
    // Full theme CSS compiled for the preview, keyed by the hash of what it depends on.
    'previewcss' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
    ],
    // Templates rendered on the last pages of the preview session (page inspector).
    'inspector' => [
        'mode' => cache_store::MODE_SESSION,
        'simplekeys' => true,
        'simpledata' => false,
    ],
    // Results of synchronous generators (mock), until the editor polls them.
    'syncresults' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl' => DAYSECS,
    ],
];
