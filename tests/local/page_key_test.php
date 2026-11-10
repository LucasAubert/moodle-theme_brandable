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

#[\PHPUnit\Framework\Attributes\CoversClass(page_key::class)]
/**
 * Tests for the Brandable page key (bp-*).
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\page_key
 */
final class page_key_test extends \advanced_testcase {
    /**
     * Creates empty files in a fresh directory.
     *
     * @param string[] $files Relative paths.
     * @return string Directory.
     */
    private function tree(array $files): string {
        $dir = make_request_directory();
        foreach ($files as $file) {
            @mkdir(dirname("$dir/$file"), 0777, true);
            touch("$dir/$file");
        }
        return $dir;
    }

    public function test_from_path(): void {
        $this->assertSame('bp-my-index', page_key::from_path('/my/index.php'));
        $this->assertSame('bp-my-courses', page_key::from_path('/my/courses.php'));
        $this->assertSame('bp-mod-forum-discuss', page_key::from_path('/mod/forum/discuss.php'));
        $this->assertSame('bp-index', page_key::from_path('/index.php'));
        $this->assertSame(
            'bp-admin-tool-dataprivacy-data-requests',
            page_key::from_path('/admin/tool/dataprivacy/data_requests.php')
        );
        $this->assertSame('bp-course-view', page_key::from_path('course\\view.php'));
        $this->assertNull(page_key::from_path('/.php'));
        foreach (['bp-my-index', 'bp-mod-forum-discuss'] as $key) {
            $this->assertMatchesRegularExpression(page_key::PATTERN, $key);
        }
    }

    public function test_from_script_on_moodle_45_layout(): void {
        $root = $this->tree(['my/index.php', 'mod/forum/discuss.php', 'admin/cli/upgrade.php']);
        $this->assertSame('bp-my-index', page_key::from_script("$root/my/index.php", $root));
        $this->assertSame('bp-mod-forum-discuss', page_key::from_script("$root/mod/forum/discuss.php", "$root/"));
        $this->assertNull(page_key::from_script('/elsewhere/index.php', $root));
    }

    public function test_from_script_on_moodle_51_layout(): void {
        // 5.1+: $CFG->dirroot is the public/ folder; the key never contains it.
        $root = $this->tree(['public/my/courses.php', 'public/r.php', 'admin/cli/upgrade.php']);
        $this->assertSame('bp-my-courses', page_key::from_script("$root/public/my/courses.php", "$root/public"));
        // Scripts out of the public folder (CLI) are not pages.
        $this->assertNull(page_key::from_script("$root/admin/cli/upgrade.php", "$root/public"));
    }

    public function test_routed_pages_use_the_route_path(): void {
        $root = $this->tree(['public/r.php']);
        $dirroot = "$root/public";
        $this->assertSame(
            'bp-course-manage',
            page_key::from_script("$dirroot/r.php", $dirroot, '/r.php/course/12/manage', 'http://example.com')
        );
        // Rewritten URLs (no r.php in the path) and sites in a subfolder.
        $this->assertSame(
            'bp-course-manage',
            page_key::from_script("$dirroot/r.php", $dirroot, '/moodle/course/12/manage?x=1', 'http://example.com/moodle')
        );
        $this->assertSame('bp-r', page_key::from_route('/r.php', 'http://example.com'));
    }

    public function test_current(): void {
        global $CFG;

        // Under PHPUnit the script is vendor/bin/phpunit: under the Moodle root on 4.5, out of public/ on 5.1+.
        $expected = is_dir($CFG->dirroot . '/vendor') ? 'bp-vendor-bin-phpunit' : null;
        $this->assertSame($expected, page_key::current());
    }
}
