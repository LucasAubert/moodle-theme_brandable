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

#[\PHPUnit\Framework\Attributes\CoversClass(brand::class)]
/**
 * Tests of the styles of the pages of the plugin (spec docs/SPEC-brand-editor.md): scope, colours, loading.
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\brand
 */
final class brand_test extends \advanced_testcase {
    /** @var string Pattern of a colour literal. */
    private const COLOUR = '~#[0-9a-fA-F]{3,8}\b|\b(?:rgba?|hsla?)\(~';

    /**
     * The rules of the style sheet, comments removed: [[selectors, body], ...], at-rules flattened.
     *
     * @return array
     */
    private function rules(): array {
        global $CFG;
        $css = file_get_contents($CFG->dirroot . '/theme/brandable' . brand::STYLES);
        $css = preg_replace('~/\*.*?\*/~s', '', $css);
        // Bodies of @media: their rules are read like the others; @keyframes are skipped.
        $css = preg_replace('~@keyframes\s+[\w-]+\s*\{(?:[^{}]*\{[^{}]*\})*\s*\}~', '', $css);
        $css = preg_replace('~@media[^{]*\{((?:[^{}]*\{[^{}]*\})*)\s*\}~', '$1', $css);
        preg_match_all('~([^{}]+)\{([^{}]*)\}~', $css, $matches, PREG_SET_ORDER);
        return array_map(fn($match) => [array_map('trim', explode(',', trim($match[1]))), $match[2]], $matches);
    }

    public function test_every_selector_is_scoped(): void {
        $rules = $this->rules();
        $this->assertGreaterThan(100, count($rules));
        $scope = '~^(?:(?:\[data-bs-theme="dark"\] )?\.tbe(?:-page)?|body\.theme-brandable-editor-page)(?=$|[\s.:\[>])~';
        foreach ($rules as [$selectors]) {
            foreach ($selectors as $selector) {
                $this->assertMatchesRegularExpression($scope, $selector, "Selector out of the pages of the plugin: $selector");
            }
        }
    }

    public function test_colours_only_in_the_definitions(): void {
        $rules = $this->rules();
        foreach ($rules as $i => [$selectors, $body]) {
            if ($i < 2) {
                // Definitions: the structure (variables of Moodle, fallback of 4.5) and the brand, light then dark.
                $this->assertStringContainsString('--bd-', $body);
                continue;
            }
            $this->assertDoesNotMatchRegularExpression(self::COLOUR, $body, 'Colour literal in ' . implode(', ', $selectors));
            $this->assertStringNotContainsString('!important', $body);
            $this->assertStringNotContainsString('@import', $body);
        }
        // The dark values of the brand.
        $this->assertSame(['[data-bs-theme="dark"] .tbe', '[data-bs-theme="dark"] .tbe-page'], $rules[1][0]);
    }

    public function test_templates_have_no_styles_of_their_own(): void {
        global $CFG;
        foreach (glob($CFG->dirroot . '/theme/brandable/templates/*.mustache') as $file) {
            $content = file_get_contents($file);
            // Only the preview banner, shown on the pages of the site, keeps its own style.
            if (basename($file) !== 'preview_banner.mustache') {
                $this->assertStringNotContainsString('<style', $content, basename($file));
            }
            $this->assertDoesNotMatchRegularExpression('~style="[^"]*(#[0-9a-fA-F]{3}|rgb)~', $content, basename($file));
        }
    }

    public function test_styles_are_required_by_the_pages(): void {
        global $PAGE, $CFG;
        $this->resetAfterTest();
        $PAGE->set_url('/theme/brandable/editor.php');
        brand::require_styles();
        // No getter for the style sheets required by the page.
        $urls = array_map(fn($url) => $url->out(false), (new \ReflectionProperty($PAGE->requires, 'cssurls'))
            ->getValue($PAGE->requires));
        $this->assertTrue((bool) preg_grep('~/theme/brandable/styles/editor\.css\?v=\d+$~', $urls), implode("\n", $urls));
        foreach (['editor.php', 'connect.php', 'export.php', 'import.php', 'jsconsent.php'] as $page) {
            $code = file_get_contents($CFG->dirroot . '/theme/brandable/' . $page);
            $this->assertStringContainsString('brand::require_styles()', $code, $page);
        }
    }

    public function test_mark(): void {
        $mark = brand::mark();
        $this->assertStringStartsWith('<svg class="tbe-mark"', $mark);
        $this->assertStringContainsString('aria-hidden="true"', $mark);
        $this->assertDoesNotMatchRegularExpression(self::COLOUR, $mark);
    }
}
