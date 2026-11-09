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

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\revision_manager;
use theme_brandable\local\plugin_updates;

/**
 * Steps of Brandable: a draft of the design, a new version of the plugin announced by Brandable.
 *
 * Everything is set up locally: the tests never call the Brandable service.
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_theme_brandable extends behat_base {
    /**
     * Adds a revision to the draft of the design, with one SCSS chunk applied after the parent theme.
     *
     * @Given /^the Brandable draft changes the SCSS with "(?P<scss_string>(?:[^"]|\\")*)"$/
     * @param string $scss SCSS of the chunk.
     */
    public function the_brandable_draft_changes_the_scss_with(string $scss): void {
        $change = change::from_array(['type' => 'scss', 'target' => 'post/behat', 'content' => str_replace('\\"', '"', $scss)]);
        revision_manager::create([$change], 'Behat', 'Rounder corners', (int) get_admin()->id);
    }

    /**
     * Brandable announces a new version of the plugin, as the daily check would keep it (GET /plugin/latest).
     *
     * @Given /^Brandable announces the plugin release "(?P<release_string>[^"]*)"( as a security update)?$/
     * @param string $release Release (9.0.0).
     * @param string $security Whether the release fixes a security issue.
     */
    public function brandable_announces_the_plugin_release(string $release, string $security = ''): void {
        global $CFG;
        set_config(plugin_updates::LATEST_CONFIG, json_encode([
            'release' => $release, 'version' => 2099010100, 'maturity' => 'MATURITY_STABLE', 'publishedat' => time(),
            'supported' => [405, (int) $CFG->branch], 'moodlelabel' => '', 'compatible' => true,
            'notes' => "- Faster preview.", 'noteslang' => 'en', 'updateavailable' => true, 'minimumversion' => 0,
            'installurl' => 'https://brandablelms.com/en/docs', 'security' => $security !== '', 'directory' => false,
            'timechecked' => time(),
        ]), 'theme_brandable');
    }
}
