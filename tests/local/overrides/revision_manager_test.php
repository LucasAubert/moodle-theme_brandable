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

/**
 * Tests for the revision chain resolution and the draft / published / redo pointers.
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\overrides\revision_manager
 * @covers     \theme_brandable\local\overrides\resolver
 * @covers     \theme_brandable\local\overrides\context
 * @covers     \theme_brandable\local\overrides\migration
 * @covers     \theme_brandable\local\overrides\version_note
 */
#[\PHPUnit\Framework\Attributes\CoversClass(revision_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(resolver::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(context::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(migration::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(version_note::class)]
final class revision_manager_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Creates a revision overriding new templates (fast: no SCSS compilation).
     *
     * @param array $templates Template name => content (null to delete).
     * @return int Revision id.
     */
    private function revision(array $templates): int {
        $changes = [];
        foreach ($templates as $name => $content) {
            $changes[] = change::from_array([
                'type' => change::TYPE_TEMPLATE,
                'target' => $name,
                'content' => $content,
                'deleted' => $content === null,
            ]);
        }
        return revision_manager::create($changes, 'prompt ' . implode(',', array_keys($templates)), 'summary');
    }

    /**
     * Returns the template overrides of a revision as name => content.
     *
     * @param int $revisionid Revision id.
     * @return array
     */
    private function templates(int $revisionid): array {
        return array_map(fn($entry) => $entry['content'], resolver::get_map($revisionid)[change::TYPE_TEMPLATE]);
    }

    public function test_initial_state(): void {
        $this->assertSame(0, revision_manager::get_draft());
        $this->assertSame(0, revision_manager::get_published());
        $this->assertFalse(revision_manager::can_undo());
        $this->assertFalse(revision_manager::can_redo());
        $this->assertSame(resolver::empty_map(), resolver::get_map(0));
    }

    public function test_chain_resolution(): void {
        $r1 = $this->revision(['theme_brandable/brandable_a' => 'A1', 'theme_brandable/brandable_b' => 'B1']);
        $r2 = $this->revision(['theme_brandable/brandable_a' => 'A2', 'theme_brandable/brandable_c' => 'C2']);
        $r3 = $this->revision(['theme_brandable/brandable_a' => null, 'theme_brandable/brandable_d' => 'D3']);
        $r4 = $this->revision(['theme_brandable/brandable_a' => 'A4']);

        $this->assertSame(['theme_brandable/brandable_a' => 'A1', 'theme_brandable/brandable_b' => 'B1'], $this->templates($r1));
        // Replacing keeps the position of the target.
        $this->assertSame(
            ['theme_brandable/brandable_a' => 'A2', 'theme_brandable/brandable_b' => 'B1', 'theme_brandable/brandable_c' => 'C2'],
            $this->templates($r2)
        );
        $this->assertSame(
            ['theme_brandable/brandable_b' => 'B1', 'theme_brandable/brandable_c' => 'C2', 'theme_brandable/brandable_d' => 'D3'],
            $this->templates($r3)
        );
        // Re-created after a deletion: goes to the end.
        $this->assertSame([
            'theme_brandable/brandable_b' => 'B1',
            'theme_brandable/brandable_c' => 'C2',
            'theme_brandable/brandable_d' => 'D3',
            'theme_brandable/brandable_a' => 'A4',
        ], $this->templates($r4));

        // Same result when resolved from scratch and from a partially cached chain.
        $expected = $this->templates($r4);
        \cache::make('theme_brandable', 'resolved')->purge();
        $this->templates($r2);
        $this->assertSame($expected, $this->templates($r4));
        $this->assertSame($r4, resolver::get_map($r4)[change::TYPE_TEMPLATE]['theme_brandable/brandable_a']['revisionid']);
    }

    public function test_create_moves_draft_only(): void {
        global $DB;

        $r1 = $this->revision(['theme_brandable/brandable_a' => 'A1']);
        $this->assertSame($r1, revision_manager::get_draft());
        $this->assertSame(0, revision_manager::get_published());

        $r2 = $this->revision(['theme_brandable/brandable_a' => 'A2']);
        $this->assertEquals($r1, $DB->get_field('theme_brandable_revision', 'parentrevisionid', ['id' => $r2]));
    }

    public function test_undo_redo(): void {
        $r1 = $this->revision(['theme_brandable/brandable_a' => 'A1']);
        $r2 = $this->revision(['theme_brandable/brandable_a' => 'A2']);
        $r3 = $this->revision(['theme_brandable/brandable_a' => 'A3']);

        $this->assertSame($r2, revision_manager::undo());
        $this->assertSame($r1, revision_manager::undo());
        $this->assertSame($r3, revision_manager::get_redo_head());
        $this->assertTrue(revision_manager::can_redo());

        $this->assertSame($r2, revision_manager::redo());
        $this->assertSame($r3, revision_manager::redo());
        $this->assertFalse(revision_manager::can_redo());
        $this->assertSame(0, revision_manager::get_redo_head());

        // Undo down to the empty root, then redo from it.
        revision_manager::undo();
        revision_manager::undo();
        $this->assertSame(0, revision_manager::undo());
        $this->assertFalse(revision_manager::can_undo());
        $this->assertSame($r1, revision_manager::redo());

        $this->expectException(\moodle_exception::class);
        revision_manager::restore($r3);
        revision_manager::redo();
    }

    public function test_new_revision_after_undo_drops_redo_branch(): void {
        $r1 = $this->revision(['theme_brandable/brandable_a' => 'A1']);
        $this->revision(['theme_brandable/brandable_a' => 'A2']);
        revision_manager::undo();
        $this->assertTrue(revision_manager::can_redo());

        $r3 = $this->revision(['theme_brandable/brandable_b' => 'B3']);
        $this->assertFalse(revision_manager::can_redo());
        $this->assertSame(['theme_brandable/brandable_a' => 'A1', 'theme_brandable/brandable_b' => 'B3'], $this->templates($r3));
        // The abandoned revision stays in the history.
        $this->assertCount(3, revision_manager::get_history());
        $this->assertEquals($r1, revision_manager::get_history()[$r3]->parentrevisionid);
    }

    public function test_restore(): void {
        $r1 = $this->revision(['theme_brandable/brandable_a' => 'A1']);
        $r2 = $this->revision(['theme_brandable/brandable_a' => 'A2']);
        $r3 = $this->revision(['theme_brandable/brandable_a' => 'A3']);

        // Restoring an ancestor behaves like several undos.
        revision_manager::restore($r1);
        $this->assertSame($r1, revision_manager::get_draft());
        $this->assertSame($r3, revision_manager::get_redo_head());
        $this->assertSame($r2, revision_manager::redo());

        // Restoring a revision of an abandoned branch drops the redo branch.
        revision_manager::undo();
        $r4 = $this->revision(['theme_brandable/brandable_b' => 'B4']);
        revision_manager::restore($r3);
        $this->assertSame($r3, revision_manager::get_draft());
        $this->assertFalse(revision_manager::can_redo());
        revision_manager::restore($r4);
        $this->assertSame($r4, revision_manager::get_draft());

        revision_manager::restore(0);
        $this->assertSame(0, revision_manager::get_draft());

        $this->expectException(\moodle_exception::class);
        revision_manager::restore($r4 + 100);
    }

    public function test_publish_creates_a_version_and_a_new_session(): void {
        global $DB;

        $session = revision_manager::get_session_id();
        $this->revision(['theme_brandable/brandable_a' => 'A1']);
        $r2 = $this->revision(['theme_brandable/brandable_b' => 'B2']);
        $this->assertSame([['prompt' => 'prompt theme_brandable/brandable_a', 'summary' => 'summary'],
            ['prompt' => 'prompt theme_brandable/brandable_b', 'summary' => 'summary']], revision_manager::get_session_entries());
        $this->assertSame("summary\nsummary", version_note::propose());

        $v1 = revision_manager::publish('Note 1');
        $version = $DB->get_record('theme_brandable_version', ['id' => $v1]);
        $this->assertSame(1, (int) $version->number);
        $this->assertSame('Note 1', $version->note);
        $this->assertSame(2, (int) $version->prompts);
        // A complete revision: same design, no parent; the session revisions are gone.
        $published = revision_manager::get_published();
        $this->assertSame(0, (int) $DB->get_field('theme_brandable_revision', 'parentrevisionid', ['id' => $published]));
        $this->assertSame(
            ['theme_brandable/brandable_a' => 'A1', 'theme_brandable/brandable_b' => 'B2'],
            $this->templates($published)
        );
        $this->assertFalse($DB->record_exists('theme_brandable_revision', ['id' => $r2]));
        $this->assertSame(1, $DB->count_records('theme_brandable_revision'));
        // New session: empty, starting from the version, nothing to undo.
        $this->assertNotSame($session, revision_manager::get_session_id());
        $this->assertSame($published, revision_manager::get_session_base());
        $this->assertSame($published, revision_manager::get_draft());
        $this->assertFalse(revision_manager::can_undo());
        $this->assertSame([], revision_manager::get_history());
        $this->assertSame('', version_note::propose());

        // Undo stops at the version.
        $r3 = $this->revision(['theme_brandable/brandable_a' => 'A3']);
        $this->assertSame([$r3], array_keys(revision_manager::get_history()));
        $this->assertSame($published, revision_manager::undo());
        $this->assertFalse(revision_manager::can_undo());
        revision_manager::redo();

        // Two versions kept: the published one and the previous one.
        revision_manager::publish('Note 2');
        $this->revision(['theme_brandable/brandable_a' => 'A4']);
        revision_manager::publish('Note 3');
        $numbers = $DB->get_fieldset_select('theme_brandable_version', 'number', '1=1 ORDER BY number');
        $this->assertSame([2, 3], array_map('intval', $numbers));
        $this->assertSame(2, $DB->count_records('theme_brandable_revision'));
        $this->assertSame('Note 3', revision_manager::get_current_version()->note);
        $this->assertSame('Note 2', revision_manager::get_previous_version()->note);

        // Nothing to publish.
        $this->expectException(\moodle_exception::class);
        revision_manager::publish('Again');
    }

    public function test_version_note_written_by_brandable(): void {
        \theme_brandable\local\account\connection::store_key('site-key-1');
        $this->revision(['theme_brandable/brandable_a' => 'A1']);
        \curl::mock_response(json_encode(['note' => "Barre bleue.\nPolice plus grande."]));
        $this->assertSame("Barre bleue.\nPolice plus grande.", version_note::propose());

        // Brandable unavailable: the summaries of the session.
        \curl::mock_response(json_encode(['error' => ['code' => 'service_unavailable', 'message' => 'Down']]));
        $this->assertSame('summary', version_note::propose());

        // No prompt (draft migrated, scope changed): Brandable is not asked.
        revision_manager::discard();
        revision_manager::create([change::from_array(['type' => 'template', 'target' => 'theme_brandable/brandable_b',
            'content' => 'B'])], '', 'Scope changed');
        \curl::mock_response(json_encode(['note' => 'Never asked']));
        $this->assertSame('Scope changed', version_note::propose());
    }

    public function test_revert_to_the_previous_version_and_discard(): void {
        global $DB;

        $this->assertNull(revision_manager::get_previous_version());
        $this->revision(['theme_brandable/brandable_a' => 'A1']);
        revision_manager::publish('One');
        $this->revision(['theme_brandable/brandable_a' => 'A2']);
        revision_manager::publish('Two');

        // A session in progress is deleted by going back.
        $session = revision_manager::get_session_id();
        $this->revision(['theme_brandable/brandable_b' => 'B']);
        $DB->insert_record('theme_brandable_generation', (object) ['sessionid' => $session, 'generator' => 'mock',
            'status' => 'completed', 'timecreated' => time(), 'timemodified' => time()]);
        revision_manager::revert_to_previous();
        $this->assertSame('One', revision_manager::get_current_version()->note);
        $this->assertSame(['theme_brandable/brandable_a' => 'A1'], $this->templates(revision_manager::get_published()));
        $this->assertSame(revision_manager::get_published(), revision_manager::get_draft());
        $this->assertSame([], revision_manager::get_history());
        $this->assertSame(0, $DB->count_records('theme_brandable_generation', ['sessionid' => $session]));
        // The other way back.
        $this->assertSame('Two', revision_manager::get_previous_version()->note);
        revision_manager::revert_to_previous();
        $this->assertSame('Two', revision_manager::get_current_version()->note);

        // Discard: the session is deleted, the draft is the published version.
        $this->revision(['theme_brandable/brandable_b' => 'B']);
        revision_manager::discard();
        $this->assertSame(revision_manager::get_published(), revision_manager::get_draft());
        $this->assertSame(['theme_brandable/brandable_a' => 'A2'], $this->templates(revision_manager::get_draft()));
        $this->assertSame(2, $DB->count_records('theme_brandable_revision'));

        // Not while a generation of the session is running.
        $DB->insert_record('theme_brandable_generation', (object) ['sessionid' => revision_manager::get_session_id(),
            'generator' => 'mock', 'status' => 'running', 'timecreated' => time(), 'timemodified' => time()]);
        $this->expectException(\moodle_exception::class);
        revision_manager::revert_to_previous();
    }

    public function test_snapshot_keeps_the_order_of_the_chunks(): void {
        $chunk = fn(string $target, ?string $content) => change::from_array(['type' => change::TYPE_SCSS, 'target' => $target,
            'content' => $content, 'deleted' => $content === null]);
        $r1 = revision_manager::create(
            [$chunk('post/a', '.a { color: red; }'), $chunk('post/b', '.b { color: red; }')],
            'p',
            's'
        );
        // Deleted then created again: goes to the end.
        revision_manager::create([$chunk('post/a', null)], 'p', 's');
        $r3 = revision_manager::create(
            [$chunk('post/a', '.a { color: red; }'), $chunk('post/c', '.c { color: red; }')],
            'p',
            's'
        );
        $order = fn(int $id) => array_keys(resolver::get_map($id)[change::TYPE_SCSS]);
        $this->assertSame(['post/b', 'post/a', 'post/c'], $order($r3));

        // Complete, or on top of a base whose common chunks are in another order.
        $map = resolver::get_map($r3);
        $complete = revision_manager::snapshot($map, null, '', 'v', null);
        $this->assertTrue(resolver::same_content($map, resolver::get_map($complete)));
        $ontop = revision_manager::snapshot($map, resolver::get_map($r1), '', 'd', 'x', null, $r1);
        $this->assertTrue(resolver::same_content($map, resolver::get_map($ontop)));
        $this->assertSame(['post/b', 'post/a', 'post/c'], $order($ontop));
    }

    public function test_migration_to_sessions(): void {
        global $DB;

        // State before sessions: a history with a published revision and a draft further on.
        $this->revision(['theme_brandable/brandable_a' => 'A1']);
        $published = $this->revision(['theme_brandable/brandable_b' => 'B2']);
        set_config(revision_manager::CONFIG_PUBLISHED, $published, 'theme_brandable');
        $this->revision(['theme_brandable/brandable_a' => null]);
        $draft = $this->revision(['theme_brandable/brandable_c' => 'C4']);
        $DB->set_field('theme_brandable_revision', 'sessionid', null);
        $DB->insert_record('theme_brandable_generation', (object) ['generator' => 'mock', 'status' => 'completed',
            'revisionid' => $draft, 'timecreated' => time(), 'timemodified' => time()]);
        $publishedmap = resolver::get_map($published);
        $draftmap = resolver::get_map($draft);

        migration::to_sessions();
        // Version 1 renders like the published revision, the session in progress like the draft.
        $version = revision_manager::get_current_version();
        $this->assertSame(1, (int) $version->number);
        $this->assertTrue(resolver::same_content($publishedmap, resolver::get_map(revision_manager::get_published())));
        $this->assertTrue(resolver::same_content($draftmap, resolver::get_map(revision_manager::get_draft())));
        $this->assertSame(revision_manager::get_published(), revision_manager::get_session_base());
        $this->assertCount(1, revision_manager::get_history());
        $this->assertSame(2, $DB->count_records('theme_brandable_revision'));
        $this->assertSame(0, $DB->count_records('theme_brandable_generation'));
        $this->assertSame(revision_manager::get_published(), revision_manager::undo());
    }

    public function test_migration_without_draft_nor_publication(): void {
        migration::to_sessions();
        $this->assertSame(0, revision_manager::get_published());
        $this->assertSame(0, revision_manager::get_draft());
        $this->assertNull(revision_manager::get_current_version());
    }

    public function test_migration_of_a_draft_identical_to_the_publication(): void {
        // No session revision.
        $r1 = $this->revision(['theme_brandable/brandable_a' => 'A1']);
        set_config(revision_manager::CONFIG_PUBLISHED, $r1, 'theme_brandable');
        migration::to_sessions();
        $this->assertSame(revision_manager::get_published(), revision_manager::get_draft());
        $this->assertSame([], revision_manager::get_history());
    }

    public function test_publish_bumps_css_sub_revision_only_when_scss_changes(): void {
        $this->revision(['theme_brandable/brandable_a' => 'A1']);
        $before = theme_get_sub_revision_for_theme('brandable');
        revision_manager::publish('n');
        $this->assertSame($before, theme_get_sub_revision_for_theme('brandable'));

        revision_manager::create([change::from_array([
            'type' => change::TYPE_SCSS, 'target' => 'post/test', 'content' => '.brandable-test { color: red; }',
        ])], 'scss', 'scss');
        revision_manager::publish('n');
        $this->assertGreaterThan($before, theme_get_sub_revision_for_theme('brandable'));
    }

    public function test_expected_draft(): void {
        $r1 = $this->revision(['theme_brandable/brandable_a' => 'A1']);
        $change = change::from_array([
            'type' => change::TYPE_TEMPLATE, 'target' => 'theme_brandable/brandable_b', 'content' => 'B',
        ]);
        $this->assertGreaterThan($r1, revision_manager::create([$change], 'p', 's', null, $r1));

        $this->expectException(\moodle_exception::class);
        revision_manager::create([$change], 'p', 's', null, $r1);
    }

    public function test_invalid_revision_leaves_draft_unchanged(): void {
        global $DB;

        $r1 = $this->revision(['theme_brandable/brandable_a' => 'A1']);
        try {
            $this->revision(['theme_brandable/brandable_a' => '{{#unclosed}}']);
            $this->fail('Validation should have failed');
        } catch (\theme_brandable\local\validation\validation_exception $e) {
            $this->assertSame('theme_brandable/brandable_a', $e->errors[0]['target']);
        }
        $this->assertSame($r1, revision_manager::get_draft());
        $this->assertSame(1, $DB->count_records('theme_brandable_revision'));
    }

    public function test_context_follows_published_or_forced_revision(): void {
        $this->revision(['theme_brandable/brandable_a' => 'A1']);
        revision_manager::publish('n');
        $r1 = revision_manager::get_published();
        $r2 = $this->revision(['theme_brandable/brandable_a' => 'A2']);

        $this->assertSame('A1', context::get_template('theme_brandable/brandable_a'));
        $this->assertSame('A2', context::with_revision($r2, fn() => context::get_template('theme_brandable/brandable_a')));
        $this->assertNull(context::with_revision(0, fn() => context::get_template('theme_brandable/brandable_a')));
        $this->assertSame('A1', context::get_template('theme_brandable/brandable_a'));
        $this->assertSame($r1, context::get_active_revision());
    }
}
