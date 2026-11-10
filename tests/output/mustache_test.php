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

namespace theme_brandable\output;

use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\context;
use theme_brandable\local\overrides\revision_manager;
use theme_brandable\output\mustache\injector;
use theme_brandable\output\mustache\lazy_engine;
use theme_brandable\output\mustache\loader;

#[\PHPUnit\Framework\Attributes\CoversClass(loader::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(injector::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(lazy_engine::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(renderer_factory::class)]
/**
 * Tests for the DB-first Mustache loader and its injection into every renderer (D1).
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\output\mustache\loader
 * @covers     \theme_brandable\output\mustache\injector
 * @covers     \theme_brandable\output\mustache\lazy_engine
 * @covers     \theme_brandable\output\renderer_factory
 */
final class mustache_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $PAGE;

        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url('/');
        $PAGE->set_context(\context_system::instance());
        $PAGE->force_theme('brandable');
    }

    /**
     * Creates a revision of template overrides.
     *
     * @param array $templates Template name => content (null to delete).
     * @return int Revision id.
     */
    private function revision(array $templates): int {
        $changes = [];
        foreach ($templates as $name => $content) {
            $changes[] = change::from_array(['type' => 'template', 'target' => $name, 'content' => $content,
                'deleted' => $content === null]);
        }
        return revision_manager::create($changes, 'p', 's');
    }

    /**
     * Renders a template with a fresh renderer of a component.
     *
     * @param string $component Renderer component.
     * @param string $template Template name.
     * @param array $data Context.
     * @return string
     */
    private function render(string $component, string $template, array $data = []): string {
        global $PAGE;
        return $PAGE->get_renderer($component)->render_from_template($template, $data);
    }

    public function test_renderer_base_still_has_private_mustache_property(): void {
        // Regression guard for D1: if core changes this, injection is disabled (degraded mode).
        $this->assertTrue(property_exists(\core\output\renderer_base::class, 'mustache'));
        $property = new \ReflectionProperty(\core\output\renderer_base::class, 'mustache');
        $this->assertTrue($property->isPrivate());
        $this->assertTrue(method_exists(\core\output\renderer_base::class, 'get_mustache'));
        $this->assertTrue(injector::is_supported());
    }

    public function test_lazy_engine_delegates_every_public_method(): void {
        $lazy = new \ReflectionClass(lazy_engine::class);
        foreach ([\Mustache_Engine::class, \core\output\mustache_engine::class] as $class) {
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isConstructor() || $method->isStatic()) {
                    continue;
                }
                $this->assertSame(
                    lazy_engine::class,
                    $lazy->getMethod($method->getName())->getDeclaringClass()->getName(),
                    "lazy_engine must delegate {$class}::{$method->getName()}()"
                );
            }
        }
    }

    public function test_theme_uses_brandable_factory(): void {
        global $PAGE;
        $this->assertInstanceOf(core_renderer::class, $PAGE->get_renderer('core', null, RENDERER_TARGET_GENERAL));
        $this->assertSame('theme_brandable\\output\\renderer_factory', $PAGE->theme->rendererfactory);
    }

    public function test_published_draft_and_fallback(): void {
        $original = $this->render('core', 'core/loading');
        $this->assertStringContainsString('loading-icon', $original);

        $this->revision(['core/loading' => '<b>published {{#str}}loading{{/str}}</b>']);
        revision_manager::publish();
        $r1 = revision_manager::get_published();
        $r2 = $this->revision(['core/loading' => '<b>draft</b>']);

        // Published by default, helpers (str) still available.
        $this->assertSame('<b>published ' . get_string('loading') . '</b>', $this->render('core', 'core/loading'));
        // Draft when the draft revision is active (preview).
        $this->assertSame('<b>draft</b>', context::with_revision($r2, fn() => $this->render('core', 'core/loading')));
        // Fallback on the filesystem when nothing overrides the template.
        $this->assertSame($original, context::with_revision(0, fn() => $this->render('core', 'core/loading')));

        // Deleting the override falls back on the filesystem too.
        $r3 = $this->revision(['core/loading' => null]);
        $this->assertSame($original, context::with_revision($r3, fn() => $this->render('core', 'core/loading')));
        $this->assertGreaterThan($r1, $r3);
    }

    public function test_plugin_renderers_and_partials_use_overrides(): void {
        $this->revision([
            'theme_brandable/brandable_page' => '<main>{{> theme_brandable/brandable_part}}</main>',
            'theme_brandable/brandable_part' => '<i>{{name}}</i>',
        ]);
        revision_manager::publish();

        // New templates exist only in the database: any renderer must find them, partials included.
        foreach (['core', 'mod_forum', 'block_myoverview', 'core_course'] as $component) {
            $this->assertSame(
                '<main><i>x</i></main>',
                $this->render($component, 'theme_brandable/brandable_page', ['name' => 'x']),
                "Renderer of {$component}"
            );
        }
    }

    public function test_theme_brandable_templates_come_from_the_database(): void {
        // No file exists for templates created by the generator: the loader must not reach the finder.
        $this->assertNull(\theme_brandable\local\overrides\template_source::get_original('theme_brandable/hero_banner'));
        $this->revision([
            'theme_brandable/hero_banner' => '<section class="hero">{{title}}</section>',
            'core/loading' => '{{> theme_brandable/hero_banner}}',
        ]);
        revision_manager::publish();

        $hero = '<section class="hero">Hi</section>';
        $this->assertSame($hero, $this->render('core', 'theme_brandable/hero_banner', ['title' => 'Hi']));
        $this->assertSame($hero, $this->render('mod_forum', 'core/loading', ['title' => 'Hi']));
    }

    public function test_renderers_outside_brandable_are_untouched(): void {
        global $PAGE;

        $this->revision(['core/loading' => 'overridden']);
        revision_manager::publish();

        $page = new \moodle_page();
        $page->set_url('/');
        $page->set_context(\context_system::instance());
        $page->force_theme('boost');
        $this->assertStringContainsString('loading-icon', $page->get_renderer('core')->render_from_template('core/loading', []));
        $this->assertSame('overridden', $PAGE->get_renderer('core')->render_from_template('core/loading', []));
    }

    public function test_degraded_mode_core_renderer_still_uses_loader(): void {
        global $PAGE;

        $renderer = new core_renderer($PAGE, RENDERER_TARGET_GENERAL);
        $engine = (new \ReflectionMethod($renderer, 'get_mustache'))->invoke($renderer);
        $this->assertInstanceOf(loader::class, $engine->getLoader());
    }

    public function test_favicon_comes_from_the_parent(): void {
        global $PAGE;

        $favicon = $PAGE->get_renderer('core', null, RENDERER_TARGET_GENERAL)->favicon()->out(false);
        $this->assertStringContainsString('/theme/image.php/boost/theme/', $favicon);

        set_config('favicon', 'site.png', 'core_admin');
        $favicon = $PAGE->get_renderer('core', null, RENDERER_TARGET_GENERAL)->favicon()->out(false);
        $this->assertStringContainsString('/core_admin/favicon/', $favicon);
    }
}
