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

use theme_brandable\local\generator\generation_context;
use theme_brandable\local\inspector\inspector;
use theme_brandable\local\validation\scss_compiler;
use theme_brandable\local\validation\validation_exception;

#[\PHPUnit\Framework\Attributes\CoversClass(scope::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(page_context::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(resolver::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(scss::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\theme_brandable\local\validation\validator::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(revision_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\theme_brandable\external\change_scope::class)]
/**
 * Tests for the scope of the overrides (site, pagetype, page, course, cm).
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\overrides\scope
 * @covers     \theme_brandable\local\overrides\page_context
 * @covers     \theme_brandable\local\overrides\resolver
 * @covers     \theme_brandable\local\overrides\scss
 * @covers     \theme_brandable\local\validation\validator
 * @covers     \theme_brandable\local\overrides\revision_manager
 * @covers     \theme_brandable\external\change_scope
 */
final class scope_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass Activity. */
    private \stdClass $cm;

    #[\Override]
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        // Demo generator.
        $CFG->theme_brandable_devmode = true;
        $this->course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance('page', $page->id);
    }

    #[\Override]
    protected function tearDown(): void {
        page_context::force(null);
        parent::tearDown();
    }

    /**
     * Builds a template change.
     *
     * @param string $target Target.
     * @param string|null $content Content (null: deletion).
     * @param array|null $scope Scope.
     * @return change
     */
    private function template(string $target, ?string $content, ?array $scope = null): change {
        return change::from_array(['type' => 'template', 'target' => $target, 'content' => $content,
            'deleted' => $content === null, 'scope' => $scope]);
    }

    /**
     * Builds an SCSS change.
     *
     * @param string $target Target.
     * @param string $content Content.
     * @param array|null $scope Scope.
     * @return change
     */
    private function chunk(string $target, string $content, ?array $scope = null): change {
        return change::from_array(['type' => 'scss', 'target' => $target, 'content' => $content, 'scope' => $scope]);
    }

    /**
     * Returns the error messages of changes that fail validation ([] if valid).
     *
     * @param change[] $changes Changes.
     * @return string[]
     */
    private function errors(array $changes): array {
        try {
            revision_manager::create($changes, 'p', 's');
        } catch (validation_exception $e) {
            return array_column($e->errors, 'message');
        }
        return [];
    }

    public function test_scope_model(): void {
        $this->assertTrue(scope::from_array(null)->is_site());
        $this->assertSame('', scope::from_array(['level' => 'site', 'value' => 'ignored'])->key());
        $page = scope::from_array(['level' => 'page', 'value' => 'bp-my-index']);
        $this->assertSame('@page:bp-my-index', $page->key());
        $this->assertSame(['level' => 'page', 'value' => 'bp-my-index'], $page->to_array());
        $this->assertSame('12', scope::from_array(['level' => 'course', 'value' => 12])->value);

        $ranks = array_map(fn($level) => (new scope($level, '1'))->rank(), scope::LEVELS);
        $this->assertSame([0, 1, 2, 3, 4], $ranks);

        $this->assertNull((new scope())->validate());
        $this->assertNull((new scope(scope::PAGETYPE, 'my-index'))->validate());
        $this->assertNull((new scope(scope::PAGE, 'bp-my-index'))->validate());
        $this->assertNull((new scope(scope::COURSE, (string) $this->course->id))->validate());
        $this->assertNull((new scope(scope::CM, (string) $this->cm->id))->validate());
        foreach (
            [[scope::PAGE, 'my-index'], [scope::PAGETYPE, 'My Index'], [scope::COURSE, (string) SITEID],
                [scope::COURSE, '99999'], [scope::CM, 'abc'], ['region', 'x']] as [$level, $value]
        ) {
            $this->assertNotNull((new scope($level, $value))->validate(), "$level: $value");
        }
    }

    public function test_scope_matching(): void {
        $page = new page_context('bp-my-courses', 'my-index', (int) $this->course->id, (int) $this->cm->id);
        $this->assertTrue((new scope())->matches($page));
        $this->assertTrue((new scope(scope::PAGETYPE, 'my-index'))->matches($page));
        $this->assertTrue((new scope(scope::PAGE, 'bp-my-courses'))->matches($page));
        $this->assertFalse((new scope(scope::PAGE, 'bp-my-index'))->matches($page));
        $this->assertTrue((new scope(scope::COURSE, (string) $this->course->id))->matches($page));
        $this->assertTrue((new scope(scope::CM, (string) $this->cm->id))->matches($page));
        // AJAX: no page key.
        $this->assertFalse((new scope(scope::PAGE, 'bp-my-courses'))->matches(new page_context(null, 'my-index')));
    }

    public function test_most_specific_template_override_wins(): void {
        revision_manager::create([
            $this->template('core/loading', 'site'),
            $this->template('core/loading', 'pagetype', ['level' => 'pagetype', 'value' => 'my-index']),
            $this->template('core/loading', 'page', ['level' => 'page', 'value' => 'bp-my-courses']),
            $this->template('core/loading', 'course', ['level' => 'course', 'value' => $this->course->id]),
            $this->template('core/loading', 'cm', ['level' => 'cm', 'value' => $this->cm->id]),
        ], 'p', 's');
        $draft = revision_manager::get_draft();
        $this->assertCount(5, resolver::get_variants(resolver::get_map($draft), 'template', 'core/loading'));

        $expectations = [
            'cm' => new page_context('bp-mod-page-view', 'mod-page-view', (int) $this->course->id, (int) $this->cm->id),
            'course' => new page_context('bp-course-view', 'course-view-topics', (int) $this->course->id),
            // The dashboard and "My courses" share their page type: the page key tells them apart.
            'page' => new page_context('bp-my-courses', 'my-index'),
            'pagetype' => new page_context('bp-my-index', 'my-index'),
            'site' => new page_context('bp-index', 'site-index'),
        ];
        foreach ($expectations as $expected => $page) {
            $this->assertSame($expected, context::with_revision($draft, fn() => context::get_template('core/loading', $page)));
        }

        // The renderer path uses the current page.
        page_context::force(new page_context('bp-my-courses', 'my-index'));
        $this->assertSame('page', context::with_revision($draft, fn() => context::get_template('core/loading')));
    }

    public function test_scoped_overrides_are_separate_overrides(): void {
        revision_manager::create([
            $this->template('core/loading', 'site'),
            $this->template('core/loading', 'page', ['level' => 'page', 'value' => 'bp-my-index']),
        ], 'p', 's');
        // Removing the page variant keeps the site one.
        revision_manager::create([$this->template('core/loading', null, ['level' => 'page', 'value' => 'bp-my-index'])], 'p', 's');
        $map = resolver::get_map(revision_manager::get_draft());
        $this->assertSame(['core/loading'], array_keys($map['template']));
        // Removing a scope that does not exist is refused.
        $this->assertSame(
            [get_string('error:nothingtodelete', 'theme_brandable')],
            $this->errors([$this->template('core/loading', null, ['level' => 'page', 'value' => 'bp-my-index'])])
        );
    }

    public function test_validation(): void {
        $this->assertSame(
            [get_string('error:prescope', 'theme_brandable')],
            $this->errors([$this->chunk('pre/colors', '$primary: red;', ['level' => 'page', 'value' => 'bp-my-index'])])
        );
        $this->assertCount(1, $this->errors([$this->template('core/loading', 'x', ['level' => 'course', 'value' => 99999])]));
        $this->assertCount(1, $this->errors([$this->template('core/loading', 'x', ['level' => 'galaxy', 'value' => 'x'])]));
        // Same target, two scopes, in one revision: allowed; same scope twice: refused.
        $this->assertSame([], $this->errors([
            $this->chunk('post/a', '.a { color: red; }'),
            $this->chunk('post/a', '.a { color: blue; }', ['level' => 'pagetype', 'value' => 'my-index']),
        ]));
        $this->assertSame([get_string('error:duplicatetarget', 'theme_brandable')], $this->errors([
            $this->chunk('post/b', '.b {}', ['level' => 'pagetype', 'value' => 'my-index']),
            $this->chunk('post/b', '.b {}', ['level' => 'pagetype', 'value' => 'my-index']),
        ]));
    }

    public function test_database_only_partials_must_cover_the_includer_scope(): void {
        $pagescope = ['level' => 'page', 'value' => 'bp-my-index'];
        // A page-scoped partial included by a site-wide override: missing on the other pages.
        $this->assertSame([get_string('error:unknownpartial', 'theme_brandable', 'theme_brandable/hero')], $this->errors([
            $this->template('core/loading', '{{> theme_brandable/hero}}'),
            $this->template('theme_brandable/hero', 'hero', $pagescope),
        ]));
        // Same scope, or a site-wide partial: fine.
        $this->assertSame([], $this->errors([
            $this->template('core/loading', '{{> theme_brandable/hero}}', $pagescope),
            $this->template('theme_brandable/hero', 'hero', $pagescope),
        ]));
        $this->assertSame([], $this->errors([
            $this->template('core/drag_handle', '{{> theme_brandable/hero}}', ['level' => 'pagetype', 'value' => 'my-index']),
            $this->template('theme_brandable/hero', 'hero'),
        ]));
    }

    public function test_scss_chunks_are_wrapped_with_increasing_specificity(): void {
        $map = resolver::apply(resolver::empty_map(), array_map(fn(change $change) => (object) [
            'type' => 'scss', 'target' => $change->target, 'content' => $change->content, 'deleted' => 0,
            'scopelevel' => $change->scope->level, 'scopevalue' => $change->scope->value,
        ], [
            $this->chunk('post/site', '.brandable-x { color: #000001; }'),
            $this->chunk('post/cm', '.brandable-x { color: #000005; }', ['level' => 'cm', 'value' => $this->cm->id]),
            $this->chunk('post/course', '.brandable-x { color: #000004; }', ['level' => 'course', 'value' => $this->course->id]),
            $this->chunk(
                'post/page',
                '.brandable-x { color: #000003; } & { margin: 0; }',
                ['level' => 'page', 'value' => 'bp-my-index']
            ),
            $this->chunk('post/type', '.brandable-x { color: #000002; }', ['level' => 'pagetype', 'value' => 'my-index']),
        ]), 0);

        $code = scss::build($map, 'post');
        $this->assertStringContainsString("/* brandable-chunk: post/site */\n.brandable-x", $code);
        $this->assertStringContainsString(
            '/* brandable-chunk: post/page@page:bp-my-index */ body.bp-my-index:not(#bp-s1) {',
            $code
        );

        $css = scss_compiler::compile($map);
        $selectors = [
            '.brandable-x' => [0, 1, 0],
            'body#page-my-index .brandable-x' => [1, 1, 1],
            'body.bp-my-index:not(#bp-s1) .brandable-x' => [1, 2, 1],
            "body.course-{$this->course->id}:not(#bp-s1):not(#bp-s2) .brandable-x" => [2, 2, 1],
            "body.cmid-{$this->cm->id}:not(#bp-s1):not(#bp-s2):not(#bp-s3) .brandable-x" => [3, 2, 1],
        ];
        foreach (array_keys($selectors) as $selector) {
            // Each rule follows another rule, a selector list or the chunk marker comment.
            $this->assertMatchesRegularExpression('~(^|[},/])\s*' . preg_quote($selector, '~') . '\s*\{~', $css, $selector);
        }
        // The & selector targets the body itself.
        $this->assertMatchesRegularExpression('~body\.bp-my-index:not\(#bp-s1\)\s*\{\s*margin:\s*0~', $css);
        // Specificities follow the levels order (ids, classes, elements), whatever the order of the chunks.
        $specificities = array_values($selectors);
        $sorted = $specificities;
        sort($sorted);
        $this->assertSame($sorted, $specificities);
    }

    public function test_rescope(): void {
        global $DB;

        $r1 = revision_manager::create([$this->chunk('post/help', '.help { display: none; }')], 'Hide help', 's');
        $changeid = (int) $DB->get_field('theme_brandable_change', 'id', ['revisionid' => $r1]);

        $state = \core_external\external_api::clean_returnvalue(
            \theme_brandable\external\change_scope::execute_returns(),
            \theme_brandable\external\change_scope::execute($changeid, 'page', 'bp-my-index')
        );
        $r2 = $state['draft'];
        $this->assertGreaterThan($r1, $r2);
        $map = resolver::get_map($r2);
        $this->assertSame(['post/help@page:bp-my-index'], array_keys($map['scss']));
        $this->assertSame('.help { display: none; }', $map['scss']['post/help@page:bp-my-index']['content']);
        $this->assertSame(get_string('scopechanged', 'theme_brandable', (object) ['target' => 'post/help',
            'from' => get_string('scope_site', 'theme_brandable'),
            'to' => get_string('scope_page', 'theme_brandable', 'bp-my-index')]), $state['revisions'][0]['summary']);
        // The older change is no longer the active override.
        $changes = array_column($state['revisions'][1]['changes'], null, 'id');
        $this->assertFalse($changes[$changeid]['canrescope']);
        $this->expectException(\moodle_exception::class);
        revision_manager::rescope($changeid, new scope());
    }

    public function test_rescope_is_validated(): void {
        global $DB;

        $r1 = revision_manager::create([$this->chunk('pre/colors', '$primary: red;')], 'p', 's');
        $changeid = (int) $DB->get_field('theme_brandable_change', 'id', ['revisionid' => $r1]);
        $this->expectException(validation_exception::class);
        revision_manager::rescope($changeid, new scope(scope::PAGE, 'bp-my-index'));
    }

    public function test_page_init_adds_the_body_class_and_picks_the_variant(): void {
        global $CFG;
        require_once($CFG->dirroot . '/theme/brandable/lib.php');

        revision_manager::create([
            $this->template('core/loading', 'site'),
            $this->template('core/loading', 'course', ['level' => 'course', 'value' => $this->course->id]),
        ], 'p', 's');
        revision_manager::publish();

        $page = new \moodle_page();
        $page->set_course($this->course);
        theme_brandable_page_init($page);
        $data = null;
        $variables = (new \ReflectionProperty($page->requires, 'jsinitvariables'))->getValue($page->requires);
        foreach ($variables['head'] as [$name, $value]) {
            $data = $name === 'theme_brandable_overrides' ? $value : $data;
        }
        $this->assertSame(hash('sha256', 'course'), ((array) $data['templates'])['core/loading']);

        $key = \theme_brandable\local\page_key::current();
        if ($key !== null) {
            $this->assertContains($key, explode(' ', $page->bodyclasses));
        }
    }

    public function test_request_carries_the_page_context_and_the_scopes(): void {
        $page = ['level' => 'page', 'value' => 'bp-my-index'];
        revision_manager::create([
            $this->chunk('post/x', '.x { color: red; }'),
            $this->chunk('post/x', '.x { color: blue; }', $page),
        ], 'p', 's');
        // Same (type, target, scope): replaces that override only.
        revision_manager::create([$this->chunk('post/x', '.x { color: green; }', $page)], 'p', 's');

        $url = 'http://example.com/mod/page/view.php?id=' . $this->cm->id;
        inspector::store($url, 'mod-page-view', [], 'bp-mod-page-view', (int) $this->course->id, (int) $this->cm->id);
        $body = generation_context::build($url)->to_request('x');

        $this->assertSame(['url' => $url, 'pagetype' => 'mod-page-view', 'pageKey' => 'bp-mod-page-view',
            'courseId' => (string) $this->course->id, 'cmId' => (string) $this->cm->id], $body['page']);
        $this->assertSame([
            ['type' => 'scss', 'target' => 'post/x', 'content' => '.x { color: red; }',
                'scope' => ['level' => 'site', 'value' => null]],
            ['type' => 'scss', 'target' => 'post/x', 'content' => '.x { color: green; }', 'scope' => $page],
        ], $body['draftOverrides']);

        // Dashboard and pages out of a course: the site course is never sent.
        inspector::store('http://example.com/my/', 'my-index', [], 'bp-my-index', (int) SITEID, null);
        $body = generation_context::build('http://example.com/my/')->to_request('x');
        $this->assertNull($body['page']['courseId']);
        $this->assertNull($body['page']['cmId']);
        $this->assertSame('bp-my-index', $body['page']['pageKey']);
    }

    public function test_page_context_never_uses_the_site_course(): void {
        global $PAGE;

        $PAGE->set_url('/my/');
        $PAGE->set_context(\context_system::instance());
        $this->assertNull(page_context::current()->courseid);

        $coursepage = new \moodle_page();
        $coursepage->set_course($this->course);
        $PAGE = $coursepage;
        $this->assertSame((int) $this->course->id, page_context::current()->courseid);
    }

    public function test_mock_generator_scopes_its_changes(): void {
        inspector::store('http://example.com/my/courses.php', 'my-index', [], 'bp-my-courses');
        $record = \theme_brandable\local\generator\generation_manager::submit(
            'Une navbar sombre et des coins arrondis sur cette page',
            'http://example.com/my/courses.php'
        );
        $record = \theme_brandable\local\generator\generation_manager::refresh($record->id);
        $this->assertSame('completed', $record->status, (string) ($record->errormessage ?? ''));
        $this->assertSame(
            ['post/dark-navbar@page:bp-my-courses', 'post/rounded-corners@page:bp-my-courses'],
            array_keys(resolver::get_map(revision_manager::get_draft())['scss'])
        );
    }
}
