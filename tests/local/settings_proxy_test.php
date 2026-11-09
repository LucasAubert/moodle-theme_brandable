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
 * Tests for the parent settings proxy and Brandable's settings page.
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\settings_proxy
 * @covers     \theme_brandable\admin\settings_page
 */
#[\PHPUnit\Framework\Attributes\CoversClass(settings_proxy::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\theme_brandable\admin\settings_page::class)]
final class settings_proxy_test extends \advanced_testcase {
    use \theme_brandable\tests\requires_classic;

    #[\Override]
    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->libdir . '/adminlib.php');
    }

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        parent_theme::reset_caches();
        settings_proxy::reset();
    }

    #[\Override]
    protected function tearDown(): void {
        parent_theme::reset_caches();
        settings_proxy::reset();
        parent::tearDown();
    }

    /**
     * Selects the parent and returns a freshly loaded full admin tree.
     *
     * @param string $parent Parent theme.
     * @return \admin_root
     */
    private function load_tree(string $parent): \admin_root {
        set_config(parent_theme::SETTING, $parent, 'theme_brandable');
        parent_theme::reset_caches();
        return admin_get_root(true, true);
    }

    /**
     * Data provider of the tested parents.
     *
     * @return array
     */
    public static function parent_provider(): array {
        return [
            'boost' => ['boost'],
            'classic' => ['classic'],
        ];
    }

    /**
     * Parent pages are found, tabs expanded, Brandable's own page excluded.
     *
     * @param string $parent Parent theme.
     * @dataProvider parent_provider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('parent_provider')]
    public function test_get_parent_pages(string $parent): void {
        if ($parent === 'classic') {
            $this->require_classic();
        }
        $root = $this->load_tree($parent);
        $pages = settings_proxy::get_parent_pages($root, $parent, 'themesettingbrandable');

        // Boost has an Experimental tab from Moodle 5.3 (colour modes).
        $this->assertSame(["theme_{$parent}_general", "theme_{$parent}_advanced"], array_slice(array_keys($pages), 0, 2));
        $this->assertEmpty(array_diff(array_keys($pages), ["theme_{$parent}_general", "theme_{$parent}_advanced",
            "theme_{$parent}_experimental"]));
        $names = [];
        foreach ($pages as $page) {
            foreach ($page->settings as $setting) {
                $names[] = $setting->plugin . '/' . $setting->name;
            }
        }
        $this->assertContains("theme_{$parent}/brandcolor", $names);
        $this->assertNotContains('theme_brandable/' . parent_theme::SETTING, $names);
    }

    public function test_brandable_page_holds_only_its_own_settings(): void {
        $root = $this->load_tree('boost');
        $page = $root->locate('themesettingbrandable');
        $this->assertInstanceOf(\theme_brandable\admin\settings_page::class, $page);
        $names = array_map(fn($setting) => $setting->plugin . '/' . $setting->name, array_values((array) $page->settings));
        $this->assertContains('theme_brandable/' . parent_theme::SETTING, $names);
        foreach ($names as $name) {
            $this->assertStringStartsWith('theme_brandable/' . parent_theme::RESERVED_PREFIX, $name);
        }
    }

    public function test_locate_wraps_parent_callbacks(): void {
        $this->require_classic();
        $root = $this->load_tree('classic');
        $pages = settings_proxy::get_parent_pages($root, 'classic', 'themesettingbrandable');
        $brandcolor = $pages['theme_classic_general']->settings->theme_classicbrandcolor;
        $this->assertSame('theme_reset_all_caches', $brandcolor->updatedcallback);

        $root->locate('themesettingbrandable');
        $this->assertSame(settings_proxy::CALLBACK, $brandcolor->updatedcallback);

        // Saving through the proxied setting writes to the parent and refreshes the mirror at once.
        $original = $brandcolor->get_setting();
        $this->assertSame('', $brandcolor->write_setting('#336699'));
        $this->assertSame('#336699', get_config('theme_classic', 'brandcolor'));
        // Remove the copy made by the config log observer to check the callback path on its own.
        unset_config('brandcolor', 'theme_brandable');
        $this->assertTrue($brandcolor->post_write_settings($original));
        $this->assertSame('#336699', get_config('theme_brandable', 'brandcolor'));
    }

    public function test_output_html_renders_parent_tabs(): void {
        $this->require_classic();
        global $PAGE;

        $PAGE->set_url('/admin/settings.php', ['section' => 'themesettingbrandable']);
        $root = $this->load_tree('classic');
        $html = $root->locate('themesettingbrandable')->output_html();

        $this->assertStringContainsString('name="s_theme_brandable_' . parent_theme::SETTING . '"', $html);
        $this->assertStringContainsString('name="s_theme_classic_brandcolor"', $html);
        $this->assertStringContainsString('href="#theme_classic_advanced"', $html);
    }

    public function test_dependencies_of_the_parent_are_copied(): void {
        if (!class_exists('\\theme_boost\\colour_mode')) {
            $this->markTestSkipped('No dependency in the settings of Boost before Moodle 5.3.');
        }
        $root = $this->load_tree('boost');
        $page = $root->locate('themesettingbrandable');
        $this->assertTrue($page->has_dependencies());
        $this->assertStringContainsString('s_theme_boost_defaultcolourmode', json_encode($page->get_dependencies_for_javascript()));
        // Idempotent.
        $count = count($page->get_dependencies_for_javascript());
        $root->locate('themesettingbrandable');
        $this->assertSame($count, count($page->get_dependencies_for_javascript()));
    }
}
