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

namespace theme_brandable\local\overrides;

use theme_brandable\local\parent_theme;

#[\PHPUnit\Framework\Attributes\CoversClass(outdated::class)]
/**
 * Tests of the template overrides made from another version of their original.
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\overrides\outdated
 */
final class outdated_test extends \advanced_testcase {
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

    /**
     * A map entry of a template override.
     *
     * @param string $target Template.
     * @param string|null $sourcehash Hash of the original when the override was made.
     * @param string $level Scope level.
     * @return array
     */
    private function entry(string $target, ?string $sourcehash, string $level = 'site'): array {
        return ['target' => $target, 'scopelevel' => $level, 'scopevalue' => null, 'content' => '<p>x</p>',
            'sourcehash' => $sourcehash];
    }

    public function test_templates_whose_original_changed(): void {
        $map = resolver::empty_map();
        $map[change::TYPE_TEMPLATE] = [
            'core/action_link' => $this->entry('core/action_link', template_source::get_original_hash('core/action_link')),
            'core/loading' => $this->entry('core/loading', hash('sha256', 'another version')),
            'core/loading@page' => $this->entry('core/loading', hash('sha256', 'another version'), 'page'),
            // Brandable's own template: no original.
            'theme_brandable/hero_banner' => $this->entry('theme_brandable/hero_banner', null),
            // A template the site no longer has.
            'core/doesnotexist' => $this->entry('core/doesnotexist', hash('sha256', 'gone')),
        ];
        $this->assertSame(['core/doesnotexist', 'core/loading'], outdated::templates($map));
        $this->assertSame([], outdated::templates(resolver::empty_map()));
    }
}
