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

use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\revision_manager;

#[\PHPUnit\Framework\Attributes\CoversClass(library::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(server::class)]
/**
 * Tests for the library of images and fonts (spec A1, A4, A5, A6).
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\assets\library
 * @covers     \theme_brandable\local\assets\server
 */
final class library_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Adds a PNG image to the library.
     *
     * @param string $filename Name of the uploaded file.
     * @param array $meta Other description.
     * @return \stdClass
     */
    private function add_image(string $filename = 'Campus hiver.png', array $meta = []): \stdClass {
        $image = imagecreatetruecolor(40, 20);
        ob_start();
        imagepng($image);
        $path = make_request_directory() . '/image';
        file_put_contents($path, ob_get_clean());
        return library::add(file_checker::check_image($path), ['filename' => $filename] + $meta);
    }

    /**
     * Asserts that an operation is refused with an error code.
     *
     * @param string $code Expected code.
     * @param callable $operation Operation.
     */
    private function assert_refused(string $code, callable $operation): void {
        try {
            $operation();
            $this->fail("Expected $code");
        } catch (\moodle_exception $e) {
            $this->assertSame($code, $e->errorcode);
        }
    }

    public function test_add_and_serve(): void {
        $revision = library::get_revision();
        $asset = $this->add_image('Campus hiver.png', ['alt' => "  Le campus\n sous la neige "]);
        $this->assertSame('campus-hiver', $asset->slug);
        $this->assertSame('Le campus sous la neige', $asset->alt);
        $this->assertSame([40, 20], [(int) $asset->width, (int) $asset->height]);
        $this->assertGreaterThan($revision, library::get_revision());
        // Free slugs.
        $this->assertSame('campus-hiver-2', $this->add_image('campus_hiver.PNG')->slug);
        $this->assertSame('image', library::suggest_slug('***.png'));

        // Public URL with the hash of the content; the original is stored, never served.
        $url = library::url($asset)->out(false);
        $this->assertStringContainsString('/theme_brandable/asset/' . $asset->id . '/' . substr($asset->contenthash, 0, 12)
            . '/campus-hiver.png', $url);
        $this->assertNotNull(library::get_file($asset, 'original'));
        $this->assertSame($asset->contenthash, library::get_file($asset, 'optimized')->get_contenthash());
        $this->assertNotNull(library::thumbnail_url($asset));

        // Fonts: description checked.
        $path = make_request_directory() . '/font';
        file_put_contents($path, 'wOF2' . str_repeat("\1", 64));
        $font = library::add(file_checker::check_font($path), ['filename' => 'Marque-Bold.woff2', 'family' => 'Marque',
            'weight' => 700, 'style' => 'normal']);
        $this->assertSame(['font', 'marque-bold', 'Marque', 700], [$font->kind, $font->slug, $font->family, (int) $font->weight]);
        $this->assert_refused('asset:error:fontmeta', fn() => library::add(
            file_checker::check_font($path),
            ['filename' => 'x.woff2', 'family' => 'Marque', 'weight' => 1200, 'style' => 'normal']
        ));
        $this->assert_refused('asset:error:slug', fn() => $this->add_image('x.png', ['slug' => 'Not A Slug']));
        $this->assert_refused('asset:error:slugtaken', fn() => $this->add_image('x.png', ['slug' => 'campus-hiver']));
    }

    public function test_usage_protects_slug_and_deletion(): void {
        $logo = $this->add_image('logo.png');
        $background = $this->add_image('fond.png');
        $unused = $this->add_image('autre.png');

        // Session: a revision using the background.
        revision_manager::create([change::from_array(['type' => 'scss', 'target' => 'post/login',
            'content' => '.login { background-image: brandable-asset("fond"); }'])], 'p', 's');
        $this->assertSame(['fond' => ['session']], library::get_usage());
        $this->assert_refused('asset:error:slugused', fn() => library::update((int) $background->id, ['slug' => 'other']));
        $this->assert_refused('asset:error:used', fn() => library::delete((int) $background->id));

        // Published, then previous version: still protected.
        revision_manager::publish('v1');
        $this->assertSame(['fond' => ['published']], library::get_usage());
        revision_manager::create([change::from_array(['type' => 'template', 'target' => 'core/loading',
            'content' => '<img src="[[brandable-asset:logo]]" alt="">'])], 'p', 's');
        revision_manager::create(
            [change::from_array(['type' => 'scss', 'target' => 'post/login', 'deleted' => true])],
            'p',
            's'
        );
        revision_manager::publish('v2');
        $this->assertEquals(['logo' => ['published'], 'fond' => ['previous']], library::get_usage());
        $this->assert_refused('asset:error:used', fn() => library::delete((int) $background->id));

        // Unused: the slug changes (files renamed), the asset can be deleted.
        $updated = library::update((int) $unused->id, ['slug' => 'nouveau-nom', 'alt' => 'Texte']);
        $this->assertSame('nouveau-nom', $updated->slug);
        $this->assertStringEndsWith('/nouveau-nom.png', library::url($updated)->out(false));
        $this->assertSame('nouveau-nom.png', library::get_file($updated, 'original')->get_filename());
        library::delete((int) $unused->id);
        $this->assertNull(library::get((int) $unused->id));
        $this->assertNull(library::get_file($updated, 'original'));
        $this->assertEqualsCanonicalizing(['fond', 'logo'], array_keys(library::get_all()));
    }

    public function test_slugs_in_contents(): void {
        $this->assertSame(['campus', 'logo-2'], library::slugs_in(
            'scss',
            "a { background: brandable-asset(\"campus\"); } b { src: brandable-asset( 'logo-2' ) format(\"woff2\"); }"
            . ' c { x: brandable-asset("Bad"); } d { x: brandable-asset("campus"); }'
        ));
        $this->assertSame(['logo'], library::slugs_in('template', '<img src="[[brandable-asset:logo]]"> [[brandable-asset:]]'));
        $this->assertSame(['logo'], library::slugs_in('setting', 'logo'));
        $this->assertSame([], library::slugs_in('setting', ''));
    }

    public function test_library_is_limited(): void {
        global $DB;
        $records = [];
        for ($i = 0; $i < library::MAX_ASSETS; $i++) {
            $records[] = ['slug' => 'a' . $i, 'kind' => 'image', 'mime' => 'image/png', 'contenthash' => sha1((string) $i)];
        }
        $DB->insert_records(library::TABLE, $records);
        $this->assert_refused('asset:error:full', fn() => $this->add_image());
    }

    public function test_webp_negotiation(): void {
        $this->assertTrue(server::accepts_webp('image/avif,image/webp,image/apng,image/*,*/*;q=0.8'));
        $this->assertTrue(server::accepts_webp('image/webp;q=0.5'));
        $this->assertFalse(server::accepts_webp('image/webp;q=0, image/png'));
        $this->assertFalse(server::accepts_webp('image/png,image/*;q=0.8'));
        $this->assertFalse(server::accepts_webp(''));
    }
}
