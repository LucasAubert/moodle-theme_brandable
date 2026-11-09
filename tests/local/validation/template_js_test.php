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
 * Tests for the refusal of executable code added by template overrides.
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\validation\template_js
 * @covers     \theme_brandable\local\validation\validator
 */
#[\PHPUnit\Framework\Attributes\CoversClass(template_js::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(validator::class)]
final class template_js_test extends \advanced_testcase {
    /** @var string Original used by the unit tests. */
    private const ORIGINAL = '<div class="menu" onclick="toggle()">{{> core/x}}<a href="javascript:void(0)">{{name}}</a></div>'
        . "<script>var a = 1;</script>\n{{#js}}\n    require(['core/x'], function(X) { X.init(); });\n{{/js}}";

    public function test_code_of_the_original_can_be_kept(): void {
        $this->assertSame([], template_js::find_added(self::ORIGINAL, self::ORIGINAL));
        // Whitespace changes and new markup without code are fine.
        $source = str_replace("\n    require", ' require', self::ORIGINAL) . '<p class="x">{{#str}} ok, core {{/str}}</p>';
        $this->assertSame([], template_js::find_added(self::ORIGINAL, $source));
        // Removing code is fine.
        $this->assertSame([], template_js::find_added(self::ORIGINAL, '<div class="menu"></div>'));
    }

    public function test_added_or_changed_js_blocks_are_found(): void {
        $this->assertSame(['{{#js}} block'], template_js::find_added(self::ORIGINAL, self::ORIGINAL . '{{#js}} alert(1); {{/js}}'));
        $changed = str_replace('X.init();', 'X.init(); document.body.remove();', self::ORIGINAL);
        $this->assertSame(['{{#js}} block'], template_js::find_added(self::ORIGINAL, $changed));
        // A delimiter change does not hide a block.
        $this->assertSame(['{{#js}} block'], template_js::find_added(null, '{{=<% %>=}}<%#js%> alert(1); <%/js%>'));
        // Nor does nesting it in another section.
        $this->assertSame(['{{#js}} block'], template_js::find_added(null, '{{#items}}{{#js}} alert(1); {{/js}}{{/items}}'));
    }

    public function test_added_scripts_are_found(): void {
        $script = self::ORIGINAL . '<SCRIPT src="x.js"></SCRIPT>';
        $this->assertSame(['<script> element'], template_js::find_added(self::ORIGINAL, $script));
        $this->assertSame(['<script> element'], template_js::find_added(null, '<script>alert({{n}})'));
        // In a Mustache comment, nothing is output.
        $this->assertSame([], template_js::find_added(null, '{{! <script>alert(1)</script> }}<p></p>'));
    }

    public function test_added_event_attributes_are_found(): void {
        $this->assertSame(['onerror attribute'], template_js::find_added(null, '<img src="{{url}}" onerror="alert(1)">'));
        $this->assertSame(['onload attribute'], template_js::find_added(null, '<body {{attrs}} onload=go()>'));
        $this->assertSame(['onmouseover attribute'], template_js::find_added(null, '<a href="#"onmouseover="x()">a</a>'));
        // The original's handler, unchanged, stays; a changed one does not.
        $this->assertSame(['onclick attribute'], template_js::find_added(
            self::ORIGINAL,
            str_replace('toggle()', 'steal()', self::ORIGINAL)
        ));
        // Not event handlers.
        $this->assertSame([], template_js::find_added(null, '<a data-onclick="x" title="onclick=x()">online</a> onclick=1'));
    }

    public function test_added_javascript_urls_are_found(): void {
        $this->assertSame(['javascript: URL in href'], template_js::find_added(null, '<a href=" JavaScript:alert(1)">x</a>'));
        $iframe = '<iframe src="&#106;avascript:alert(1)"></iframe>';
        $this->assertSame(['javascript: URL in src'], template_js::find_added(null, $iframe));
        $this->assertSame(['javascript: URL in formaction'], template_js::find_added(
            null,
            "<button formaction='java\tscript:x()'>b</button>"
        ));
        $this->assertSame([], template_js::find_added(null, '<a href="{{url}}">javascript: tips</a><a href="/javascript:x">y</a>'));
        $this->assertSame([], template_js::find_added(self::ORIGINAL, self::ORIGINAL));
    }

    public function test_validation_refuses_the_override_in_english(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();
        $USER->lang = 'fr';
        $original = template_source::get_original('core/user_menu');
        $this->assertNotNull($original);
        $this->assertStringContainsString('{{#js}}', $original);

        $override = fn(string $content) => change::from_array(['type' => 'template', 'target' => 'core/user_menu',
            'content' => $content, 'sourceHash' => template_source::hash($original)]);
        // Same JS as the original: accepted.
        validator::validate([$override('<div class="brandable-wrap">' . $original . '</div>')], resolver::empty_map());

        try {
            validator::validate(
                [$override($original . "{{#js}}\nrequire(['jquery'], function($) {});\n{{/js}}")],
                resolver::empty_map()
            );
            $this->fail('An added {{#js}} block must be refused');
        } catch (validation_exception $e) {
            $this->assertSame('core/user_menu', $e->errors[0]['target']);
            $this->assertSame('JavaScript is not allowed in template overrides; use CSS or template structure '
                . '(added: {{#js}} block).', $e->errors[0]['message']);
        }

        // A new template has no original: no code at all.
        try {
            validator::validate([change::from_array(['type' => 'template', 'target' => 'theme_brandable/widget',
                'content' => '<a href="#" onclick="x()">w</a>'])], resolver::empty_map());
            $this->fail('An event handler in a new template must be refused');
        } catch (validation_exception $e) {
            $this->assertStringContainsString('onclick attribute', $e->errors[0]['message']);
        }
    }
}
