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

namespace theme_brandable;

#[\PHPUnit\Framework\Attributes\CoversNothing]
/**
 * Upgrade steps: none before 0.1.0, the first version published.
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class upgrade_test extends \advanced_testcase {
    #[\Override]
    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->dirroot . '/theme/brandable/db/upgrade.php');
    }

    public function test_upgrade_from_a_published_version(): void {
        $this->assertTrue(xmldb_theme_brandable_upgrade(2026110505));
        $this->assertTrue(xmldb_theme_brandable_upgrade(2026110601));
    }

    public function test_alpha_versions_are_not_upgraded(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('upgradefromalpha', 'theme_brandable', 2026110400));
        xmldb_theme_brandable_upgrade(2026110400);
    }
}
