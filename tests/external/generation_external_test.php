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
use theme_brandable\local\inspector\inspector;
use theme_brandable\local\overrides\revision_manager;

#[\PHPUnit\Framework\Attributes\CoversClass(submit_prompt::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(get_generation::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(apply_changes::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(get_template_source::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(get_page_templates::class)]
/**
 * Tests for the generation, apply and inspection external functions.
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\external\submit_prompt
 * @covers     \theme_brandable\external\get_generation
 * @covers     \theme_brandable\external\apply_changes
 * @covers     \theme_brandable\external\get_template_source
 * @covers     \theme_brandable\external\get_page_templates
 */
final class generation_external_test extends \advanced_testcase {
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

    public function test_submit_and_poll(): void {
        $generation = $this->call('submit_prompt', ['Make it red', 'http://example.com/my/']);
        $this->assertSame('running', $generation['status']);
        $this->assertArrayNotHasKey('state', $generation);

        $generation = $this->call('get_generation', [$generation['id']]);
        $this->assertSame('completed', $generation['status']);
        $this->assertSame($generation['revisionid'], $generation['state']['draft']);
        $this->assertNotEmpty($generation['events']);

        // The steps stay attached to the revision in the editor state.
        $steps = $generation['state']['generations'][0];
        $this->assertSame($generation['revisionid'], $steps['revisionid']);
        $this->assertSame('completed', $steps['status']);
        $this->assertSame(array_column($generation['events'], 'message'), array_column($steps['events'], 'message'));
    }

    public function test_failed_attempts_are_listed(): void {
        set_config('brandable_generator', 'remote', 'theme_brandable');
        $generation = $this->call('submit_prompt', ['Make it red']);
        $this->assertSame('failed', $generation['status']);
        // Not connected: the editor offers to connect the site.
        $this->assertSame(get_string('error:notconnected', 'theme_brandable'), $generation['errormessage']);
        $this->assertSame('connect', $generation['action']);

        $state = $this->call('get_revisions');
        $this->assertSame([], $state['revisions']);
        $this->assertSame('failed', $state['generations'][0]['status']);
        $this->assertSame(0, $state['generations'][0]['revisionid']);
        $this->assertSame('Make it red', $state['generations'][0]['prompt']);
    }

    public function test_apply_changes(): void {
        $result = $this->call('apply_changes', [[
            ['type' => 'template', 'target' => 'theme_brandable/pushed', 'content' => '<b>{{x}}</b>', 'sourceHash' => ''],
        ], 'Pushed', 'From the backend']);
        $this->assertTrue($result['success']);
        $this->assertSame($result['revisionid'], revision_manager::get_draft());

        $result = $this->call('apply_changes', [[
            ['type' => 'js', 'target' => 'js/x', 'content' => 'alert(1)'],
        ], 'Bad']);
        $this->assertFalse($result['success']);
        $this->assertSame(['js/x'], array_column($result['errors'], 'target'));
        $this->assertSame(get_string('error:jsgenerationonly', 'theme_brandable'), $result['errors'][0]['message']);

        $result = $this->call('apply_changes', [[
            ['type' => 'scss', 'target' => 'Bad Target', 'content' => '.a{}'],
        ], 'Bad']);
        $this->assertSame(['Bad Target'], array_column($result['errors'], 'target'));

        $result = $this->call('apply_changes', [[
            ['type' => 'template', 'target' => 'theme_brandable/other', 'content' => 'x'],
        ], 'Late', '', 0]);
        $this->assertFalse($result['success']);
        $this->assertSame(get_string('error:draftchanged', 'theme_brandable'), $result['errors'][0]['message']);
    }

    public function test_template_source_and_page_templates(): void {
        $source = $this->call('get_template_source', ['core/loading']);
        $this->assertTrue($source['exists']);
        $this->assertStringContainsString('loading-icon', $source['source']);
        $this->assertSame(64, strlen($source['sourcehash']));
        $this->assertFalse($source['overridden']);

        $this->assertFalse($this->call('get_page_templates')['found']);
        inspector::store('http://example.com/my/', 'my-index', [['name' => 'core/loading', 'sampleContext' => ['a' => 1]]]);
        $page = $this->call('get_page_templates', ['http://example.com/my/']);
        $this->assertTrue($page['found']);
        $this->assertSame('my-index', $page['pagetype']);
        $this->assertSame('{"a":1}', $page['templates'][0]['samplecontext']);
    }
}
