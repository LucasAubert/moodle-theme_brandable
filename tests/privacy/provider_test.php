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

namespace theme_brandable\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\revision_manager;

/**
 * Tests for the privacy provider (revisions are anonymised, never deleted).
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\privacy\provider
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class provider_test extends provider_testcase {
    /** @var \stdClass First author. */
    private \stdClass $user1;

    /** @var \stdClass Second author. */
    private \stdClass $user2;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->user1 = $this->getDataGenerator()->create_user();
        $this->user2 = $this->getDataGenerator()->create_user();
        $this->create_revision($this->user1, 'prompt one');
        $this->create_revision($this->user2, 'prompt two');
        $this->create_revision($this->user1, 'prompt three');
    }

    /**
     * Creates a revision authored by a user.
     *
     * @param \stdClass $user Author.
     * @param string $prompt Prompt.
     */
    private function create_revision(\stdClass $user, string $prompt): void {
        revision_manager::create([change::from_array([
            'type' => 'template', 'target' => 'theme_brandable/brandable_' . md5($prompt), 'content' => $prompt,
        ])], $prompt, 'summary', (int) $user->id);
    }

    public function test_contexts_and_users(): void {
        $system = \context_system::instance();
        $contextids = provider::get_contexts_for_userid($this->user1->id)->get_contextids();
        $this->assertSame([$system->id], array_map('intval', $contextids));
        $nobody = $this->getDataGenerator()->create_user();
        $this->assertCount(0, provider::get_contexts_for_userid($nobody->id));

        $userlist = new userlist($system, 'theme_brandable');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$this->user1->id, $this->user2->id], $userlist->get_userids());
    }

    public function test_js_consents_and_reviews(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('theme_brandable_jsconsent', ['userid' => $user->id, 'version' => '2026-11-02', 'enabled' => 1,
            'timecreated' => time()]);
        $DB->insert_record('theme_brandable_jsreview', ['userid' => $user->id, 'target' => 'js/welcome',
            'contenthash' => str_repeat('a', 64), 'timecreated' => time()]);
        $system = \context_system::instance();
        $this->assertCount(1, provider::get_contexts_for_userid($user->id));
        $userlist = new userlist($system, 'theme_brandable');
        provider::get_users_in_context($userlist);
        $this->assertContains((int) $user->id, array_map('intval', $userlist->get_userids()));

        $this->export_context_data_for_user($user->id, $system, 'theme_brandable');
        $path = fn(string $id) => [get_string('pluginname', 'theme_brandable'), get_string($id, 'theme_brandable')];
        $this->assertSame('2026-11-02', writer::with_context($system)->get_data($path('privacy:path:jsconsents'))
            ->consents[0]->version);
        $this->assertSame('js/welcome', writer::with_context($system)->get_data($path('privacy:path:jsreviews'))
            ->reviews[0]->target);

        // History of the site kept, without its author.
        provider::delete_data_for_user(new approved_contextlist($user, 'theme_brandable', [$system->id]));
        $this->assertSame(1, $DB->count_records('theme_brandable_jsconsent', ['userid' => 0]));
        $this->assertSame(1, $DB->count_records('theme_brandable_jsreview', ['userid' => 0]));
    }

    public function test_versions_waiting_for_the_cloud(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user(['firstname' => 'Jeanne', 'lastname' => 'Martin']);
        $payload = ['clientVersionId' => 'u1', 'note' => 'Barre bleue.', 'author' => ['hash' => str_repeat('b', 64),
            'name' => 'Jeanne Martin'], 'changes' => []];
        $DB->insert_record('theme_brandable_cloudqueue', ['versionid' => null, 'clientversionid' => 'u1',
            'userid' => $user->id, 'payload' => json_encode($payload), 'files' => '[]', 'attempts' => 0, 'nextattempt' => 0,
            'timecreated' => time()]);
        $system = \context_system::instance();
        $this->assertCount(1, provider::get_contexts_for_userid($user->id));
        $userlist = new userlist($system, 'theme_brandable');
        provider::get_users_in_context($userlist);
        $this->assertContains((int) $user->id, array_map('intval', $userlist->get_userids()));

        $this->export_context_data_for_user($user->id, $system, 'theme_brandable');
        $data = writer::with_context($system)->get_data([get_string('pluginname', 'theme_brandable'),
            get_string('privacy:path:cloudqueue', 'theme_brandable')]);
        $this->assertSame(['Barre bleue.', 'Jeanne Martin'], [$data->versions[0]->note, $data->versions[0]->author]);

        // Still sent (the history of the site), without its author.
        provider::delete_data_for_user(new approved_contextlist($user, 'theme_brandable', [$system->id]));
        $row = $DB->get_record('theme_brandable_cloudqueue', ['clientversionid' => 'u1']);
        $this->assertSame(0, (int) $row->userid);
        $anonymous = ['hash' => \theme_brandable\local\cloud\cloud::author_hash(0)];
        $this->assertSame($anonymous, json_decode($row->payload, true)['author']);
        $this->assertStringNotContainsString('Jeanne', $row->payload);
    }

    public function test_export(): void {
        $system = \context_system::instance();
        $this->export_context_data_for_user($this->user1->id, $system, 'theme_brandable');
        $data = writer::with_context($system)->get_data([get_string('pluginname', 'theme_brandable'),
            get_string('privacy:path:revisions', 'theme_brandable')]);
        $this->assertSame(['prompt one', 'prompt three'], array_column($data->revisions, 'prompt'));
    }

    public function test_delete_for_user_anonymises(): void {
        global $DB;

        $system = \context_system::instance();
        provider::delete_data_for_user(new approved_contextlist($this->user1, 'theme_brandable', [$system->id]));

        $this->assertSame(0, $DB->count_records('theme_brandable_revision', ['userid' => $this->user1->id]));
        $this->assertSame(3, $DB->count_records('theme_brandable_revision'));
        $this->assertSame(2, $DB->count_records_select('theme_brandable_revision', 'userid = 0 AND ' .
            $DB->sql_compare_text('prompt') . ' = ?', ['']));
        $this->assertSame(1, $DB->count_records('theme_brandable_revision', ['userid' => $this->user2->id]));
    }

    public function test_delete_for_users_and_context(): void {
        global $DB;

        $system = \context_system::instance();
        provider::delete_data_for_users(new approved_userlist($system, 'theme_brandable', [$this->user2->id]));
        $this->assertSame(0, $DB->count_records('theme_brandable_revision', ['userid' => $this->user2->id]));
        $this->assertSame(2, $DB->count_records('theme_brandable_revision', ['userid' => $this->user1->id]));

        provider::delete_data_for_all_users_in_context($system);
        $this->assertSame(3, $DB->count_records('theme_brandable_revision', ['userid' => 0]));
        // The design history is kept.
        $this->assertSame(3, $DB->count_records('theme_brandable_change'));
    }
}
