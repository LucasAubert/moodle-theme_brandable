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

#[\PHPUnit\Framework\Attributes\CoversClass(file_checker::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(svg::class)]
/**
 * Tests for the checks of the files added to the library (spec A2, A3, A8).
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\assets\file_checker
 * @covers     \theme_brandable\local\assets\svg
 */
final class file_checker_test extends \advanced_testcase {
    /** @var string Folder of the test files. */
    private string $dir;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->dir = make_request_directory();
    }

    /**
     * Writes a test file.
     *
     * @param string $content Content.
     * @return string Path.
     */
    private function file(string $content): string {
        $path = $this->dir . '/' . uniqid('f', true);
        file_put_contents($path, $content);
        return $path;
    }

    /**
     * Makes an image with GD.
     *
     * @param int $width Width.
     * @param int $height Height.
     * @param string $type png, jpeg or gif.
     * @return string Content.
     */
    private function image(int $width, int $height, string $type = 'png'): string {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 30, 60));
        ob_start();
        match ($type) {
            'png' => imagepng($image),
            'jpeg' => imagejpeg($image),
            'gif' => imagegif($image),
        };
        return (string) ob_get_clean();
    }

    /**
     * Asserts that a check is refused with an error code.
     *
     * @param string $code Expected code (asset:error:*).
     * @param callable $check Check.
     */
    private function assert_refused(string $code, callable $check): void {
        try {
            $check();
            $this->fail("Expected $code");
        } catch (\moodle_exception $e) {
            $this->assertSame($code, $e->errorcode);
        }
    }

    public function test_types_are_read_from_the_content(): void {
        $this->assertSame('image/png', file_checker::sniff($this->image(4, 4)));
        $this->assertSame('image/jpeg', file_checker::sniff($this->image(4, 4, 'jpeg')));
        $this->assertSame('image/gif', file_checker::sniff($this->image(4, 4, 'gif')));
        $this->assertSame('image/svg+xml', file_checker::sniff('<?xml version="1.0"?><!-- logo --><svg viewBox="0 0 1 1"/>'));
        $this->assertSame('font/woff2', file_checker::sniff('wOF2' . str_repeat("\0", 40)));
        $this->assertNull(file_checker::sniff('<html><svg/></html>'));
        $this->assertNull(file_checker::sniff('%PDF-1.7'));
        $this->assert_refused('asset:error:type', fn() => file_checker::check_image($this->file('just text')));
        $this->assert_refused('asset:error:type', fn() => file_checker::check_image($this->file('wOF2' . str_repeat("\0", 40))));
        $this->assert_refused('asset:error:empty', fn() => file_checker::check_image($this->file('')));
    }

    public function test_raster_image_reencoded_and_reduced_by_the_server(): void {
        $checked = file_checker::check_image($this->file($this->image(3000, 1000)));
        $this->assertSame('image/png', $checked['mime']);
        $this->assertSame([2560, 853], [$checked['width'], $checked['height']]);
        $this->assertSame([2560, 853], array_slice(getimagesizefromstring($checked['files']['optimized']['content']), 0, 2));
        // The original is kept as uploaded.
        $this->assertSame(3000, getimagesizefromstring($checked['files']['original']['content'])[0]);
        $thumbnail = getimagesizefromstring($checked['files']['thumbnail']['content']);
        $this->assertSame(512, max($thumbnail[0], $thumbnail[1]));
        $this->assertSame(file_checker::gd_webp(), isset($checked['files']['webp']));
        $this->assertFalse($checked['favicononly']);
    }

    public function test_versions_prepared_by_the_editor(): void {
        $original = $this->file($this->image(3000, 1500, 'jpeg'));
        $checked = file_checker::check_image(
            $original,
            $this->file($this->image(2560, 1280, 'jpeg')),
            null,
            $this->file($this->image(512, 256, 'png'))
        );
        $this->assertSame([2560, 1280], [$checked['width'], $checked['height']]);
        $this->assertSame('image/png', $checked['files']['thumbnail']['mime']);
        // Orientation applied by the browser: width and height swapped.
        $checked = file_checker::check_image($original, $this->file($this->image(1280, 2560, 'jpeg')));
        $this->assertSame([1280, 2560], [$checked['width'], $checked['height']]);

        // Other type, other proportions, larger than the limit: refused.
        $this->assert_refused(
            'asset:error:version',
            fn() => file_checker::check_image($original, $this->file($this->image(2560, 1280, 'png')))
        );
        $this->assert_refused(
            'asset:error:version',
            fn() => file_checker::check_image($original, $this->file($this->image(2560, 2000, 'jpeg')))
        );
        $this->assert_refused(
            'asset:error:version',
            fn() => file_checker::check_image($original, $this->file($this->image(2600, 1300, 'jpeg')))
        );
        // Thumbnail too large, WebP version of another size.
        $this->assert_refused(
            'asset:error:thumbnail',
            fn() => file_checker::check_image($original, null, null, $this->file($this->image(600, 300)))
        );
        $this->assert_refused(
            'asset:error:thumbnail',
            fn() => file_checker::check_image($original, null, null, $this->file($this->image(10, 10, 'gif')))
        );
        $this->assert_refused(
            'asset:error:version',
            fn() => file_checker::check_image($original, null, $this->file($this->image(10, 10)))
        );
    }

    public function test_gif_kept_as_uploaded(): void {
        $gif = $this->image(300, 200, 'gif');
        $checked = file_checker::check_image($this->file($gif));
        $this->assertSame('image/gif', $checked['mime']);
        $this->assertSame($gif, $checked['files']['optimized']['content']);
        $this->assertArrayNotHasKey('webp', $checked['files']);
        $this->assertArrayHasKey('thumbnail', $checked['files']);
        $wide = $this->file($this->image(2600, 10, 'gif'));
        $this->assert_refused('asset:error:toolarge', fn() => file_checker::check_image($wide));
    }

    public function test_limits(): void {
        $big = $this->file("\x89PNG\r\n\x1a\n" . str_repeat("\0", file_checker::MAX_FILE));
        $this->assert_refused('asset:error:filesize', fn() => file_checker::check_image($big));
        $this->assert_refused('asset:error:corrupt', fn() => file_checker::check_image($this->file("\x89PNG\r\n\x1a\nbroken")));
    }

    public function test_svg_sanitised(): void {
        $dirty = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 120 40"'
            . ' onload="alert(1)"><script>alert(2)</script><foreignObject><p>x</p></foreignObject>'
            . '<image xlink:href="https://evil.example/x.png" width="10" height="10"/>'
            . '<a href="javascript:alert(3)"><rect width="10" height="10" fill="#c00"/></a></svg>';
        $checked = file_checker::check_image($this->file($dirty));
        $clean = $checked['files']['optimized']['content'];
        $this->assertSame('image/svg+xml', $checked['mime']);
        $this->assertSame([120, 40], [$checked['width'], $checked['height']]);
        foreach (['onload', 'script', 'foreignObject', 'evil.example', 'javascript:'] as $needle) {
            $this->assertStringNotContainsString($needle, $clean);
        }
        $this->assertStringContainsString('<rect', $clean);
        // The original is kept as uploaded, never served.
        $this->assertSame($dirty, $checked['files']['original']['content']);

        // Size from width and height when there is no viewBox; none at all: refused.
        $this->assertSame([64, 32], svg::size('<svg xmlns="http://www.w3.org/2000/svg" width="64px" height="32"/>'));
        $this->assert_refused(
            'asset:error:svg',
            fn() => file_checker::check_image($this->file('<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>'))
        );
        // Entities and DOCTYPE (XXE, billion laughs): refused before parsing.
        $xxe = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
            . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><text>&x;</text></svg>';
        $this->assert_refused('asset:error:svg', fn() => file_checker::check_image($this->file($xxe)));
    }

    public function test_fonts_and_licences(): void {
        $woff2 = 'wOF2' . str_repeat("\1", 100);
        $checked = file_checker::check_font($this->file($woff2), $this->file("SIL Open Font License 1.1\n"));
        $this->assertSame('font/woff2', $checked['mime']);
        $this->assertSame($woff2, $checked['files']['optimized']['content']);
        $this->assertSame("SIL Open Font License 1.1\n", $checked['files']['licence']['content']);
        $this->assert_refused('asset:error:font', fn() => file_checker::check_font($this->file("\0\1\0\0ttf")));
        $this->assert_refused('asset:error:licence', fn() => file_checker::check_font($this->file($woff2), $this->file("\0\xFF")));
    }
}
