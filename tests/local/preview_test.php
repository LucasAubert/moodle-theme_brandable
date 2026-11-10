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

use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\context;
use theme_brandable\local\overrides\revision_manager;

#[\PHPUnit\Framework\Attributes\CoversClass(preview::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(hook_callbacks::class)]
/**
 * Tests for the preview mode.
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\preview
 * @covers     \theme_brandable\local\hook_callbacks
 */
final class preview_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        preview::reset();
    }

    #[\Override]
    protected function tearDown(): void {
        preview::disable();
        preview::reset();
        parent::tearDown();
    }

    /**
     * Creates a revision overriding a template and, optionally, an SCSS chunk.
     *
     * @param string $content Template content.
     * @param string|null $scss SCSS chunk content.
     * @return int Revision id.
     */
    private function revision(string $content, ?string $scss = null): int {
        $changes = [change::from_array(['type' => 'template', 'target' => 'theme_brandable/preview_test', 'content' => $content])];
        if ($scss !== null) {
            $changes[] = change::from_array(['type' => 'scss', 'target' => 'post/preview-test', 'content' => $scss]);
        }
        return revision_manager::create($changes, 'p', 's');
    }

    public function test_only_editors_can_enable_preview(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->expectException(\required_capability_exception::class);
        preview::enable();
    }

    public function test_preview_switches_the_session_to_the_draft(): void {
        $this->setAdminUser();
        $this->revision('published');
        revision_manager::publish();
        $draft = $this->revision('draft');

        $this->assertFalse(preview::is_active());
        $this->assertSame('published', context::get_template('theme_brandable/preview_test'));

        preview::enable();
        $this->assertTrue(preview::is_active());
        $this->assertSame($draft, preview::get_revision());
        $this->assertSame('draft', context::get_template('theme_brandable/preview_test'));

        // Following the draft: undo is reflected immediately.
        revision_manager::undo();
        $this->assertSame('published', context::get_template('theme_brandable/preview_test'));

        preview::disable();
        $this->assertFalse(preview::is_active());
    }

    public function test_preview_flag_is_ignored_without_capability(): void {
        global $SESSION;

        $this->setAdminUser();
        $this->revision('draft');
        // A session flagged for preview, used by someone without the capability.
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $SESSION->theme_brandable_preview = (object) ['revision' => null];
        preview::reset();
        $this->assertFalse(preview::is_active());
        $this->assertNull(context::get_template('theme_brandable/preview_test'));
    }

    public function test_css_urls_are_replaced_only_when_scss_differs(): void {
        $this->setAdminUser();
        $original = [new \moodle_url('/theme/styles.php/brandable/1_1/all')];

        $this->revision('template only');
        preview::enable();
        $this->assertSame($original, preview::alter_css_urls($original));

        $draft = $this->revision('with scss', '.brandable-preview-test { color: #bada55; }');
        $urls = preview::alter_css_urls($original);
        $this->assertCount(1, $urls);
        $this->assertStringContainsString('/theme/brandable/preview_css.php', $urls[0]->out(false));
        $this->assertEquals($draft, $urls[0]->get_param('rev'));
        $this->assertSame(preview::get_css_key($draft, false), $urls[0]->get_param('hash'));

        preview::disable();
        $this->assertSame($original, preview::alter_css_urls($original));
    }

    public function test_get_css_compiles_the_full_theme_css_of_the_revision(): void {
        $this->setAdminUser();
        $draft = $this->revision('x', '.brandable-preview-test { color: #bada55; }');

        $css = preview::get_css($draft, false);
        $this->assertStringContainsString('#bada55', $css);
        // Full theme CSS (Bootstrap from the parent SCSS), not only the chunk.
        $this->assertStringContainsString('.btn-primary', $css);
        // Served from the cache afterwards.
        $this->assertSame($css, \cache::make('theme_brandable', 'previewcss')->get(preview::get_css_key($draft, false)));
    }

    public function test_banner(): void {
        global $PAGE;

        $this->setAdminUser();
        $draft = $this->revision('x');
        $PAGE->set_url('/my/');
        $PAGE->set_context(\context_system::instance());
        $PAGE->force_theme('brandable');
        $renderer = $PAGE->get_renderer('core', null, RENDERER_TARGET_GENERAL);

        $hook = new \core\hook\output\before_standard_top_of_body_html_generation($renderer);
        hook_callbacks::before_standard_top_of_body_html_generation($hook);
        $this->assertSame('', $hook->get_output());

        preview::enable();
        $hook = new \core\hook\output\before_standard_top_of_body_html_generation($renderer);
        hook_callbacks::before_standard_top_of_body_html_generation($hook);
        $this->assertStringContainsString('theme-brandable-preview-banner', $hook->get_output());
        $this->assertStringContainsString(get_string('previewbanner', 'theme_brandable', $draft), $hook->get_output());
        $this->assertStringContainsString('/theme/brandable/preview.php', $hook->get_output());
    }

    public function test_publishing_from_a_preview_session_builds_the_published_css(): void {
        global $CFG;

        $this->setAdminUser();
        $this->revision('a', '.brandable-published { color: #0a0a0a; }');
        revision_manager::publish();
        $this->revision('c', '.brandable-second { color: #111111; }');
        revision_manager::publish();
        $this->revision('b', '.brandable-draft { color: #d4af37; }');
        preview::enable();

        // Back to the previous version from the editor (preview session, draft ahead of the published version).
        revision_manager::revert_to_previous();

        $file = theme_get_css_filename('brandable', theme_get_revision(), theme_get_sub_revision_for_theme('brandable'), 'ltr');
        $this->assertFileExists($file);
        $css = file_get_contents($file);
        $this->assertStringContainsString('#0a0a0a', $css);
        $this->assertStringNotContainsString('#d4af37', $css);
        $this->assertStringStartsWith($CFG->localcachedir, $file);
    }
}
