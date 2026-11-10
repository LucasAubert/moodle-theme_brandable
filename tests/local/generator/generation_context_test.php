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

use theme_brandable\local\inspector\inspector;
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\revision_manager;
use theme_brandable\local\overrides\template_source;
use theme_brandable\local\parent_theme;

#[\PHPUnit\Framework\Attributes\CoversClass(generation_context::class)]
/**
 * Tests for the generation context (request body of the backend contract v2).
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\generator\generation_context
 */
final class generation_context_test extends \advanced_testcase {
    use \theme_brandable\tests\requires_classic;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        // Classic as the parent where it can be compiled (removed from core in Moodle 5.3), Boost otherwise.
        set_config(parent_theme::SETTING, $this->classic_compiles() ? 'classic' : 'boost', 'theme_brandable');
        parent_theme::reset_caches();
    }

    #[\Override]
    protected function tearDown(): void {
        parent_theme::reset_caches();
        parent::tearDown();
    }

    public function test_request_body_follows_the_contract(): void {
        revision_manager::create([
            change::from_array(['type' => 'scss', 'target' => 'post/first-post', 'content' => '.a{}']),
            change::from_array(['type' => 'scss', 'target' => 'pre/first-pre', 'content' => '$x: 1;']),
            change::from_array(['type' => 'template', 'target' => 'theme_brandable/hero_banner', 'content' => 'hero']),
        ], 'First prompt', 'First summary');
        revision_manager::create([
            change::from_array(['type' => 'scss', 'target' => 'pre/second-pre', 'content' => '$y: 2;']),
        ], 'Second prompt', 'Second summary');

        inspector::store('http://example.com/my/', 'my-index', [
            ['name' => 'core/loading', 'sampleContext' => ['a' => 1]],
            ['name' => 'core/checkbox-toggleall-master', 'sampleContext' => null],
            ['name' => 'theme_brandable/hero_banner', 'sampleContext' => null],
            ['name' => 'core/full_header', 'sampleContext' => null],
        ]);

        $body = generation_context::build('http://example.com/my/')->to_request('  Make it pop  ');

        $this->assertSame('Make it pop', $body['prompt']);
        $this->assertMatchesRegularExpression('/^[a-z]{2,3}(?:_[a-z0-9]+)*$/', $body['lang']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $body['moodleVersion']);
        $classic = $this->classic_compiles();
        $this->assertSame($classic ? 'classic' : 'boost', $body['parentTheme']);
        $this->assertSame($classic ? ['classic', 'boost'] : ['boost'], $body['themeChain']);
        // Always sent; the modes exist from Moodle 5.3 (Boost in the chain), off by default.
        $this->assertSame(['available' => class_exists('\\theme_boost\\colour_mode'), 'enabled' => false,
            'default' => 'light'], $body['colourModes']);
        $this->assertSame(['url' => 'http://example.com/my/', 'pagetype' => 'my-index', 'pageKey' => null,
            'courseId' => null, 'cmId' => null], $body['page']);

        // Original sources (theme chain first), full Moodle names (dashes); database-only templates are in
        // draftOverrides.
        $names = array_column($body['pageTemplates'], 'name');
        $this->assertSame(['core/loading', 'core/checkbox-toggleall-master', 'core/full_header'], $names);
        $this->assertDebuggingNotCalled();
        $this->assertSame(template_source::get_original('core/full_header'), $body['pageTemplates'][2]['source']);
        $this->assertStringContainsString(
            $classic ? '/theme/classic/' : '/lib/templates/',
            template_source::get_original_path('core/full_header')
        );
        $this->assertSame(template_source::get_original_hash('core/loading'), $body['pageTemplates'][0]['sourceHash']);
        $this->assertSame(['a' => 1], $body['pageTemplates'][0]['sampleContext']);

        // Effective concatenation order: pre chunks, post chunks, then templates.
        $this->assertSame(
            ['pre/first-pre', 'pre/second-pre', 'post/first-post', 'theme_brandable/hero_banner'],
            array_column($body['draftOverrides'], 'target')
        );

        $this->assertSame([
            ['prompt' => 'First prompt', 'summary' => 'First summary'],
            ['prompt' => 'Second prompt', 'summary' => 'Second summary'],
        ], $body['history']);
        $this->assertSame(revision_manager::get_session_id(), $body['sessionId']);

        // Once published, a new session: its id, and no history of the version.
        revision_manager::publish('Version');
        $body = generation_context::build('http://example.com/my/')->to_request('Next');
        $this->assertSame([], $body['history']);
        $this->assertSame(revision_manager::get_session_id(), $body['sessionId']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $body['sessionId']);
    }

    public function test_selection_is_sent_without_its_locator(): void {
        $this->assertArrayNotHasKey('selection', generation_context::build()->to_request('x'));

        $clean = selection::clean(['html' => '<a>x</a>', 'label' => 'Language', 'locator' => 'a.x',
            'ancestors' => [['tag' => 'body', 'id' => 'page-my-index', 'classes' => ['x'], 'data' => []]]]);
        $body = generation_context::build(null, $clean)->to_request('x');
        $this->assertSame(['html', 'ancestors', 'template', 'templates', 'label', 'unstableIds'], array_keys($body['selection']));
        $this->assertNull($body['selection']['template']);
        $this->assertSame('page-my-index', $body['selection']['ancestors'][0]['id']);
    }

    public function test_unknown_page_falls_back_on_the_last_inspected_page(): void {
        inspector::store('http://example.com/course/index.php', 'course-index', []);
        $body = generation_context::build('http://example.com/never-inspected.php')->to_request('x');
        $this->assertSame(['url' => 'http://example.com/never-inspected.php', 'pagetype' => 'course-index', 'pageKey' => null,
            'courseId' => null, 'cmId' => null], $body['page']);

        $body = generation_context::build(null)->to_request('x');
        $this->assertSame('http://example.com/course/index.php', $body['page']['url']);
    }

    public function test_page_templates_over_the_limit_are_dropped_with_a_debugging_message(): void {
        $templates = array_fill(0, generation_context::MAX_PAGE_TEMPLATES + 1, ['name' => 'core/loading', 'sampleContext' => null]);
        inspector::store('http://example.com/my/', 'my-index', $templates);

        $body = generation_context::build('http://example.com/my/')->to_request('x');
        $this->assertCount(generation_context::MAX_PAGE_TEMPLATES, $body['pageTemplates']);
        $this->assertDebuggingCalled('theme_brandable: page template core/loading not sent to the generator (more than '
            . generation_context::MAX_PAGE_TEMPLATES . ' templates).');
    }
}
