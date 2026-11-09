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

namespace theme_brandable\local\validation;

use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\resolver;
use theme_brandable\local\overrides\template_source;

/**
 * Tests for the validation of changes before a revision is written.
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\validation\validator
 * @covers     \theme_brandable\local\validation\scss_compiler
 * @covers     \theme_brandable\local\overrides\template_source
 */
#[\PHPUnit\Framework\Attributes\CoversClass(validator::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(scss_compiler::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(template_source::class)]
final class validator_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
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
     * Asserts that a single error is reported for a target.
     *
     * @param string $target Expected target.
     * @param string $identifier Expected string identifier (theme_brandable).
     * @param array $errors Errors.
     */
    private function assert_error(string $target, string $identifier, array $errors): void {
        $this->assertCount(1, $errors, json_encode($errors));
        $this->assertSame($target, $errors[0]['target']);
        $prefix = strtok(get_string($identifier, 'theme_brandable', '%%'), '%');
        $this->assertStringStartsWith($prefix, $errors[0]['message']);
    }

    public function test_structure(): void {
        $this->assert_error('', 'error:nochanges', $this->errors([]));
        $this->assert_error('x.js', 'error:jstarget', $this->errors([['type' => 'js', 'target' => 'x.js', 'content' => 'a()']]));
        $this->assert_error('t', 'error:unknowntype', $this->errors([['type' => 'php', 'target' => 't', 'content' => 'x']]));
        foreach (['core', 'nope_nope/x', 'core/../x', 'mod_forum/', 'core/a b'] as $name) {
            $this->assert_error(
                $name,
                'error:invalidtemplatename',
                $this->errors([['type' => 'template', 'target' => $name, 'content' => 'x']])
            );
        }
        foreach (['', 'pre', 'pre/', 'pre/Brand', 'pre/brand_colors', 'middle/x', 'post/-x', 'post/x-'] as $target) {
            $this->assert_error(
                $target,
                'error:invalidscsstarget',
                $this->errors([['type' => 'scss', 'target' => $target, 'content' => '.a{}']])
            );
        }
        $this->assert_error(
            'theme_brandable/brandable_x',
            'error:emptycontent',
            $this->errors([['type' => 'template', 'target' => 'theme_brandable/brandable_x', 'content' => '  ']])
        );
        $this->assert_error(
            'theme_brandable/brandable_x',
            'error:nothingtodelete',
            $this->errors([['type' => 'template', 'target' => 'theme_brandable/brandable_x', 'deleted' => true]])
        );
        $this->assert_error('theme_brandable/brandable_x', 'error:duplicatetarget', $this->errors([
            ['type' => 'template', 'target' => 'theme_brandable/brandable_x', 'content' => 'a'],
            ['type' => 'template', 'target' => 'theme_brandable/brandable_x', 'content' => 'b'],
        ]));

        // Valid names: existing core / plugin / theme templates, new theme_brandable templates (subdirectories too).
        $this->assertSame([], $this->errors([
            ['type' => 'template', 'target' => 'core/loading', 'content' => 'x'],
            ['type' => 'template', 'target' => 'mod_forum/discussion_list', 'content' => 'y'],
            ['type' => 'template', 'target' => 'theme_brandable/brandable_x', 'content' => 'x'],
            ['type' => 'template', 'target' => 'theme_brandable/sections/hero-banner', 'content' => 'z'],
        ]));
    }

    public function test_only_theme_brandable_templates_can_be_created(): void {
        // Any other component: the template must exist on the filesystem.
        foreach (['core/brandable_new', 'mod_forum/brandable_new', 'theme_boost/brandable_new'] as $target) {
            $this->assert_error(
                $target,
                'error:templatenotfound',
                $this->errors([['type' => 'template', 'target' => $target, 'content' => 'x']])
            );
        }
        // Brandable's own UI templates (files) cannot be overridden.
        $this->assertNotNull(template_source::get_original('theme_brandable/preview_banner'));
        $this->assert_error(
            'theme_brandable/preview_banner',
            'error:protectedtemplate',
            $this->errors([['type' => 'template', 'target' => 'theme_brandable/preview_banner', 'content' => 'x']])
        );
        // New theme_brandable templates have no source on the filesystem.
        $records = validator::validate([change::from_array(['type' => 'template', 'target' => 'theme_brandable/hero_banner',
            'content' => '<div class="hero">{{title}}</div>', 'sourceHash' => null])], resolver::empty_map());
        $this->assertNull($records[0]->sourcehash);
        $this->assertSame(0, $records[0]->needsreview);
    }

    public function test_partials_from_draft_and_candidate(): void {
        // Partial created in the revision being validated.
        $this->assertSame([], $this->errors([
            ['type' => 'template', 'target' => 'core/loading', 'content' => '{{> theme_brandable/hero_banner}}'],
            ['type' => 'template', 'target' => 'theme_brandable/hero_banner', 'content' => '<div>hero</div>'],
        ]));

        // Partial created by an earlier revision (resolved draft).
        $draft = resolver::apply(resolver::empty_map(), [(object) ['type' => 'template',
            'target' => 'theme_brandable/hero_banner', 'content' => '<div>hero</div>', 'deleted' => 0]], 1);
        $this->assertSame([], $this->errors([
            ['type' => 'template', 'target' => 'core/loading', 'content' => '{{> theme_brandable/hero_banner}}'],
        ], $draft));
        // Without it, the partial is unknown.
        $this->assert_error('core/loading', 'error:unknownpartial', $this->errors([
            ['type' => 'template', 'target' => 'core/loading', 'content' => '{{> theme_brandable/hero_banner}}'],
        ]));

        // Removing a template still used as a partial by another override is refused...
        $draft = resolver::apply($draft, [(object) ['type' => 'template', 'target' => 'core/loading',
            'content' => '{{> theme_brandable/hero_banner}}', 'deleted' => 0]], 2);
        $this->assert_error('core/loading', 'error:unknownpartial', $this->errors([
            ['type' => 'template', 'target' => 'theme_brandable/hero_banner', 'deleted' => true],
        ], $draft));
        // ... unless the override using it is removed at the same time.
        $this->assertSame([], $this->errors([
            ['type' => 'template', 'target' => 'theme_brandable/hero_banner', 'deleted' => true],
            ['type' => 'template', 'target' => 'core/loading', 'deleted' => true],
        ], $draft));
    }

    public function test_mustache(): void {
        $this->assert_error(
            'theme_brandable/brandable_x',
            'error:mustache',
            $this->errors([['type' => 'template', 'target' => 'theme_brandable/brandable_x', 'content' => '{{#a}}not closed']])
        );
        $this->assert_error(
            'theme_brandable/brandable_x',
            'error:mustache',
            $this->errors([['type' => 'template', 'target' => 'theme_brandable/brandable_x', 'content' => '{{/a}}']])
        );
        $this->assert_error(
            'theme_brandable/brandable_x',
            'error:unknownpartial',
            $this->errors([['type' => 'template', 'target' => 'theme_brandable/brandable_x',
                'content' => '{{> theme_brandable/brandable_missing}}']])
        );

        // Partials and blocks: existing on disk or overridden in the same candidate revision.
        $this->assertSame([], $this->errors([
            ['type' => 'template', 'target' => 'theme_brandable/brandable_x',
                'content' => '{{> core/loading}}{{> theme_brandable/brandable_y}}'
                    . '{{< theme_brandable/brandable_y}}{{$b}}B{{/b}}{{/theme_brandable/brandable_y}}'],
            ['type' => 'template', 'target' => 'theme_brandable/brandable_y', 'content' => '{{$b}}default{{/b}}'],
        ]));
    }

    public function test_scss_syntax_error_is_attributed_to_its_chunk(): void {
        $errors = $this->errors([
            ['type' => 'scss', 'target' => 'pre/fine', 'content' => '$brandable-ok: 1px;'],
            ['type' => 'scss', 'target' => 'post/broken', 'content' => '.a { color: red; '],
        ]);
        $this->assert_error('post/broken', 'error:scss', $errors);
    }

    public function test_scss_compile_error_is_attributed_to_its_chunk(): void {
        $errors = $this->errors([
            ['type' => 'scss', 'target' => 'pre/fine', 'content' => '$brandable-ok: 1px;'],
            ['type' => 'scss', 'target' => 'post/broken', 'content' => ".a { margin: 0; }\n.b { color: \$brandable-undefined; }"],
        ]);
        $this->assert_error('post/broken', 'error:scss', $errors);
        // The line is relative to the chunk.
        $this->assertStringContainsString('line: 2,', $errors[0]['message']);

        // Variables defined in a pre chunk are visible from post chunks and the theme.
        $this->assertSame([], $this->errors([
            ['type' => 'scss', 'target' => 'pre/vars', 'content' => '$brandable-color: #123456; $primary: #654321;'],
            ['type' => 'scss', 'target' => 'post/use', 'content' => '.brandable { color: $brandable-color; }'],
        ]));
    }

    public function test_source_hash_and_review_flag(): void {
        $original = template_source::get_original('core/loading');
        $this->assertNotNull($original);
        $hash = template_source::hash($original);
        $this->assertSame($hash, template_source::hash(str_replace("\n", "\r\n", $original)));

        $records = validator::validate([
            change::from_array(['type' => 'template', 'target' => 'core/loading', 'content' => 'a', 'sourceHash' => $hash]),
            change::from_array(['type' => 'template', 'target' => 'core/action_menu', 'content' => 'b',
                'sourceHash' => str_repeat('0', 64)]),
            change::from_array(['type' => 'template', 'target' => 'theme_brandable/brandable_new', 'content' => 'c',
                'sourceHash' => null]),
            change::from_array(['type' => 'template', 'target' => 'core/drag_handle', 'content' => 'd']),
        ], resolver::empty_map());

        // Matching hash.
        $this->assertSame($hash, $records[0]->sourcehash);
        $this->assertSame(0, $records[0]->needsreview);
        // Different hash: stored with the plugin's hash, flagged for review.
        $this->assertSame(template_source::get_original_hash('core/action_menu'), $records[1]->sourcehash);
        $this->assertSame(str_repeat('0', 64), $records[1]->remotesourcehash);
        $this->assertSame(1, $records[1]->needsreview);
        // New template: null on both sides.
        $this->assertNull($records[2]->sourcehash);
        $this->assertSame(0, $records[2]->needsreview);
        // No hash sent (manual change): computed, not flagged.
        $this->assertNotNull($records[3]->sourcehash);
        $this->assertSame(0, $records[3]->needsreview);

        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $records[0]->moodleversion);
        $this->assertSame(hash('sha256', 'a'), $records[0]->contenthash);
    }

    public function test_compile_uses_chunks(): void {
        $map = resolver::apply(resolver::empty_map(), [
            (object) ['type' => 'scss', 'target' => 'pre/colors', 'content' => '$primary: #c0ffee;', 'deleted' => 0],
            (object) ['type' => 'scss', 'target' => 'post/marker', 'content' => '.brandable-marker { color: #bada55; }',
                'deleted' => 0],
        ], 0);
        $css = scss_compiler::compile($map);
        $this->assertStringContainsString('#bada55', $css);
        $this->assertStringContainsString('#c0ffee', $css);
    }

    public function test_original_source_resolution_matches_core_finder(): void {
        global $PAGE;

        foreach (['boost', 'classic'] as $parent) {
            set_config(\theme_brandable\local\parent_theme::SETTING, $parent, 'theme_brandable');
            \theme_brandable\local\parent_theme::reset_caches();
            foreach (['core/loading', 'core/full_header', 'mod_forum/discussion_list', 'core/navbar', 'core/loginform'] as $name) {
                $this->assertSame(
                    \core\output\mustache_template_finder::get_template_filepath($name, 'brandable'),
                    template_source::get_original_path($name),
                    "{$name} with parent {$parent}"
                );
            }
            // Each call above loaded the Brandable theme config: reset what depends on the parent.
            $PAGE = new \moodle_page();
        }
        $this->assertNull(template_source::get_original_path('core/brandable_missing'));
        $this->assertNull(template_source::get_original_path('nope_nope/x'));
        $this->assertNull(template_source::get_original_path('core/../x'));
    }

    public function test_unknown_strings_are_refused(): void {
        $this->assert_error('theme_brandable/brandable_x', 'error:unknownstring', $this->errors([
            ['type' => 'template', 'target' => 'theme_brandable/brandable_x', 'content' => '{{#str}} welcome, moodle {{/str}}'],
        ]));
        $this->assert_error('theme_brandable/brandable_x', 'error:unknownstring', $this->errors([
            ['type' => 'template', 'target' => 'theme_brandable/brandable_x', 'content' => '{{#cleanstr}}nope_nope{{/cleanstr}}'],
        ]));
        // Existing strings, default component, dynamic identifiers.
        $this->assertSame([], $this->errors([
            ['type' => 'template', 'target' => 'theme_brandable/brandable_x',
                'content' => '{{#str}} sitehome, core {{/str}}{{#str}}loading{{/str}}{{#str}} pluginname, theme_brandable {{/str}}'
                    . '{{#str}} {{key}}, core {{/str}}'],
        ]));
    }
}
