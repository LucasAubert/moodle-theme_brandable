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

namespace theme_brandable\local;

#[\PHPUnit\Framework\Attributes\CoversClass(parent_notices::class)]
/**
 * Tests of the notices of the parent theme in the editor (with a parent that has them: tests of the development sites).
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\parent_notices
 */
final class parent_notices_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        parent_theme::reset_caches();
    }

    #[\Override]
    protected function tearDown(): void {
        parent_theme::reset_caches();
        parent::tearDown();
    }

    public function test_no_notice_with_boost(): void {
        // Boost has no custom CSS added after Brandable nor a dark mode of its own.
        set_config('customcss', '.x { color: red; }', 'theme_boost');
        $this->assertSame([], parent_notices::get('boost'));
    }
}
