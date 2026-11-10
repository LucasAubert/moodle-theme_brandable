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

#[\PHPUnit\Framework\Attributes\CoversClass(inspector::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(sampler::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\theme_brandable\output\mustache\recording_template::class)]
/**
 * Tests for the page inspector.
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\inspector\inspector
 * @covers     \theme_brandable\local\inspector\sampler
 * @covers     \theme_brandable\output\mustache\recording_template
 */
final class inspector_test extends \advanced_testcase {
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

    public function test_sampler_truncates_and_strips_sensitive_data(): void {
        $context = [
            'sesskey' => 'abc',
            'token' => 'secret',
            'user' => (object) ['fullname' => 'Jane Doe', 'email' => 'jane@example.com', 'id' => 5],
            'text' => str_repeat('x', 500),
            'contact' => 'Write to jane@example.com please',
            'items' => range(1, 20),
            'deep' => ['a' => ['b' => ['c' => ['d' => ['e' => 'too deep']]]]],
            'flag' => true,
        ];
        $sample = sampler::sample($context);

        $this->assertArrayNotHasKey('sesskey', $sample);
        $this->assertArrayNotHasKey('token', $sample);
        $this->assertSame(sampler::REDACTED, $sample['user']['fullname']);
        $this->assertSame(sampler::REDACTED, $sample['user']['email']);
        $this->assertSame(5, $sample['user']['id']);
        $this->assertSame(sampler::MAX_STRING + 1, \core_text::strlen($sample['text']));
        $this->assertSame('Write to ' . sampler::REDACTED . ' please', $sample['contact']);
        $this->assertCount(sampler::MAX_ITEMS, $sample['items']);
        $this->assertSame('…', $sample['deep']['a']['b']['c']);
        $this->assertLessThanOrEqual(sampler::MAX_BYTES, strlen(json_encode(sampler::sample(array_fill(0, 50, $context)))));
    }

    public function test_records_rendered_templates_with_context_and_partials(): void {
        global $PAGE;

        revision_manager::create([
            change::from_array(['type' => 'template', 'target' => 'theme_brandable/inspected',
                'content' => '<p>{{name}}</p>{{> theme_brandable/inspected_part}}']),
            change::from_array(['type' => 'template', 'target' => 'theme_brandable/inspected_part', 'content' => '<i>part</i>']),
        ], 'p', 's');
        revision_manager::publish();

        $PAGE->set_url('/my/');
        $PAGE->set_context(\context_system::instance());
        $PAGE->force_theme('brandable');
        inspector::reset(true);
        // Template markers are tested on their own (markers_test).
        \theme_brandable\local\preview::set_markers(false);

        $renderer = $PAGE->get_renderer('mod_forum');
        $this->assertSame(
            '<p>Ada</p><i>part</i>',
            $renderer->render_from_template('theme_brandable/inspected', ['name' => 'Ada', 'email' => 'ada@example.com'])
        );
        // Second rendering: same renderer, still recorded (wrapped template cached by the renderer).
        $renderer->render_from_template('theme_brandable/inspected', ['name' => 'Bob']);

        $recorded = array_column(inspector::get_recorded(), null, 'name');
        $this->assertSame(['name' => 'Ada', 'email' => sampler::REDACTED], $recorded['theme_brandable/inspected']['sampleContext']);
        $this->assertArrayHasKey('theme_brandable/inspected_part', $recorded);
        $this->assertNull($recorded['theme_brandable/inspected_part']['sampleContext']);
    }

    public function test_nothing_is_recorded_outside_preview(): void {
        inspector::reset(false);
        inspector::record('core/loading', ['a' => 1]);
        $this->assertSame([], inspector::get_recorded());
    }

    public function test_pages_are_stored_by_url(): void {
        inspector::store(
            'http://example.com/course/view.php?id=2&section=1#top',
            'course-view',
            [['name' => 'core/a', 'sampleContext' => null]]
        );
        inspector::store('http://example.com/my/', 'my-index', [['name' => 'core/b', 'sampleContext' => null]]);

        $this->assertSame('my-index', inspector::get_page()['pagetype']);
        $this->assertSame('course-view', inspector::get_page('http://example.com/course/view.php?section=1&id=2')['pagetype']);
        $this->assertNull(inspector::get_page('http://example.com/admin/'));

        for ($i = 0; $i < inspector::MAX_PAGES + 2; $i++) {
            inspector::store("http://example.com/p.php?i=$i", 'p', []);
        }
        $this->assertNull(inspector::get_page('http://example.com/my/'));
    }
}
