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
 * Tests for the parent theme settings mirror, its observer and its scheduled task.
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\settings_mirror
 * @covers     \theme_brandable\local\observer
 * @covers     \theme_brandable\task\sync_parent_settings
 */
#[\PHPUnit\Framework\Attributes\CoversClass(settings_mirror::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(observer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\theme_brandable\task\sync_parent_settings::class)]
final class settings_mirror_test extends \advanced_testcase {
    use \theme_brandable\tests\requires_classic;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        // The settings of Classic stand for those of a parent other than Boost.
        $this->require_classic();
        $this->resetAfterTest();
        set_config(parent_theme::SETTING, 'classic', 'theme_brandable');
        parent_theme::reset_caches();
    }

    #[\Override]
    protected function tearDown(): void {
        parent_theme::reset_caches();
        parent::tearDown();
    }

    public function test_sync_all_copies_config(): void {
        set_config('brandcolor', '#ff0000', 'theme_classic');
        set_config('navbardark', '1', 'theme_classic');
        set_config('stale', 'x', 'theme_brandable');

        $this->assertTrue(settings_mirror::sync_all());

        $this->assertSame('#ff0000', get_config('theme_brandable', 'brandcolor'));
        $this->assertSame('1', get_config('theme_brandable', 'navbardark'));
        $this->assertFalse(get_config('theme_brandable', 'stale'));
        // Reserved and core keys are left alone.
        $this->assertSame('classic', get_config('theme_brandable', parent_theme::SETTING));
        $this->assertNotSame(get_config('theme_classic', 'version'), get_config('theme_brandable', 'version'));

        // Nothing changes on a second run.
        $this->assertFalse(settings_mirror::sync_all());
    }

    public function test_settings_of_brandable_survive_the_resynchronisation(): void {
        // The connection of the site (a resynchronisation used to delete it: unprefixed names).
        account\connection::store_key('bsk_9fQxAbCdEfGhIjKlMnOpQrStUvWxYz0123456789-_A', ['environment' => ['id' => 'e1']]);
        set_config(js\modules::ENABLED_CONFIG, 1, 'theme_brandable');
        settings_mirror::sync_all();
        (new \theme_brandable\task\sync_parent_settings())->execute();
        $this->assertTrue(account\connection::is_connected());
        $this->assertSame('e1', get_config('theme_brandable', account\connection::ENVIRONMENT_CONFIG));
        $this->assertSame('1', get_config('theme_brandable', js\modules::ENABLED_CONFIG));

        // Every setting of Brandable is named with the reserved prefix (constants *_CONFIG of the classes).
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../classes'));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all(
                "/const ([A-Z_]*CONFIG[A-Z_]*) = '([^']+)'/",
                file_get_contents($file->getPathname()),
                $matches,
                PREG_SET_ORDER
            );
            foreach ($matches as [, $constant, $name]) {
                $this->assertStringStartsWith(parent_theme::RESERVED_PREFIX, $name, $file->getFilename() . " $constant");
            }
        }
    }

    public function test_sync_all_never_copies_core_keys(): void {
        set_config('themerev', '123', 'theme_classic');
        set_config('themerev', '456', 'theme_brandable');
        settings_mirror::sync_all();
        $this->assertSame('456', get_config('theme_brandable', 'themerev'));
    }

    public function test_sync_setting(): void {
        set_config('brandcolor', '#00ff00', 'theme_classic');
        set_config('navbardark', '1', 'theme_classic');

        $this->assertTrue(settings_mirror::sync_setting('brandcolor'));
        $this->assertSame('#00ff00', get_config('theme_brandable', 'brandcolor'));
        $this->assertNotSame('1', get_config('theme_brandable', 'navbardark'));

        unset_config('brandcolor', 'theme_classic');
        $this->assertTrue(settings_mirror::sync_setting('brandcolor'));
        $this->assertFalse(get_config('theme_brandable', 'brandcolor'));

        // Reserved names are never written by the mirror.
        set_config(parent_theme::SETTING, 'boost', 'theme_classic');
        settings_mirror::sync_setting(parent_theme::SETTING);
        $this->assertSame('classic', get_config('theme_brandable', parent_theme::SETTING));
    }

    public function test_observer_syncs_parent_settings(): void {
        set_config('brandcolor', '#0000ff', 'theme_classic');
        add_to_config_log('brandcolor', '', '#0000ff', 'theme_classic');
        $this->assertSame('#0000ff', get_config('theme_brandable', 'brandcolor'));

        // Settings of other plugins are ignored.
        set_config('brandcolor', '#abcdef', 'theme_boost');
        add_to_config_log('brandcolor', '', '#abcdef', 'theme_boost');
        $this->assertSame('#0000ff', get_config('theme_brandable', 'brandcolor'));
    }

    public function test_task_resyncs_and_bumps_sub_revision(): void {
        $task = new \theme_brandable\task\sync_parent_settings();
        settings_mirror::sync_all();
        $before = theme_get_sub_revision_for_theme('brandable');

        ob_start();
        $task->execute();
        ob_end_clean();
        $this->assertSame($before, theme_get_sub_revision_for_theme('brandable'));

        set_config('brandcolor', '#111111', 'theme_classic');
        ob_start();
        $task->execute();
        ob_end_clean();
        $this->assertSame('#111111', get_config('theme_brandable', 'brandcolor'));
        $this->assertGreaterThan($before, theme_get_sub_revision_for_theme('brandable'));
    }

    public function test_parent_changed_callback_resyncs_and_purges(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/theme/brandable/lib.php');

        set_config(parent_theme::SETTING, 'boost', 'theme_brandable');
        parent_theme::reset_caches();
        settings_mirror::sync_all();
        $this->assertFalse(get_config('theme_brandable', 'navbardark'));
        set_config('themerev', 1);
        set_config('templaterev', 1);

        set_config('navbardark', '1', 'theme_classic');
        set_config(parent_theme::SETTING, 'classic', 'theme_brandable');
        theme_brandable_parent_changed('s_theme_brandable_' . parent_theme::SETTING);

        $this->assertSame('1', get_config('theme_brandable', 'navbardark'));
        // Read the database: PHPUnit may force these values in $CFG.
        $this->assertGreaterThan(1, (int) $DB->get_field('config', 'value', ['name' => 'themerev']));
        $this->assertGreaterThan(1, (int) $DB->get_field('config', 'value', ['name' => 'templaterev']));
    }
}
