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

namespace theme_brandablert\privacy;

use core_privacy\local\metadata\collection;

/**
 * Privacy provider: no personal data stored; the daily signal of the licence only sends data about the site.
 *
 * @package    theme_brandablert
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements \core_privacy\local\metadata\provider {
    /**
     * Describes the data sent to Brandable.
     *
     * @param collection $collection Collection.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_external_location_link('brandable_licence', [
            'siteurl' => 'privacy:metadata:licence:siteurl',
            'theme' => 'privacy:metadata:licence:theme',
            'moodleversion' => 'privacy:metadata:licence:moodleversion',
        ], 'privacy:metadata:licence');
        return $collection;
    }
}
