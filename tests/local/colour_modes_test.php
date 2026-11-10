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

namespace theme_brandable\local;

use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\resolver;
use theme_brandable\local\validation\scss_compiler;
use theme_brandable\local\validation\validation_exception;
use theme_brandable\local\validation\validator;

#[\PHPUnit\Framework\Attributes\CoversClass(colour_modes::class)]
/**
 * Tests of the colour modes: the brandable-dark mixin on every version, its rules, the state of the modes.
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\colour_modes
 */
final class colour_modes_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config(parent_theme::SETTING, 'boost', 'theme_brandable');
        parent_theme::reset_caches();
    }

    #[\Override]
    protected function tearDown(): void {
        parent_theme::reset_caches();
        parent::tearDown();
    }

    /**
     * Validates SCSS chunks and returns the errors.
     *
     * @param array $chunks Target => content, or target => [content, scope].
     * @return array
     */
    private function errors(array $chunks): array {
        $changes = [];
        foreach ($chunks as $target => $chunk) {
            [$content, $scope] = is_array($chunk) ? $chunk : [$chunk, null];
            $changes[] = change::from_array(['type' => 'scss', 'target' => $target, 'content' => $content]
                + ($scope ? ['scope' => $scope] : []));
        }
        try {
            validator::validate($changes, resolver::empty_map());
        } catch (validation_exception $e) {
            return $e->errors;
        }
        return [];
    }

    public function test_mixin_compiles_in_the_theme_on_every_version(): void {
        $chunks = [
            'post/cards' => '.card, .block { color: #111; @include brandable-dark { color: #eee; } }'
                . ' @include brandable-dark { --brandable-test: 1; }',
            'post/dashboard' => ['.card { @media (min-width: 10px) { @include brandable-dark { color: red; } } }',
                ['level' => 'page', 'value' => 'bp-my-index']],
        ];
        $this->assertSame([], $this->errors($chunks));

        $changes = [];
        foreach ($chunks as $target => $chunk) {
            [$content, $scope] = is_array($chunk) ? $chunk : [$chunk, null];
            $changes[] = change::from_array(['type' => 'scss', 'target' => $target, 'content' => $content]
                + ($scope ? ['scope' => $scope] : []));
        }
        $records = validator::validate($changes, resolver::empty_map());
        $map = resolver::empty_map();
        $css = scss_compiler::compile(resolver::apply($map, $records, 0));
        $this->assertStringContainsString('[data-bs-theme="dark"] .card, [data-bs-theme="dark"] .block', $css);
        $this->assertMatchesRegularExpression('~\[data-bs-theme="dark"\]\s*\{\s*--brandable-test: 1;~', $css);
        $this->assertStringContainsString('[data-bs-theme="dark"] body.bp-my-index:not(#bp-s1) .card', $css);
        $this->assertTrue(colour_modes::has_dark_rules($css));
    }

    public function test_rules_of_the_mixin(): void {
        $errors = $this->errors(['pre/vars' => '@include brandable-dark { --x: 1; }']);
        $this->assertCount(1, $errors);
        $this->assertSame(get_string('error:darkinpre', 'theme_brandable'), $errors[0]['message']);

        $nested = '.a { @include brandable-dark { .b { @include brandable-dark { color: red; } } } }';
        $errors = $this->errors(['post/nested' => $nested]);
        $this->assertSame(get_string('error:darknested', 'theme_brandable'), $errors[0]['message'] ?? null);

        $errors = $this->errors(['post/root' => ':root { @include brandable-dark { --x: 1; } }']);
        $this->assertSame(get_string('error:darkroot', 'theme_brandable'), $errors[0]['message'] ?? null);
        $errors = $this->errors(['post/html' => 'html.x { @include brandable-dark { color: red; } }']);
        $this->assertSame(get_string('error:darkroot', 'theme_brandable'), $errors[0]['message'] ?? null);
    }

    public function test_check_selectors(): void {
        $this->assertNull(colour_modes::check_selectors('post/a', ['.a', '[data-bs-theme="dark"] .a', '[data-bs-theme=dark]']));
        $this->assertNotNull(colour_modes::check_selectors('pre/a', ['[data-bs-theme="dark"] .a']));
        $this->assertNull(colour_modes::check_selectors('pre/a', ['.a']));
    }

    public function test_mixin_is_the_one_of_the_runtime(): void {
        global $CFG;
        $runtime = file_get_contents($CFG->dirroot . '/theme/brandable/export/runtime/classes/brandable/scss.php');
        $this->assertStringContainsString(colour_modes::MIXIN, preg_replace("~\n {8}~", "\n", $runtime));
    }

    public function test_state_of_the_modes(): void {
        $available = class_exists('\theme_boost\colour_mode');
        $this->assertSame(['available' => $available, 'enabled' => false, 'default' => 'light'], colour_modes::to_context());
        if (!$available) {
            return;
        }
        set_config('enablecolourmodes', 1, 'theme_boost');
        set_config('defaultcolourmode', 'dark', 'theme_boost');
        $this->assertSame(['available' => true, 'enabled' => true, 'default' => 'dark'], colour_modes::to_context());
    }

    public function test_compatibility_finding(): void {
        $dark = ['.a { color: red; }', '.b { @include brandable-dark { color: #eee; } }'];
        if (class_exists('\\theme_boost\\colour_mode')) {
            $this->assertNull(colour_modes::compatibility_finding($dark));
            return;
        }
        $finding = colour_modes::compatibility_finding($dark);
        $this->assertSame('colour_modes_unavailable', $finding['code']);
        $this->assertSame('warning', $finding['severity']);
        $this->assertNull(colour_modes::compatibility_finding(['.a { color: red; }']));
    }
}
