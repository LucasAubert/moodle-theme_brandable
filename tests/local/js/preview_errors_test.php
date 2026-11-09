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

namespace theme_brandable\local\js;

use theme_brandable\external\editor_state;
use theme_brandable\external\report_verification;
use theme_brandable\local\generator\generation_context;
use theme_brandable\local\generator\generation_manager;
use theme_brandable\local\generator\generation_result;
use theme_brandable\local\generator\sync_generator;
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\resolver;

/**
 * Tests for the errors of the JavaScript modules in the preview (spec B11, contract 12.8) and their display in the
 * thread (B12): sent to the repair once per module and per generation, only for the modules of the revision.
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\generator\generation_manager
 * @covers     \theme_brandable\external\report_verification
 * @covers     \theme_brandable\external\editor_state
 */
#[\PHPUnit\Framework\Attributes\CoversClass(generation_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(report_verification::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(editor_state::class)]
final class preview_errors_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        // Demo generator, JavaScript on with its text.
        $CFG->theme_brandable_devmode = true;
        set_config('brandable_generator', 'mock', 'theme_brandable');
        set_config(modules::ENABLED_CONFIG, 1, 'theme_brandable');
        set_config(modules::CONSENT_CONFIG, consent::MOCK_VERSION, 'theme_brandable');
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

    public function test_module_failing_in_the_preview_is_repaired(): void {
        $record = generation_manager::refresh(generation_manager::submit('Fenêtre de bienvenue et un module cassé')->id);
        $this->assertSame(generation_manager::COMPLETED, $record->status, (string) ($record->errormessage ?? ''));
        $map = resolver::get_map((int) $record->revisionid);
        $this->assertArrayHasKey('js/broken-module', $map[change::TYPE_JS]);

        // The thread shows the APIs and the findings of each module.
        $state = \core_external\external_api::clean_returnvalue(editor_state::structure(), editor_state::export());
        $changes = array_column($state['revisions'][0]['changes'], null, 'target');
        $this->assertSame(['b.modal', 'b.once'], $changes['js/welcome-modal']['apis']);
        $this->assertSame([], $changes['js/welcome-modal']['findings']);
        $this->assertSame(2, $changes['js/broken-module']['findings'][0]['line']);
        $this->assertFalse($changes['js/broken-module']['jsblocked']);
        $this->assertSame([], $changes['js/welcome-modal']['selectors']);

        // Errors of other targets are ignored; the message is one line of 500 characters at most.
        $result = report_verification::execute((int) $record->id, [], [], 0, [
            ['target' => 'js/broken-module', 'message' => "Demo:\n<b>fails</b> " . str_repeat('x', 600)],
            ['target' => 'js/broken-module', 'message' => 'second error of the same module'],
            ['target' => 'js/unknown', 'message' => 'not of the revision'],
            ['target' => 'post/test', 'message' => 'not a module'],
        ]);
        $this->assertSame(generation_manager::RUNNING, $result['status']);
        $this->assertSame(1, $result['repairs']);
        $event = array_values(array_filter($result['events'], fn($event) => $event['type'] === generation_manager::EVENT_JSERROR));
        $this->assertCount(1, $event);
        $lines = explode("\n", $event[0]['message']);
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('js/broken-module : Demo: fails xxx', $lines[1]);
        $this->assertSame(500, \core_text::strlen(substr($lines[1], strlen('js/broken-module : '))));

        // The repair drops the module (demo generator): the welcome window stays.
        $record = generation_manager::refresh((int) $record->id);
        $this->assertSame(generation_manager::COMPLETED, $record->status);
        $map = resolver::get_map((int) $record->revisionid);
        $this->assertArrayNotHasKey('js/broken-module', $map[change::TYPE_JS]);
        $this->assertArrayHasKey('js/welcome-modal', $map[change::TYPE_JS]);

        // Verified: nothing checked but the modules, which ran.
        $record = generation_manager::verify((int) $record->id, [], [], 0);
        $this->assertContains('verified', $this->event_types($record));
        $this->assertStringContainsString(
            get_string('verification:okjs', 'theme_brandable', (object) ['selectors' => 0, 'modules' => 1]),
            implode("\n", array_column(generation_manager::export($record)['events'], 'message'))
        );
    }

    public function test_personal_data_is_masked_before_any_sending(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Jeanne', 'lastname' => 'Dupont-Martin',
            'email' => 'jeanne@example.org']);
        $user = $DB->get_record('user', ['id' => $user->id]);
        $this->assertSame(
            'Cannot read [masqué] of [masqué] ([masqué]), order [masqué], code 123, [masqué] then [masqué] in Dupontel',
            modules::clean_error("Cannot read jeanne@example.org of Jeanne Dupont-Martin\n(<i>a.b+c@sub.example.com</i>), "
                . 'order 20261104, code 123, jeanne then DUPONT-MARTIN in Dupontel', $user)
        );
        // Whole words of 3 letters at least: a short first name, or a name inside a word, is kept.
        $short = $this->getDataGenerator()->create_user(['firstname' => 'Al', 'lastname' => 'Bert']);
        $short = $DB->get_record('user', ['id' => $short->id]);
        $this->assertSame('Al said: [masqué] [masqué], Alberta, Albert, Bertrand', modules::clean_error(
            'Al said: Al Bert bert, Alberta, Albert, Bertrand',
            $short
        ));

        // Masked before the cut: no end of an email left at the limit.
        $long = modules::clean_error(str_repeat('a', 490) . ' jeanne@example.org', $user);
        $this->assertSame(str_repeat('a', 490) . ' [masqué]', $long);
        $this->assertSame(500, \core_text::strlen(modules::clean_error(str_repeat('é', 600), $user)));
        // A visitor has no name to mask.
        $this->assertSame('Guest user [masqué]', modules::clean_error('Guest user 2026', guest_user()));

        // What the repair receives, for the current user (the one viewing the preview).
        $this->setUser($user);
        $role = $this->getDataGenerator()->create_role();
        assign_capability('theme/brandable:edit', CAP_ALLOW, $role, \context_system::instance());
        role_assign($role, $user->id, \context_system::instance());
        $record = generation_manager::refresh(generation_manager::submit('Un module cassé')->id);
        $result = report_verification::execute((int) $record->id, [], [], 0, [
            ['target' => 'js/broken-module', 'message' => 'Hello Jeanne Dupont-Martin <jeanne@example.org>, id 98765'],
        ]);
        $event = array_values(array_filter($result['events'], fn($event) => $event['type'] === generation_manager::EVENT_JSERROR));
        // An address between angle brackets goes with the tags.
        $this->assertSame('js/broken-module : Hello [masqué] , id [masqué]', explode("\n", $event[0]['message'])[1]);
        $this->assertStringNotContainsString('Jeanne', json_encode($result['events']));
    }

    public function test_a_module_is_repaired_once_per_generation(): void {
        $module = change::from_array(['type' => 'js', 'target' => 'js/stubborn', 'content' => "export default function () {}\n",
            'compiled' => 'function(){"use strict";throw new Error("x")}', 'report' => ['analyzer' => 'test', 'apis' => [],
            'findings' => []], 'signature' => 'v1.testSignatureNotFromBrandable00']);
        // The same module again after the repair; the errors sent are kept.
        $generator = new class ([$module]) extends sync_generator {
            /** @var array Errors sent to the repairs. */
            public static array $errors = [];

            /**
             * Constructor.
             *
             * @param change[] $changes Changes of every result.
             */
            public function __construct(
                /** @var change[] Changes of every result. */
                private array $changes
            ) {
            }

            #[\Override]
            public function generate(string $prompt, generation_context $context): generation_result {
                return new generation_result('Stubborn', $this->changes);
            }

            #[\Override]
            protected function repair_result(generation_result $result, array $errors): ?generation_result {
                self::$errors[] = $errors;
                return new generation_result('Stubborn again', $this->changes);
            }
        };
        $record = generation_manager::submit('prompt', null, $generator);
        $record = generation_manager::refresh($record->id, $generator);
        $error = [['target' => 'js/stubborn', 'message' => 'x']];
        $record = generation_manager::verify((int) $record->id, [], [], 0, $generator, $error);
        $this->assertSame(generation_manager::RUNNING, $record->status);
        $this->assertSame([[['target' => 'js/stubborn', 'kind' => 'js_runtime_error', 'message' => 'x']]], $generator::$errors);
        // Masked in what the repair receives.
        $this->assertStringNotContainsString('@', json_encode($generator::$errors));

        $record = generation_manager::refresh((int) $record->id, $generator);
        $this->assertSame(generation_manager::COMPLETED, $record->status);
        // Failing again: shown, the revision kept, no second repair.
        $record = generation_manager::verify((int) $record->id, [], [], 0, $generator, $error);
        $this->assertSame(generation_manager::COMPLETED, $record->status);
        $this->assertSame(1, (int) $record->repairs);
        $this->assertCount(1, $generator::$errors);
        $types = $this->event_types($record);
        $this->assertContains('verification_warning', $types);
        $this->assertContains('verified', $types);
        // Reported once per run (the editor reloaded): nothing more.
        $again = generation_manager::verify((int) $record->id, [], [], 0, $generator, $error);
        $this->assertSame($types, $this->event_types($again));
    }
}
