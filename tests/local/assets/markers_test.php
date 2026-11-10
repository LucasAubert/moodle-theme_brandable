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

namespace theme_brandable\local\assets;

use theme_brandable\local\generator\generation_context;
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\context;
use theme_brandable\local\overrides\resolver;
use theme_brandable\local\overrides\revision_manager;
use theme_brandable\local\overrides\scss;
use theme_brandable\local\validation\validation_exception;
use theme_brandable\local\validation\validator;

#[\PHPUnit\Framework\Attributes\CoversClass(markers::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(settings::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\theme_brandable\output\core_renderer::class)]
/**
 * Tests for the markers of the library and the settings changes (spec A9, A10; contract 11.3, 11.4).
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\assets\markers
 * @covers     \theme_brandable\local\assets\settings
 * @covers     \theme_brandable\output\core_renderer
 */
final class markers_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $PAGE;

        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        markers::reset();
        $PAGE->set_url('/');
        $PAGE->set_context(\context_system::instance());
        $PAGE->force_theme('brandable');
    }

    /**
     * Adds an image to the library.
     *
     * @param string $slug Slug.
     * @param string $type png or ico.
     * @param int $width Width.
     * @param int $height Height.
     * @return \stdClass
     */
    private function image(string $slug, string $type = 'png', int $width = 40, int $height = 20): \stdClass {
        $path = make_request_directory() . '/image';
        if ($type === 'ico') {
            // One 16x16 entry, 32-bit BMP data.
            $bmp = pack('VVVvvVVVVVV', 40, 16, 32, 1, 32, 0, 1024 + 64, 0, 0, 0, 0) . str_repeat("\0", 1024 + 64);
            file_put_contents($path, pack('vvv', 0, 1, 1) . pack('CCCCvvVV', 16, 16, 0, 0, 1, 32, strlen($bmp), 22) . $bmp);
        } else {
            $gd = imagecreatetruecolor($width, $height);
            imagepng($gd, $path);
        }
        return library::add(file_checker::check_image($path), ['slug' => $slug]);
    }

    /**
     * Validates changes against the empty map, returns the error messages.
     *
     * @param array $changes Changes (contract format).
     * @return string[]
     */
    private function errors(array $changes): array {
        try {
            validator::validate(array_map(fn($data) => change::from_array($data), $changes), resolver::empty_map());
            return [];
        } catch (validation_exception $e) {
            return array_column($e->errors, 'message');
        }
    }

    public function test_markers_replaced_by_urls(): void {
        $campus = $this->image('campus-hiver');
        $url = library::url($campus)->out(false);

        $this->assertSame(
            ".a { background: url(\"$url\") center; } .b { background: url(\"data:,\"); }",
            markers::scss('.a { background: brandable-asset("campus-hiver") center; }'
                . ' .b { background: brandable-asset(\'gone\'); }')
        );
        $this->assertSame(
            '<img src="' . s($url) . '" alt=""><img src="">',
            markers::template('<img src="[[brandable-asset:campus-hiver]]" alt=""><img src="[[brandable-asset:gone]]">')
        );
        $this->assertSame(['gone'], markers::missing('scss', 'x { y: brandable-asset("gone") brandable-asset("campus-hiver"); }'));
        $this->assertSame([], markers::missing('setting', 'campus-hiver'));

        // A new asset changes the URLs read.
        $this->image('autre');
        $this->assertStringContainsString('/autre.png', markers::template('[[brandable-asset:autre]]'));
    }

    public function test_markers_in_the_rendering(): void {
        global $PAGE;
        $campus = $this->image('campus-hiver');
        $url = library::url($campus)->out(false);
        $revision = revision_manager::create([
            change::from_array(['type' => 'template', 'target' => 'core/loading',
                'content' => '<img src="[[brandable-asset:campus-hiver]]" alt="">']),
            change::from_array(['type' => 'scss', 'target' => 'post/login',
                'content' => '.login { background-image: brandable-asset("campus-hiver"); }']),
        ], 'p', 's');

        // Template rendered with the URL (PHP).
        $html = context::with_revision($revision, fn() => $PAGE->get_renderer('core')->render_from_template('core/loading', []));
        $this->assertSame('<img src="' . s($url) . '" alt="">', $html);
        // SCSS of the theme with the URL; the revision keeps the marker.
        $this->assertStringContainsString("url(\"$url\")", context::with_revision($revision, fn() => scss::get_code('post')));
        $this->assertStringContainsString(
            'brandable-asset("campus-hiver")',
            resolver::get_map($revision)[change::TYPE_SCSS]['post/login']['content']
        );
        // Templates rendered in JavaScript: the contents served carry the URL.
        $this->setAdminUser();
        $result = \theme_brandable\external\get_template_overrides::execute($revision);
        $this->assertSame('<img src="' . s($url) . '" alt="">', $result['templates'][0]['content']);
    }

    public function test_validation_of_markers_and_settings(): void {
        $this->image('logo');
        $this->image('icone', 'ico');
        $path = make_request_directory() . '/font';
        file_put_contents($path, 'wOF2' . str_repeat("\1", 40));
        library::add(file_checker::check_font($path), ['slug' => 'marque', 'family' => 'Marque', 'weight' => 400,
            'style' => 'normal']);

        $this->assertSame([], $this->errors([
            ['type' => 'setting', 'target' => 'logo', 'content' => 'logo'],
            ['type' => 'setting', 'target' => 'logocompact', 'content' => 'logo'],
            ['type' => 'setting', 'target' => 'favicon', 'content' => 'icone'],
            ['type' => 'scss', 'target' => 'post/x', 'content' => '.x { background: brandable-asset("logo"); }'],
            ['type' => 'template', 'target' => 'core/loading', 'content' => '<img src="[[brandable-asset:logo]]" alt="">'],
        ]));
        $error = fn(string $key, $a = null) => get_string($key, 'theme_brandable', $a);
        $this->assertSame(
            [$error('error:settingtarget')],
            $this->errors([['type' => 'setting', 'target' => 'backgroundimage', 'content' => 'logo']])
        );
        $this->assertSame([$error('error:settingscope')], $this->errors([['type' => 'setting', 'target' => 'logo',
            'content' => 'logo', 'scope' => ['level' => 'pagetype', 'value' => 'login-index']]]));
        $this->assertSame(
            [$error('error:settingasset', 'nothing')],
            $this->errors([['type' => 'setting', 'target' => 'logo', 'content' => 'nothing']])
        );
        $this->assertSame(
            [$error('error:settingasset', 'marque')],
            $this->errors([['type' => 'setting', 'target' => 'logo', 'content' => 'marque']])
        );
        // Icons only for the favicon.
        $this->assertSame(
            [$error('error:settingtype', 'icone')],
            $this->errors([['type' => 'setting', 'target' => 'logo', 'content' => 'icone']])
        );
        // Marker inside url(), malformed markers.
        foreach (
            [
                ['type' => 'scss', 'target' => 'post/x', 'content' => '.x { background: url(brandable-asset("logo")); }'],
                ['type' => 'scss', 'target' => 'post/x', 'content' => '.x { background: brandable-asset(logo); }'],
                ['type' => 'template', 'target' => 'core/loading', 'content' => '<img src="[[brandable-asset: logo]]">'],
            ] as $change
        ) {
            $this->assertSame([$error('error:assetmarker')], $this->errors([$change]));
        }
    }

    public function test_settings_in_preview_then_published(): void {
        global $PAGE;
        $logo = $this->image('logo-universite', 'png', 1000, 400);
        $icon = $this->image('icone', 'ico');
        $renderer = new \theme_brandable\output\core_renderer($PAGE, RENDERER_TARGET_GENERAL);
        $this->assertFalse($renderer->get_logo_url());

        $draft = revision_manager::create([
            change::from_array(['type' => 'setting', 'target' => 'logo', 'content' => 'logo-universite']),
            change::from_array(['type' => 'setting', 'target' => 'logocompact', 'content' => 'logo-universite']),
            change::from_array(['type' => 'setting', 'target' => 'favicon', 'content' => 'icone']),
        ], 'p', 's');
        // Draft only: the published site keeps Moodle's settings; the preview shows the library.
        $this->assertFalse($renderer->get_logo_url());
        $this->assertStringNotContainsString('theme_brandable/asset', $renderer->favicon()->out(false));
        $this->assertStringContainsString(
            '/theme_brandable/asset/' . $logo->id . '/',
            context::with_revision($draft, fn() => $renderer->get_logo_url()->out(false))
        );

        // Sent to Brandable with the draft overrides.
        $overrides = generation_context::export_overrides(resolver::get_map($draft));
        $this->assertContains(['type' => 'setting', 'target' => 'logo', 'content' => 'logo-universite',
            'scope' => ['level' => 'site', 'value' => null]], $overrides);

        revision_manager::publish();
        $this->assertStringContainsString(
            '/' . $logo->id . '/' . substr($logo->contenthash, 0, 12) . '/0x200/',
            $renderer->get_logo_url()->out(false)
        );
        $this->assertStringContainsString('/300x300/', $renderer->get_compact_logo_url()->out(false));
        $this->assertStringContainsString('/' . $icon->id . '/', $renderer->favicon()->out(false));
        $this->assertTrue($renderer->should_display_navbar_logo());
        // A size core does not ask for: the file as it is.
        $this->assertStringNotContainsString('x', basename(dirname($renderer->get_logo_url(10, 10)->out(false))));
        // Nothing written in core_admin.
        $this->assertEmpty(get_config('core_admin', 'logo'));

        // Moodle's settings again.
        revision_manager::create([change::from_array(['type' => 'setting', 'target' => 'logo', 'deleted' => true])], 'p', 's');
        revision_manager::publish();
        $this->assertFalse($renderer->get_logo_url());
    }

    public function test_logo_reduced_once(): void {
        $logo = $this->image('logo-universite', 'png', 1000, 400);
        $file = library::get_file($logo, 'optimized');
        $reduced = server::resized($logo, $file, '0x200');
        $this->assertSame([500, 200], array_slice(getimagesizefromstring($reduced->get_content()), 0, 2));
        $this->assertEquals($reduced->get_id(), server::resized($logo, $file, '0x200')->get_id());
        // Already small enough: the file itself.
        $small = $this->image('petit', 'png', 100, 40);
        $smallfile = library::get_file($small, 'optimized');
        $this->assertSame($smallfile, server::resized($small, $smallfile, '0x200'));
    }
}
