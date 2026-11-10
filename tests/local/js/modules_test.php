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

use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\page_context;
use theme_brandable\local\overrides\resolver;
use theme_brandable\local\overrides\revision_manager;
use theme_brandable\local\preview;
use theme_brandable\local\validation\validation_exception;
use theme_brandable\local\validation\validator;

#[\PHPUnit\Framework\Attributes\CoversClass(modules::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(validator::class)]
/**
 * Tests for the generated JavaScript modules: validation, storage, choice of the modules of a page, conditions of
 * execution and file of the modules (spec docs/SPEC-javascript.md, B5 to B8).
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\js\modules
 * @covers     \theme_brandable\local\validation\validator
 */
final class modules_test extends \advanced_testcase {
    /** @var string A signature of the right form (not checked by the plugin, contract 12.3). */
    private const SIGNATURE = 'v1.abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ';

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        preview::reset();
        set_config(modules::ENABLED_CONFIG, 1, 'theme_brandable');
    }

    #[\Override]
    protected function tearDown(): void {
        preview::disable();
        preview::reset();
        parent::tearDown();
    }

    /**
     * Returns a js change in the external format.
     *
     * @param string $target Target.
     * @param array $override Keys replaced.
     * @return array
     */
    private function js(string $target = 'js/welcome', array $override = []): array {
        return $override + [
            'type' => 'js',
            'target' => $target,
            'content' => "export default function (b) {\n  b.find('.x');\n}\n",
            'compiled' => 'function(e){"use strict";e.find(".x")}',
            'report' => ['analyzer' => '1', 'apis' => ['b.find'], 'findings' => []],
            'signature' => self::SIGNATURE,
        ];
    }

    /**
     * Validates changes and returns the errors (empty when valid).
     *
     * @param array $changes Changes in the external format.
     * @param array|null $basemap Draft map, empty when null.
     * @return array
     */
    private function errors(array $changes, ?array $basemap = null): array {
        try {
            validator::validate(array_map([change::class, 'from_array'], $changes), $basemap ?? resolver::empty_map());
        } catch (validation_exception $e) {
            return $e->errors;
        }
        return [];
    }

    /**
     * Asserts a single error of a string.
     *
     * @param string $identifier String identifier (theme_brandable).
     * @param array $errors Errors.
     */
    private function assert_error(string $identifier, array $errors): void {
        $this->assertCount(1, $errors, json_encode($errors));
        $prefix = strtok(get_string($identifier, 'theme_brandable', '%%'), '%');
        $this->assertStringStartsWith($prefix, $errors[0]['message']);
    }

    /**
     * Creates a draft revision.
     *
     * @param array $changes Changes in the external format.
     * @return int
     */
    private function create(array $changes): int {
        return revision_manager::create(array_map([change::class, 'from_array'], $changes), 'p', 's');
    }

    /**
     * Returns a page on a URL.
     *
     * @param string $url URL.
     * @param string $layout Layout.
     * @return \moodle_page
     */
    private function page(string $url = '/my/index.php', string $layout = 'mydashboard'): \moodle_page {
        $page = new \moodle_page();
        $page->set_context(\context_system::instance());
        $page->set_url($url);
        $page->set_pagelayout($layout);
        $page->set_pagetype('my-index');
        return $page;
    }

    /**
     * Publishes the draft, its modules reviewed first (spec B13).
     */
    private function publish_reviewed(): void {
        foreach (review::get_changed() as $module) {
            review::record($module['key'], $module['hash']);
        }
        revision_manager::publish();
    }

    public function test_validation(): void {
        $this->assertSame([], $this->errors([$this->js()]));
        foreach (['x.js', 'js/', 'js/Welcome', 'js/a_b', 'js/a/b', 'welcome'] as $target) {
            $this->assert_error('error:jstarget', $this->errors([$this->js($target)]));
        }
        $this->assert_error('error:jscontent', $this->errors([$this->js('js/a', ['content' => str_repeat('a', 20001)])]));
        $this->assert_error('error:jscompiled', $this->errors([$this->js('js/a', ['compiled' => null])]));
        $this->assert_error('error:jscompiled', $this->errors([$this->js('js/a', ['compiled' => 'alert(1)'])]));
        $this->assert_error('error:jscompiled', $this->errors([$this->js(
            'js/a',
            ['compiled' => 'function(b){' . str_repeat('a', 10000) . '}']
        )]));
        // Without signature: accepted (a module of the cloud changed out of Brandable, js_unsigned); a signature given
        // has the form of Brandable.
        $this->assertSame([], $this->errors([$this->js('js/a', ['signature' => null])]));
        $this->assert_error('error:jssignature', $this->errors([$this->js('js/a', ['signature' => 'forged'])]));
        $this->assert_error('error:jsreport', $this->errors([$this->js('js/a', ['report' => '{not json'])]));
        $this->assert_error('error:assetmarker', $this->errors([$this->js(
            'js/a',
            ['compiled' => 'function(b){b.find("[[brandable-asset:Bad Slug]]")}']
        )]));

        // Six modules at most: a target counts once, every scope.
        $changes = [];
        foreach (range(1, 6) as $i) {
            $changes[] = $this->js("js/m$i");
        }
        $changes[] = $this->js('js/m1', ['scope' => ['level' => 'pagetype', 'value' => 'my-index']]);
        $this->assertSame([], $this->errors($changes));
        $changes[] = $this->js('js/m7');
        $this->assert_error('error:jsmaxmodules', $this->errors($changes));

        // Templates given to b.render: existing, or created in the revision.
        $render = function (string $name): array {
            return $this->js('js/r', ['content' => "export default function (b) {\n  b.render('$name', {});\n}"]);
        };
        $this->assertSame([], $this->errors([$render('core/loading')]));
        $this->assert_error('error:jstemplate', $this->errors([$render('theme_brandable/js_missing')]));
        $this->assertSame([], $this->errors([
            ['type' => 'template', 'target' => 'theme_brandable/js_card', 'content' => '<p>{{text}}</p>'],
            $render('theme_brandable/js_card'),
        ]));
    }

    public function test_storage_keeps_the_signature(): void {
        $draft = $this->create([$this->js(), $this->js('js/welcome', ['scope' => ['level' => 'pagetype', 'value' => 'my-index']])]);
        $map = resolver::get_map($draft);
        $entry = $map[change::TYPE_JS]['js/welcome'];
        $this->assertSame(self::SIGNATURE, $entry['signature']);
        $this->assertSame('function(e){"use strict";e.find(".x")}', $entry['compiled']);
        $this->assertSame('1', $entry['jsanalyzer']);
        $this->assertSame(['b.find'], json_decode($entry['report'], true)['apis']);

        // Published (complete revision rebuilt from the changes): the same.
        $this->publish_reviewed();
        $published = resolver::get_map(revision_manager::get_published())[change::TYPE_JS];
        $this->assertCount(2, $published);
        foreach ($published as $entry) {
            $this->assertSame(self::SIGNATURE, $entry['signature']);
            $this->assertSame('function(e){"use strict";e.find(".x")}', $entry['compiled']);
        }

        // Moved to another scope (the active change of the draft): the same module.
        global $DB;
        $this->create([$this->js('js/welcome', ['scope' => ['level' => 'pagetype', 'value' => 'my-index'],
            'content' => "export default function (b) {}\n", 'compiled' => 'function(b){}'])]);
        $change = $DB->get_record_select('theme_brandable_change', "type = 'js' AND scopelevel = 'pagetype'
            AND revisionid = ?", [revision_manager::get_draft()]);
        $draft = revision_manager::rescope((int) $change->id, new \theme_brandable\local\overrides\scope('page', 'bp-my-index'));
        $entry = resolver::get_map($draft)[change::TYPE_JS]['js/welcome@page:bp-my-index'] ?? null;
        $this->assertNotNull($entry, json_encode(array_keys(resolver::get_map($draft)[change::TYPE_JS])));
        $this->assertSame('function(b){}', $entry['compiled']);
        $this->assertSame(self::SIGNATURE, $entry['signature']);

        // Removed.
        $draft = $this->create([['type' => 'js', 'target' => 'js/welcome', 'deleted' => true]]);
        $this->assertArrayNotHasKey('js/welcome', resolver::get_map($draft)[change::TYPE_JS]);
    }

    public function test_select_by_scope_and_order(): void {
        $draft = $this->create([
            $this->js('js/zeta'),
            $this->js('js/alpha', ['compiled' => 'function(b){1}']),
            $this->js('js/alpha', ['compiled' => 'function(b){2}', 'scope' => ['level' => 'pagetype', 'value' => 'my-index']]),
            $this->js('js/broken', ['report' => ['analyzer' => '1', 'apis' => [], 'findings' => [], 'errors' => ['x']]]),
        ]);
        $map = resolver::get_map($draft);
        $selected = modules::select($map, new page_context('bp-my-index', 'my-index'));
        $this->assertSame(['js/alpha', 'js/zeta'], array_column($selected, 'target'));
        $this->assertSame('function(b){2}', $selected[0]['compiled']);
        $selected = modules::select($map, new page_context('bp-course-view', 'course-view-topics'));
        $this->assertSame('function(b){1}', $selected[0]['compiled']);

        // The file of the modules: every variant without error, under its key; markers resolved.
        $bundle = modules::bundle($draft);
        $this->assertStringStartsWith("define([], function() {\n\"use strict\";\nreturn {\n", $bundle);
        $this->assertStringContainsString('"js\/alpha": function(b){1}', $bundle);
        $this->assertStringContainsString('"js\/alpha@pagetype:my-index": function(b){2}', $bundle);
        $this->assertStringNotContainsString('broken', $bundle);
        $this->assertMatchesRegularExpression('~^[0-9a-f]{16}$~', modules::bundle_hash($draft));
        $this->assertSame('a("")', modules::resolve_markers('a("[[brandable-asset:nope]]")'));
    }

    public function test_run_on(): void {
        $this->create([$this->js()]);
        $this->publish_reviewed();
        $page = $this->page();
        $this->assertTrue(modules::run_on($page));

        // Setting off, kill switch.
        set_config(modules::ENABLED_CONFIG, 0, 'theme_brandable');
        $this->assertFalse(modules::run_on($page));
        set_config(modules::ENABLED_CONFIG, 1, 'theme_brandable');
        set_config(modules::SUSPENDED_CONFIG, 1, 'theme_brandable');
        $this->assertFalse(modules::run_on($page));
        set_config(modules::SUSPENDED_CONFIG, 0, 'theme_brandable');

        // Administration pages.
        $this->assertFalse(modules::run_on($this->page('/admin/settings.php', 'admin')));
        $this->assertFalse(modules::run_on($this->page('/admin/user.php', 'standard')));
        $this->assertFalse(modules::run_on($this->page('/my/index.php', 'admin')));
        // Pages of Brandable (the editor is not an administration page).
        $this->assertFalse(modules::run_on($this->page('/theme/brandable/editor.php', 'embedded')));

        // Parameter brandable_nojs: administrators (managejs) only.
        $_GET[modules::NOJS_PARAM] = 1;
        $this->assertFalse(modules::run_on($page));
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertTrue(modules::run_on($page));
        unset($_GET[modules::NOJS_PARAM]);
    }

    public function test_page_config(): void {
        $page = $this->page();
        $context = new page_context('bp-my-index', 'my-index');
        // No module for the page: nothing.
        $this->assertNull(modules::page_config($page, $context, 'bp-my-index'));

        $this->create([$this->js()]);
        $this->publish_reviewed();
        $draft = $this->create([$this->js('js/draft-only')]);

        // Published version only, out of the preview.
        $config = modules::page_config($page, $context, 'bp-my-index');
        $this->assertSame([['key' => 'js/welcome', 'target' => 'js/welcome']], $config['modules']);
        $published = revision_manager::get_published();
        $this->assertStringContainsString("rev=$published", $config['url']);
        $this->assertSame(['pagetype' => 'my-index', 'pageKey' => 'bp-my-index', 'courseId' => null, 'cmId' => null,
            'lang' => 'en'], $config['page']);
        $this->assertMatchesRegularExpression('~^[0-9a-f]{16}$~', $config['user']);
        $this->assertFalse($config['preview']);

        // Preview: the draft.
        preview::enable();
        preview::reset();
        $config = modules::page_config($page, $context, 'bp-my-index');
        $this->assertSame(['js/draft-only', 'js/welcome'], array_column($config['modules'], 'target'));
        $this->assertStringContainsString("rev=$draft", $config['url']);
        $this->assertTrue($config['preview']);
    }

    public function test_user_key(): void {
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $this->setUser($user1);
        $key1 = modules::user_key();
        $this->assertSame($key1, modules::user_key());
        $this->setUser($user2);
        $this->assertNotSame($key1, modules::user_key());
        // Another site (key): other identifiers.
        set_config(modules::USERKEY_CONFIG, 'another key', 'theme_brandable');
        $this->setUser($user1);
        $this->assertNotSame($key1, modules::user_key());
    }

    public function test_rendered_templates(): void {
        $this->assertSame(['theme_brandable/card', 'core/loading'], modules::rendered_templates(
            "b.render('theme_brandable/card', {}); x.render(\"core/loading\"); b.render(name); " .
                "b.render(\x60theme_brandable/card\x60)"
        ));
    }
}
