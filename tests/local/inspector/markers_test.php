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

namespace theme_brandable\local\inspector;

use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\revision_manager;
use theme_brandable\local\preview;

#[\PHPUnit\Framework\Attributes\CoversClass(markers::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\theme_brandable\output\mustache\recording_template::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\theme_brandable\output\mustache\loader::class)]
/**
 * Tests for the template markers of the preview.
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\inspector\markers
 * @covers     \theme_brandable\output\mustache\recording_template
 * @covers     \theme_brandable\output\mustache\loader
 */
final class markers_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        inspector::reset();
    }

    #[\Override]
    protected function tearDown(): void {
        inspector::reset();
        parent::tearDown();
    }

    public function test_wrap_output_only_wraps_html(): void {
        $this->assertSame('<!-- bt:start core/a --><p>x</p><!-- bt:end core/a -->', markers::wrap_output('core/a', '<p>x</p>'));
        $this->assertSame('', markers::wrap_output('core/a', ''));
        $this->assertSame('  ', markers::wrap_output('core/a', '  '));
        $this->assertSame('plain text', markers::wrap_output('core/a', 'plain text'));
        $this->assertSame('<b>x</b> text', markers::wrap_output('core/a', '<b>x</b> text'));
        $wrapped = markers::wrap_output('core/a', '<p>x</p>');
        $this->assertSame($wrapped, markers::wrap_output('core/b', $wrapped));
        // A double dash would end the comment early.
        $this->assertStringContainsString('bt:start a-b ', markers::wrap_output('a--b', '<p>x</p>'));
    }

    public function test_wrap_source_needs_tags_out_of_comments(): void {
        $this->assertSame('{{! <p>doc</p> }}{{name}}', markers::wrap_source('core/a', '{{! <p>doc</p> }}{{name}}'));
        $this->assertSame(
            '<!-- bt:start core/a -->{{! doc }}<span>{{name}}</span><!-- bt:end core/a -->',
            markers::wrap_source('core/a', '{{! doc }}<span>{{name}}</span>')
        );
    }

    public function test_is_active(): void {
        global $CFG;

        $this->assertFalse(markers::is_active());
        inspector::reset(true);
        $this->assertTrue(markers::is_active());
        preview::set_markers(false);
        $this->assertFalse(markers::is_active());
        preview::set_markers(true);
        $CFG->debugtemplateinfo = true;
        $this->assertFalse(markers::is_active());
    }

    public function test_rendered_templates_and_partials_are_marked(): void {
        global $PAGE;

        revision_manager::create([
            change::from_array(['type' => 'template', 'target' => 'theme_brandable/marked',
                'content' => '<p>{{name}}</p>{{> theme_brandable/marked_part}}']),
            change::from_array(['type' => 'template', 'target' => 'theme_brandable/marked_part', 'content' => '<i>part</i>']),
        ], 'p', 's');
        revision_manager::publish();

        $PAGE->set_url('/my/');
        $PAGE->set_context(\context_system::instance());
        $PAGE->force_theme('brandable');
        inspector::reset(true);

        $renderer = $PAGE->get_renderer('mod_forum');
        $this->assertSame(
            '<!-- bt:start theme_brandable/marked --><p>Ada</p>'
                . '<!-- bt:start theme_brandable/marked_part --><i>part</i><!-- bt:end theme_brandable/marked_part -->'
                . '<!-- bt:end theme_brandable/marked -->',
            $renderer->render_from_template('theme_brandable/marked', ['name' => 'Ada'])
        );
    }
}
