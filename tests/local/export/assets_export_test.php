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

namespace theme_brandable\local\export;

use theme_brandable\local\assets\file_checker;
use theme_brandable\local\assets\library;
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\resolver;
use theme_brandable\local\overrides\revision_manager;
use theme_brandablert\brandable\assets as runtime_assets;
use theme_brandablert\brandable\manifest as runtime_manifest;

/**
 * Tests for the images and fonts of an exported theme, and their import (spec A13, A14; contract 11.6).
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\export\exporter
 * @covers     \theme_brandable\local\export\importer
 */
#[\PHPUnit\Framework\Attributes\CoversClass(exporter::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(importer::class)]
final class assets_export_test extends \advanced_testcase {
    #[\Override]
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        $dir = exporter::runtime_dir() . '/classes/brandable';
        foreach (['manifest', 'assets'] as $class) {
            require_once("$dir/$class.php");
        }
    }

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    #[\Override]
    protected function tearDown(): void {
        runtime_manifest::reset();
        parent::tearDown();
    }

    /**
     * Adds a PNG image.
     *
     * @param string $slug Slug.
     * @param int $width Width.
     * @param int $height Height.
     * @param int $red Colour (different contents).
     * @return \stdClass
     */
    private function image(string $slug, int $width = 40, int $height = 20, int $red = 0): \stdClass {
        $path = make_request_directory() . '/image';
        $gd = imagecreatetruecolor($width, $height);
        imagefill($gd, 0, 0, imagecolorallocate($gd, $red, 80, 160));
        imagepng($gd, $path);
        return library::add(file_checker::check_image($path), ['slug' => $slug, 'alt' => "Image $slug"]);
    }

    /**
     * Publishes a version using a logo, a background, a template image and a font of the catalogue, then exports it.
     *
     * @return array [folder of the theme, zip]
     */
    private function export(): array {
        $this->image('logo-test', 1000, 400);
        $this->image('campus');
        $this->image('unused');
        $path = make_request_directory() . '/font';
        file_put_contents($path, 'wOF2' . str_repeat("\1", 60));
        library::add(file_checker::check_font($path), ['slug' => 'font-montserrat-latin-700-normal', 'family' => 'Montserrat',
            'weight' => 700, 'style' => 'normal', 'subset' => 'latin', 'origin' => 'google', 'licence' => 'OFL-1.1']);
        library::set_family_licence('Montserrat', 'OFL-1.1', "Copyright The Montserrat Project Authors\nSIL OFL 1.1");
        revision_manager::create([
            change::from_array(['type' => 'setting', 'target' => 'logo', 'content' => 'logo-test']),
            change::from_array(['type' => 'setting', 'target' => 'logocompact', 'content' => 'logo-test']),
            change::from_array(['type' => 'template', 'target' => 'core/loading',
                'content' => '<img src="[[brandable-asset:campus]]" alt="">']),
            change::from_array(['type' => 'scss', 'target' => 'post/background',
                'content' => 'body { background: brandable-asset("campus"); }']),
            change::from_array(['type' => 'scss', 'target' => 'post/font-montserrat',
                'content' => '@font-face { font-family: "Montserrat"; src: brandable-asset("font-montserrat-latin-700-normal")'
                    . ' format("woff2"); }']),
        ], 'p', 's');
        revision_manager::publish('Logo');
        $zip = make_request_directory() . '/acme.zip';
        (new exporter('acme', date('Ymd') . '00', 'Logo', licence::test()))->build($zip);
        $dir = make_request_directory();
        get_file_packer('application/zip')->extract_to_pathname($zip, $dir);
        return ["$dir/acme", $zip];
    }

    public function test_export_with_assets(): void {
        [$dir] = $this->export();
        $manifest = json_decode(file_get_contents("$dir/brandable/manifest.json"), true);
        $assets = array_column($manifest['assets'], null, 'slug');

        // Only what the version uses.
        $this->assertEqualsCanonicalizing(['logo-test', 'campus', 'font-montserrat-latin-700-normal'], array_keys($assets));
        $this->assertSame(['logo' => 'logo-test', 'logocompact' => 'logo-test'], $manifest['settings']);
        $campus = library::get_all()['campus'];
        $this->assertSame(substr($campus->contenthash, 0, 12), $assets['campus']['hash']);
        $served = library::get_file($campus, 'optimized')->get_content();
        $this->assertSame($served, file_get_contents("$dir/brandable/assets/campus.png"));
        // Logos reduced to the sizes core asks for.
        $this->assertSame(['0x200', '300x300', '100x100'], array_keys($assets['logo-test']['sizes']));
        $this->assertSame([500, 200], array_slice(getimagesize("$dir/brandable/assets/logo-test.0x200.png"), 0, 2));
        // Font and the licence of its family next to it.
        $this->assertFileExists("$dir/brandable/assets/font-montserrat-latin-700-normal.woff2");
        $this->assertStringContainsString(
            'Montserrat Project Authors',
            file_get_contents("$dir/" . $assets['font-montserrat-latin-700-normal']['licencefile'])
        );

        // Templates: URLs of the theme (root of the site for PHP and JavaScript rendering); SCSS: markers for the runtime.
        $this->assertSame(
            '<img src="' . exporter::WWWROOT . '/theme/acme/asset.php/' . $assets['campus']['hash'] . '/campus.png" alt="">',
            file_get_contents("$dir/templates/core/loading.mustache")
        );
        $this->assertStringContainsString('brandable-asset("campus")', file_get_contents("$dir/brandable/scss/1.scss"));
        $this->assertFileExists("$dir/asset.php");
        $this->assertFileExists("$dir/classes/output/core_renderer.php");

        // Runtime: URLs of the theme (here the runtime name, replaced at the export), SCSS and settings.
        runtime_manifest::reset($manifest);
        $hash = $assets['campus']['hash'];
        $this->assertStringEndsWith("/theme/brandablert/asset.php/$hash/campus.png", runtime_assets::url('campus')->out(false));
        $this->assertSame(
            'body { background: url("' . runtime_assets::url('campus')->out(false) . '"); } x { y: url("data:,"); }',
            runtime_assets::scss('body { background: brandable-asset("campus"); } x { y: brandable-asset("gone"); }')
        );
        $this->assertStringEndsWith('/logo-test.0x200.png', runtime_assets::setting('logo', null, 200)->out(false));
        $this->assertStringEndsWith('/logo-test.png', runtime_assets::setting('logo', 50, 50)->out(false));
        $this->assertNull(runtime_assets::setting('favicon'));
    }

    public function test_import_with_assets(): void {
        [, $zip] = $this->export();

        // Same files already in the library: reused, nothing added.
        $read = importer::read($zip);
        $this->assertCount(3, $read['assets']);
        $contents = implode(' ', array_map(fn($change) => (string) $change->content, $read['changes']));
        $this->assertStringContainsString('<img src="[[brandable-asset:campus]]"', $contents);
        $count = count(library::get_all());
        $changes = importer::apply_assets($read);
        $this->assertCount($count, library::get_all());

        // Another site: "campus" is another image there, the font is missing.
        foreach (['campus', 'font-montserrat-latin-700-normal', 'unused'] as $slug) {
            $asset = library::get_all()[$slug];
            $GLOBALS['DB']->delete_records(library::TABLE, ['id' => $asset->id]);
        }
        $GLOBALS['DB']->delete_records(library::FAMILY_TABLE);
        $this->image('campus', 40, 20, 255);
        $read = importer::read($zip);
        $changes = importer::apply_assets($read);
        $library = library::get_all();
        $this->assertArrayHasKey('campus-2', $library);
        $this->assertSame('Montserrat', $library['font-montserrat-latin-700-normal']->family);
        $this->assertStringContainsString('Montserrat Project Authors', library::get_family('Montserrat')->licencetext);

        revision_manager::import($changes, 'Import');
        $map = resolver::get_map(revision_manager::get_published());
        $template = $map[change::TYPE_TEMPLATE]['core/loading']['content'];
        $this->assertSame('<img src="[[brandable-asset:campus-2]]" alt="">', $template);
        $this->assertSame(
            'body { background: brandable-asset("campus-2"); }',
            $map[change::TYPE_SCSS]['post/background']['content']
        );
        $this->assertSame('logo-test', $map[change::TYPE_SETTING]['logo']['content']);
    }
}
