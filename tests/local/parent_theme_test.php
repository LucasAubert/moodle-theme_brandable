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

/**
 * Tests for the dynamic parent theme resolution.
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\parent_theme
 */
#[\PHPUnit\Framework\Attributes\CoversClass(parent_theme::class)]
final class parent_theme_test extends \advanced_testcase {
    use \theme_brandable\tests\requires_classic;

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
     * Sets the parent theme setting.
     *
     * @param string $parent Theme name.
     */
    private function set_parent(string $parent): void {
        set_config(parent_theme::SETTING, $parent, 'theme_brandable');
        parent_theme::reset_caches();
    }

    public function test_get_name_defaults_to_boost(): void {
        unset_config(parent_theme::SETTING, 'theme_brandable');
        $this->assertSame('boost', parent_theme::get_name());
    }

    public function test_get_name_rejects_invalid_parents(): void {
        foreach (['brandable', 'doesnotexist', '../boost', ''] as $invalid) {
            $this->set_parent($invalid);
            $this->assertSame('boost', parent_theme::get_name(), "'$invalid' must not be accepted");
        }
    }

    public function test_get_name_reads_settings_object(): void {
        $this->assertSame('boost', parent_theme::get_name((object) []));
        $this->require_classic();
        $this->assertSame('classic', parent_theme::get_name((object) [parent_theme::SETTING => 'classic']));
    }

    public function test_get_available_parents(): void {
        $parents = parent_theme::get_available_parents();
        $this->assertArrayHasKey('boost', $parents);
        if (self::has_classic()) {
            $this->assertArrayHasKey('classic', $parents);
        }
        $this->assertArrayNotHasKey('brandable', $parents);
    }

    public function test_get_chain(): void {
        $this->assertSame(['boost'], parent_theme::get_chain('boost'));
        $this->require_classic();
        $this->assertSame(['classic', 'boost'], parent_theme::get_chain('classic'));
    }

    public function test_apply_with_classic(): void {
        $this->require_classic();
        $theme = (object) ['name' => 'brandable', 'settings' => (object) [parent_theme::SETTING => 'classic']];
        parent_theme::apply($theme);

        $this->assertSame(['classic', 'boost'], $theme->parents);
        // Classic's own layouts win over Boost's ones.
        $this->assertSame('columns.php', $theme->layouts['standard']['file']);
        $this->assertSame('boost', $theme->layouts['login']['theme']);
        // Non-inherited properties are copied from the closest theme defining them.
        $this->assertFalse($theme->haseditswitch);
        $this->assertTrue($theme->usefallback);
        // Properties core resolves through the parents are not copied (their callbacks would run twice).
        foreach (['scss', 'prescsscallback', 'extrascsscallback', 'precompiledcsscallback', 'iconsystem', 'sheets'] as $name) {
            $this->assertObjectNotHasProperty($name, $theme);
        }
    }

    public function test_apply_with_boost(): void {
        $theme = (object) ['name' => 'brandable', 'settings' => (object) []];
        parent_theme::apply($theme);

        $this->assertSame(['boost'], $theme->parents);
        $this->assertSame('drawers.php', $theme->layouts['standard']['file']);
        $this->assertTrue($theme->haseditswitch);
    }

    public function test_theme_config_load_follows_parent(): void {
        global $CFG;
        $this->require_classic();

        $this->set_parent('classic');
        $theme = \theme_config::load('brandable');
        $this->assertSame('brandable', $theme->name);
        $this->assertSame(['classic', 'boost'], $theme->parents);
        $this->assertSame($CFG->dirroot . '/theme/classic/layout/columns.php', $theme->layout_file('standard'));
        $this->assertSame($CFG->dirroot . '/theme/boost/layout/login.php', $theme->layout_file('login'));
        $this->assertSame('\\theme_classic\\output\\icon_system_fontawesome', $theme->get_icon_system());

        $this->set_parent('boost');
        $theme = \theme_config::load('brandable');
        $this->assertSame(['boost'], $theme->parents);
        $this->assertSame($CFG->dirroot . '/theme/boost/layout/drawers.php', $theme->layout_file('standard'));
    }

    public function test_get_core_renderer_class(): void {
        $this->assertSame('\\theme_boost\\output\\core_renderer', parent_theme::get_core_renderer_class('boost'));
        $this->require_classic();
        $this->assertSame('\\theme_classic\\output\\core_renderer', parent_theme::get_core_renderer_class('classic'));
    }

    public function test_brandable_core_renderer_extends_a_core_renderer(): void {
        // The alias is resolved once per process: only check it targets a valid core renderer.
        $this->assertTrue(is_subclass_of(\theme_brandable\output\core_renderer::class, \core\output\core_renderer::class));
        $this->assertContains(
            '\\' . get_parent_class(\theme_brandable\output\core_renderer::class),
            ['\\theme_boost\\output\\core_renderer', '\\theme_classic\\output\\core_renderer'],
        );
    }
}
