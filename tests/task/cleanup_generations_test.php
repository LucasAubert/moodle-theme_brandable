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

namespace theme_brandable\task;

use theme_brandable\local\generator\generation_manager;

#[\PHPUnit\Framework\Attributes\CoversClass(cleanup_generations::class)]
/**
 * Tests for the generations cleanup task.
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\task\cleanup_generations
 */
final class cleanup_generations_test extends \advanced_testcase {
    /**
     * Inserts a generation.
     *
     * @param string $status Status.
     * @param int $age Age in seconds.
     * @return int Id.
     */
    private function generation(string $status, int $age): int {
        global $DB;
        return $DB->insert_record('theme_brandable_generation', (object) [
            'userid' => 2, 'generator' => 'mock', 'prompt' => 'p', 'status' => $status,
            'revisionid' => $status === generation_manager::COMPLETED ? 1 : null,
            'timecreated' => time() - $age, 'timemodified' => time() - $age,
        ]);
    }

    public function test_cleanup(): void {
        global $DB;
        $this->resetAfterTest();

        $oldfailed = $this->generation(generation_manager::FAILED, 31 * DAYSECS);
        $recentfailed = $this->generation(generation_manager::FAILED, DAYSECS);
        $oldcompleted = $this->generation(generation_manager::COMPLETED, 400 * DAYSECS);
        $stale = $this->generation(generation_manager::RUNNING, 2 * DAYSECS);
        $running = $this->generation(generation_manager::RUNNING, 60);

        (new cleanup_generations())->execute();

        $this->assertFalse($DB->record_exists('theme_brandable_generation', ['id' => $oldfailed]));
        $this->assertTrue($DB->record_exists('theme_brandable_generation', ['id' => $recentfailed]));
        // Steps of revisions are kept.
        $this->assertTrue($DB->record_exists('theme_brandable_generation', ['id' => $oldcompleted]));
        $record = $DB->get_record('theme_brandable_generation', ['id' => $stale]);
        $this->assertSame(generation_manager::FAILED, $record->status);
        $this->assertSame('interrupted', $record->errorcode);
        $this->assertSame(get_string('error:interrupted', 'theme_brandable'), $record->errormessage);
        $this->assertSame(generation_manager::RUNNING, $DB->get_field('theme_brandable_generation', 'status', ['id' => $running]));
    }
}
