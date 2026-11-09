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

namespace theme_brandable\local\generator;

use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\revision_manager;

/**
 * Tests for the targeted element (selection) and the verification of the selectors in the preview.
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\generator\generation_manager
 * @covers     \theme_brandable\local\generator\selection
 * @covers     \theme_brandable\local\overrides\revision_manager
 */
#[\PHPUnit\Framework\Attributes\CoversClass(generation_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(selection::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(revision_manager::class)]
final class verification_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        // Demo generator.
        $CFG->theme_brandable_devmode = true;
    }

    /**
     * A generator returning SCSS chunks, one result per start / repair.
     *
     * @param string[] $selectors Selector of the chunk of each result, in order.
     * @return sync_generator
     */
    private function chunks(array $selectors): sync_generator {
        $results = array_map(fn($selector) => new generation_result('Summary ' . $selector, [change::from_array([
            'type' => 'scss', 'target' => 'post/test', 'content' => $selector . ' { color: red; }',
        ])]), $selectors);
        return new class ($results) extends sync_generator {
            /** @var generation_result[] Remaining results. */
            private array $results;

            /**
             * Constructor.
             *
             * @param generation_result[] $results Results.
             */
            public function __construct(array $results) {
                $this->results = $results;
            }

            #[\Override]
            public function generate(string $prompt, generation_context $context): generation_result {
                return array_shift($this->results);
            }

            #[\Override]
            protected function repair_result(generation_result $result, array $errors): ?generation_result {
                return array_shift($this->results);
            }
        };
    }

    /**
     * Submits a prompt and runs the generation until it completes.
     *
     * @param sync_generator $generator Generator.
     * @param array|null $selection Targeted element.
     * @return \stdClass Generation record.
     */
    private function complete(sync_generator $generator, ?array $selection = null): \stdClass {
        $record = generation_manager::submit('prompt', null, $generator, $selection);
        $record = generation_manager::refresh($record->id, $generator);
        $this->assertSame(generation_manager::COMPLETED, $record->status, (string) ($record->errormessage ?? ''));
        return $record;
    }

    /**
     * Event types of a generation.
     *
     * @param \stdClass $record Generation record.
     * @return string[]
     */
    private function event_types(\stdClass $record): array {
        return array_column(generation_manager::export($record)['events'], 'type');
    }

    public function test_compiled_selectors_are_stored_with_the_scope_wrapper(): void {
        global $DB;

        $record = $this->complete($this->chunks(['.navbar .nav-link:hover, .card']));
        $changes = $DB->get_records('theme_brandable_change', ['revisionid' => $record->revisionid]);
        $selectors = json_decode(reset($changes)->selectors, true);
        $this->assertSame(['.navbar .nav-link:hover', '.card'], $selectors);

        $id = revision_manager::create([
            change::from_array(['type' => 'scss', 'target' => 'post/scoped',
                'content' => '.card { color: red; @media (min-width: 1px) { .x { color: blue; } } }',
                'scope' => ['level' => 'page', 'value' => 'bp-my-index']]),
            change::from_array(['type' => 'scss', 'target' => 'post/keyframes',
                'content' => '@keyframes spin { from { opacity: 0; } to { opacity: 1; } }']),
        ], 'p', 's');
        $selectors = $DB->get_records_menu('theme_brandable_change', ['revisionid' => $id], '', 'target, selectors');
        $this->assertSame(
            ['body.bp-my-index:not(#bp-s1) .card', 'body.bp-my-index:not(#bp-s1) .card .x'],
            json_decode($selectors['post/scoped'], true)
        );
        $this->assertSame([], json_decode($selectors['post/keyframes'], true));
    }

    public function test_successful_verification_is_recorded_once(): void {
        $record = $this->complete($this->chunks(['.card']));
        $record = generation_manager::verify($record->id, [], [], 1);
        $this->assertSame(generation_manager::COMPLETED, $record->status);
        $this->assertContains('verified', $this->event_types($record));
        $this->assertStringContainsString('1', end(generation_manager::export($record)['events'])['message']);

        // Reported again (editor reloaded): nothing more.
        $record = generation_manager::verify($record->id, [], [], 1);
        $this->assertCount(1, array_keys($this->event_types($record), 'verified'));
    }

    public function test_warnings_do_not_repair(): void {
        $record = $this->complete($this->chunks(['.card']));
        $record = generation_manager::verify($record->id, [], [['target' => 'post/test', 'selector' => '.card', 'count' => 3]], 1);
        $this->assertSame(generation_manager::COMPLETED, $record->status);
        $types = $this->event_types($record);
        $this->assertContains('verification_warning', $types);
        $this->assertContains('verified', $types);
    }

    public function test_failure_abandons_the_revision_and_repairs(): void {
        $generator = $this->chunks(['.ghost', '.card']);
        $record = $this->complete($generator);
        $first = (int) $record->revisionid;
        $this->assertSame($first, revision_manager::get_draft());

        $record = generation_manager::verify($record->id, [['target' => 'post/test', 'selector' => '.ghost']], [], 1, $generator);
        $this->assertSame(generation_manager::RUNNING, $record->status);
        $this->assertSame(1, (int) $record->repairs);
        // Back to the draft the generation started from; the revision stays in the history, abandoned.
        $this->assertSame(0, revision_manager::get_draft());
        $history = revision_manager::get_history();
        $this->assertTrue($history[$first]->abandoned);
        $this->assertContains('verification_failed', $this->event_types($record));

        $record = generation_manager::refresh($record->id, $generator);
        $this->assertSame(generation_manager::COMPLETED, $record->status);
        $this->assertNotSame($first, (int) $record->revisionid);
        $this->assertSame((int) $record->revisionid, revision_manager::get_draft());
        $this->assertFalse(revision_manager::get_history()[(int) $record->revisionid]->abandoned);
    }

    public function test_failure_without_repair_left_keeps_the_revision(): void {
        $generator = $this->chunks(['.a', '.b', '.c']);
        $record = $this->complete($generator);
        for ($i = 0; $i < 2; $i++) {
            $record = generation_manager::verify($record->id, [['target' => 'post/test', 'selector' => '.x']], [], 1, $generator);
            $record = generation_manager::refresh($record->id, $generator);
        }
        $this->assertSame(2, (int) $record->repairs);
        $last = (int) $record->revisionid;

        $record = generation_manager::verify($record->id, [['target' => 'post/test', 'selector' => '.x']], [], 1, $generator);
        $this->assertSame(generation_manager::COMPLETED, $record->status);
        $this->assertSame($last, revision_manager::get_draft());
        $messages = array_column(generation_manager::export($record)['events'], 'message');
        $this->assertContains(get_string('verification:kept', 'theme_brandable'), $messages);
    }

    public function test_failure_after_the_draft_moved_keeps_the_revision(): void {
        $generator = $this->chunks(['.ghost', '.card']);
        $record = $this->complete($generator);
        revision_manager::undo();

        $record = generation_manager::verify($record->id, [['target' => 'post/test', 'selector' => '.ghost']], [], 1, $generator);
        $this->assertSame(generation_manager::COMPLETED, $record->status);
        $this->assertSame(0, (int) $record->repairs);
        $this->assertFalse(revision_manager::get_history()[(int) $record->revisionid]->abandoned);
    }

    public function test_abandon_requires_the_revision_to_be_the_draft(): void {
        $id = revision_manager::create([change::from_array(['type' => 'scss', 'target' => 'post/a',
            'content' => '.a { color: red; }'])], 'p', 's');
        $this->assertFalse(revision_manager::abandon($id + 1, 0));
        $this->assertTrue(revision_manager::abandon($id, 0));
        $this->assertSame(0, revision_manager::get_draft());
        $this->assertFalse(revision_manager::can_redo());
    }

    public function test_selection_is_cleaned_and_stored(): void {
        $html = '<div data-x="jane@example.com">' . str_repeat('é', 5000) . '</div>';
        $record = generation_manager::submit('prompt', null, $this->chunks(['.card']), [
            'html' => $html,
            'ancestors' => [['tag' => 'nav', 'id' => 'x', 'classes' => ['navbar', ['bad']], 'data' => ['region' => "a\nb"]]],
            'template' => 'core/navbar',
            'templates' => ['core/navbar', 'theme_boost/navbar'],
            'label' => 'Mail me: jane@example.com',
            'unstableIds' => ['yui_3_17_2_1_1'],
            'locator' => 'nav.navbar',
            'extra' => 'dropped',
        ]);
        $stored = json_decode($record->selection, true);
        $this->assertLessThanOrEqual(selection::MAX_HTML, \core_text::strlen($stored['html']));
        $this->assertStringNotContainsString('jane@example.com', $stored['html']);
        $this->assertSame('Mail me: [email]', $stored['label']);
        $this->assertSame(['navbar'], $stored['ancestors'][0]['classes']);
        $this->assertSame('a b', $stored['ancestors'][0]['data']['region']);
        $this->assertSame('core/navbar', $stored['template']);
        $this->assertArrayNotHasKey('extra', $stored);
        // The locator stays in the plugin (verification), out of the contract.
        $this->assertSame('nav.navbar', $stored['locator']);
        $this->assertArrayNotHasKey('locator', selection::to_request($stored));
    }

    public function test_selection_without_markers_has_null_template(): void {
        $clean = selection::clean(['html' => '<b>x</b>', 'ancestors' => [['tag' => 'body', 'id' => '']], 'label' => 'x']);
        $this->assertNull($clean['template']);
        $this->assertSame([], $clean['templates']);
        $this->assertSame([], $clean['unstableIds']);
        $this->assertNull($clean['ancestors'][0]['id']);
    }

    public function test_mock_ghost_keyword(): void {
        $record = generation_manager::refresh(generation_manager::submit('ghost')->id);
        $this->assertSame(generation_manager::COMPLETED, $record->status);
        $targets = array_column(\theme_brandable\external\editor_state::export()['revisions'][0]['changes'], 'target');
        $this->assertContains('post/ghost', $targets);
    }
}
