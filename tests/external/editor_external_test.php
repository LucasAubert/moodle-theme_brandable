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

namespace theme_brandable\external;

use core_external\external_api;
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\revision_manager;

/**
 * Tests for the editor external functions.
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\external\editor_state
 * @covers     \theme_brandable\external\get_revisions
 * @covers     \theme_brandable\external\undo
 * @covers     \theme_brandable\external\redo
 * @covers     \theme_brandable\external\restore
 * @covers     \theme_brandable\external\publish
 * @covers     \theme_brandable\external\discard
 * @covers     \theme_brandable\external\revert_version
 * @covers     \theme_brandable\external\propose_version_note
 */
#[\PHPUnit\Framework\Attributes\CoversClass(editor_state::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(get_revisions::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(undo::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(redo::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(restore::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(publish::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(discard::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(revert_version::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(propose_version_note::class)]
final class editor_external_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Creates a revision.
     *
     * @param string $prompt Prompt.
     * @return int Revision id.
     */
    private function revision(string $prompt): int {
        return revision_manager::create([change::from_array([
            'type' => 'template', 'target' => 'theme_brandable/ext_' . md5($prompt), 'content' => $prompt,
        ])], $prompt, 'Summary of ' . $prompt);
    }

    /**
     * Calls an external function and cleans its result.
     *
     * @param string $class Class name in theme_brandable\external.
     * @param array $args Arguments.
     * @return array
     */
    private function call(string $class, array $args = []): array {
        $class = __NAMESPACE__ . '\\' . $class;
        return external_api::clean_returnvalue($class::execute_returns(), $class::execute(...$args));
    }

    public function test_state_and_actions(): void {
        $r1 = $this->revision('first');
        $r2 = $this->revision('second');

        $state = $this->call('get_revisions');
        $this->assertSame($r2, $state['draft']);
        $this->assertSame(0, $state['published']);
        $this->assertTrue($state['canundo']);
        $this->assertTrue($state['unpublished']);
        $this->assertSame([$r2, $r1], array_column($state['revisions'], 'id'));
        $this->assertSame('second', $state['revisions'][0]['prompt']);
        $this->assertSame(fullname(get_admin()), $state['revisions'][0]['author']);
        $this->assertTrue($state['revisions'][0]['isdraft']);

        $this->assertSame($r1, $this->call('undo')['draft']);
        $this->assertSame($r2, $this->call('redo')['draft']);
        $this->assertSame($r1, $this->call('restore', [$r1])['draft']);

        $this->assertSame(['note' => 'Summary of first', 'maxlength' => 1000], $this->call('propose_version_note'));
        $state = $this->call('publish', ['Première version']);
        $this->assertSame($state['published'], $state['draft']);
        $this->assertFalse($state['unpublished']);
        $this->assertFalse($state['canundo']);
        $this->assertSame([], $state['revisions']);
        $version = ['number' => 1, 'note' => 'Première version', 'author' => fullname(get_admin()),
            'timecreated' => $state['versions'][0]['timecreated'], 'iscurrent' => true, 'jsdownloadurl' => '',
            // Not connected: no cloud backup.
            'cloud' => ['status' => '', 'number' => 0, 'synced' => false, 'pending' => false, 'stopped' => false,
                'errormessage' => '', 'contacturl' => '']];
        $this->assertSame([$version], $state['versions']);
        $this->assertFalse($state['canrevert']);

        $this->revision('third');
        $this->assertSame($state['published'], $this->call('discard')['draft']);

        $this->revision('fourth');
        $second = $this->call('publish', ['Deuxième']);
        $this->assertTrue($second['canrevert']);
        $this->assertSame([2, 1], array_column($second['versions'], 'number'));
        $state = $this->call('revert_version');
        $this->assertSame($state['draft'], $state['published']);
        $this->assertSame([1, 2], array_column($state['versions'], 'number'));
    }

    public function test_capability_required(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        get_revisions::execute();
    }

    public function test_editor_calls_turn_the_preview_back_on(): void {
        global $SESSION;

        $r1 = $this->revision('first');
        \theme_brandable\local\preview::enable();
        // Preview left (editor close button, banner), then the editor is used again (back button).
        \theme_brandable\local\preview::disable();
        \theme_brandable\local\preview::reset();
        $this->assertFalse(\theme_brandable\local\preview::is_active());

        $this->call('get_revisions');
        $this->assertTrue(\theme_brandable\local\preview::is_active());
        $this->assertSame($r1, \theme_brandable\local\preview::get_revision());

        // A preview targeting a given revision is kept.
        $SESSION->theme_brandable_preview = (object) ['revision' => 0];
        $this->call('undo');
        $this->assertSame(0, \theme_brandable\local\preview::get_revision());
        \theme_brandable\local\preview::disable();
    }
}
