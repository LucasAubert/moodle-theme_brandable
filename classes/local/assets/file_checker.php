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

use moodle_exception;

/**
 * Checks the files of an asset before they enter the library (spec A2, A3, A8).
 *
 * The editor prepares the versions (optimised, WebP, thumbnail) in the browser; nothing it sends is trusted: the
 * type is read from the magic bytes, the dimensions from the files, and every limit is checked again. When a version
 * is missing, GD makes it if it can (re-encoding also drops the metadata of the original), else the asset goes without
 * it (no WebP, no thumbnail).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class file_checker {
    /** @var int Largest file accepted (original, font). */
    public const MAX_FILE = 10 * 1024 * 1024;

    /** @var int Largest side of a raster image served (contract 11.2). */
    public const MAX_SIDE = 2560;

    /** @var int Largest number of pixels of an original (decompression bombs). */
    public const MAX_PIXELS = 40000000;

    /** @var int Largest side of a thumbnail. */
    public const THUMBNAIL_SIDE = 512;

    /** @var int Largest thumbnail (bytes). */
    public const THUMBNAIL_BYTES = 400 * 1024;

    /** @var int Largest licence file of a font. */
    public const MAX_LICENCE = 1024 * 1024;

    /** @var string[] Extension of each type served. */
    public const EXTENSIONS = [
        'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp',
        'image/svg+xml' => 'svg', 'image/vnd.microsoft.icon' => 'ico', 'font/woff2' => 'woff2',
    ];

    /** @var string[] Types the editor re-encodes (resized beyond MAX_SIDE, metadata dropped). */
    private const REENCODED = ['image/png', 'image/jpeg', 'image/webp'];

    /**
     * Checks an image and its versions.
     *
     * @param string $original Path of the original.
     * @param string|null $optimized Path of the version prepared by the editor (re-encoded, resized), if any.
     * @param string|null $webp Path of its WebP version, if any.
     * @param string|null $thumbnail Path of the thumbnail, if any.
     * @return array ['mime', 'width', 'height', 'favicononly', 'files' => [role => ['content', 'mime']]], the roles
     *               being original, optimized (the file served), webp and thumbnail.
     * @throws moodle_exception asset:error:* when a file is refused.
     */
    public static function check_image(
        string $original,
        ?string $optimized = null,
        ?string $webp = null,
        ?string $thumbnail = null
    ): array {
        $content = self::read($original, self::MAX_FILE);
        $mime = self::sniff($content);
        if ($mime === null || $mime === 'font/woff2') {
            throw new moodle_exception('asset:error:type', 'theme_brandable');
        }
        $files = ['original' => ['content' => $content, 'mime' => $mime]];
        $favicononly = $mime === 'image/vnd.microsoft.icon';

        if ($mime === 'image/svg+xml') {
            $clean = svg::sanitize($content);
            if ($clean === null) {
                throw new moodle_exception('asset:error:svg', 'theme_brandable');
            }
            [$width, $height] = svg::size($clean);
            $files['optimized'] = ['content' => $clean, 'mime' => $mime];
        } else {
            [$width, $height] = self::dimensions($content, $mime);
            if ($width * $height > self::MAX_PIXELS) {
                throw new moodle_exception('asset:error:pixels', 'theme_brandable');
            }
            if (in_array($mime, self::REENCODED, true)) {
                $served = $optimized !== null ? self::check_optimized($optimized, $mime, $width, $height)
                    : self::reencode($content, $mime, $width, $height);
            } else {
                // GIF (animations kept) and icons: served as uploaded.
                if (max($width, $height) > self::MAX_SIDE) {
                    throw new moodle_exception('asset:error:toolarge', 'theme_brandable', '', self::MAX_SIDE);
                }
                $served = ['content' => $content, 'mime' => $mime, 'width' => $width, 'height' => $height];
            }
            [$width, $height] = [$served['width'], $served['height']];
            $files['optimized'] = ['content' => $served['content'], 'mime' => $mime];
            if ($mime !== 'image/webp' && in_array($mime, self::REENCODED, true)) {
                $version = $webp !== null ? self::check_webp($webp, $width, $height) : self::make_webp($served['content']);
                if ($version !== null) {
                    $files['webp'] = ['content' => $version, 'mime' => 'image/webp'];
                }
            }
        }

        $small = $thumbnail !== null ? self::check_thumbnail($thumbnail) : self::make_thumbnail($files['optimized']['content']);
        if ($small !== null) {
            $files['thumbnail'] = $small;
        }
        return ['mime' => $mime, 'width' => $width, 'height' => $height, 'favicononly' => $favicononly, 'files' => $files];
    }

    /**
     * Checks a font (woff2) and its optional licence file.
     *
     * @param string $font Path of the font.
     * @param string|null $licence Path of the licence (plain text or Markdown), if any.
     * @return array ['mime', 'files' => [role => ['content', 'mime']]] (roles original, optimized, licence).
     * @throws moodle_exception asset:error:* when a file is refused.
     */
    public static function check_font(string $font, ?string $licence = null): array {
        $content = self::read($font, self::MAX_FILE);
        if (self::sniff($content) !== 'font/woff2') {
            throw new moodle_exception('asset:error:font', 'theme_brandable');
        }
        $files = [
            'original' => ['content' => $content, 'mime' => 'font/woff2'],
            'optimized' => ['content' => $content, 'mime' => 'font/woff2'],
        ];
        if ($licence !== null) {
            $files['licence'] = ['content' => self::check_licence(self::read($licence, self::MAX_LICENCE)), 'mime' => 'text/plain'];
        }
        return ['mime' => 'font/woff2', 'files' => $files];
    }

    /**
     * Checks the text of a licence.
     *
     * @param string $text Licence.
     * @return string
     * @throws moodle_exception asset:error:licence when it is not text.
     */
    public static function check_licence(string $text): string {
        $binary = !mb_check_encoding($text, 'UTF-8') || strpos($text, "\0") !== false;
        if ($text === '' || strlen($text) > self::MAX_LICENCE || $binary) {
            throw new moodle_exception('asset:error:licence', 'theme_brandable');
        }
        return $text;
    }

    /**
     * Returns the type of a file from its first bytes (never from its name).
     *
     * @param string $content Content.
     * @return string|null One of the keys of EXTENSIONS, null when unknown.
     */
    public static function sniff(string $content): ?string {
        $head = substr($content, 0, 16);
        if (strncmp($head, "\x89PNG\r\n\x1a\n", 8) === 0) {
            return 'image/png';
        }
        if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) {
            return 'image/jpeg';
        }
        if (strncmp($head, 'GIF87a', 6) === 0 || strncmp($head, 'GIF89a', 6) === 0) {
            return 'image/gif';
        }
        if (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        if (strncmp($head, "\x00\x00\x01\x00", 4) === 0) {
            return 'image/vnd.microsoft.icon';
        }
        if (strncmp($head, 'wOF2', 4) === 0) {
            return 'font/woff2';
        }
        // SVG: text whose first element is <svg (after the XML declaration, a DOCTYPE, comments and spaces; a DOCTYPE is
        // then refused by svg::sanitize()).
        $start = preg_replace(
            '/^(\xEF\xBB\xBF)?\s*(<\?xml[^>]*\?>\s*)?(<!DOCTYPE[^\[>]*(\[.*?\])?\s*>\s*)?(<!--.*?-->\s*)*/s',
            '',
            substr($content, 0, 4096)
        );
        return preg_match('/^<svg[\s>]/i', (string) $start) ? 'image/svg+xml' : null;
    }

    /**
     * Whether GD can write WebP files on this server.
     *
     * @return bool
     */
    public static function gd_webp(): bool {
        return function_exists('imagewebp') && !empty(gd_info()['WebP Support']);
    }

    /**
     * Reads a file within a size limit.
     *
     * @param string $path Path.
     * @param int $max Largest size.
     * @return string
     */
    private static function read(string $path, int $max): string {
        $size = is_readable($path) ? filesize($path) : false;
        if ($size === false || $size === 0) {
            throw new moodle_exception('asset:error:empty', 'theme_brandable');
        }
        if ($size > $max) {
            throw new moodle_exception('asset:error:filesize', 'theme_brandable', '', display_size($max));
        }
        return (string) file_get_contents($path);
    }

    /**
     * Returns the dimensions of a raster image, read from the file.
     *
     * @param string $content Content.
     * @param string $mime Type already sniffed.
     * @return int[] [width, height]
     */
    private static function dimensions(string $content, string $mime): array {
        $info = @getimagesizefromstring($content);
        if (!$info || $info[0] < 1 || $info[1] < 1 || ($info['mime'] ?? '') !== $mime) {
            throw new moodle_exception('asset:error:corrupt', 'theme_brandable');
        }
        return [(int) $info[0], (int) $info[1]];
    }

    /**
     * Checks the version prepared by the editor: same type, same proportions (the orientation may have been applied),
     * no larger than the original nor than MAX_SIDE.
     *
     * @param string $path Path.
     * @param string $mime Type of the original.
     * @param int $width Width of the original.
     * @param int $height Height of the original.
     * @return array ['content', 'mime', 'width', 'height']
     */
    private static function check_optimized(string $path, string $mime, int $width, int $height): array {
        $content = self::read($path, self::MAX_FILE);
        if (self::sniff($content) !== $mime) {
            throw new moodle_exception('asset:error:version', 'theme_brandable');
        }
        [$w, $h] = self::dimensions($content, $mime);
        $limit = min(self::MAX_SIDE, max($width, $height));
        $ratio = fn($a, $b) => abs($a - $b) <= 0.02 * max($a, $b);
        $same = $ratio($w * $height, $h * $width) || $ratio($w * $width, $h * $height);
        if (max($w, $h) > $limit || !$same) {
            throw new moodle_exception('asset:error:version', 'theme_brandable');
        }
        return ['content' => $content, 'mime' => $mime, 'width' => $w, 'height' => $h];
    }

    /**
     * Re-encodes an image with GD (metadata dropped), resized beyond MAX_SIDE. Without GD support for its type, the
     * image is kept as is when it is small enough.
     *
     * @param string $content Content.
     * @param string $mime Type.
     * @param int $width Width.
     * @param int $height Height.
     * @return array ['content', 'mime', 'width', 'height']
     */
    private static function reencode(string $content, string $mime, int $width, int $height): array {
        $image = self::gd_supports($mime) ? self::gd_open($content) : null;
        if ($image === null) {
            if (max($width, $height) > self::MAX_SIDE) {
                throw new moodle_exception('asset:error:toolarge', 'theme_brandable', '', self::MAX_SIDE);
            }
            return ['content' => $content, 'mime' => $mime, 'width' => $width, 'height' => $height];
        }
        $scale = min(1, self::MAX_SIDE / max($width, $height));
        if ($scale < 1) {
            $resized = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
            $image = $resized;
        }
        $out = self::gd_write($image, $mime);
        $result = ['content' => $out, 'mime' => $mime, 'width' => imagesx($image), 'height' => imagesy($image)];
        return $result;
    }

    /**
     * Resizes an image with GD to a size (same type; a GIF loses its animation), null when GD cannot.
     *
     * @param string $content Image.
     * @param int $width New width.
     * @param int $height New height.
     * @return string|null
     */
    public static function resize(string $content, int $width, int $height): ?string {
        $mime = self::sniff($content);
        if ($mime === null || !self::gd_supports($mime) || !($image = self::gd_open($content))) {
            return null;
        }
        $resized = imagescale($image, max(1, $width), max(1, $height));
        // Freed now (a GdImage object since PHP 8.0: no imagedestroy(), deprecated in PHP 8.5).
        unset($image);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $out = self::gd_write($resized, $mime);
        return $out;
    }

    /**
     * Checks the WebP version prepared by the editor: a WebP file of the size of the version served.
     *
     * @param string $path Path.
     * @param int $width Width of the version served.
     * @param int $height Height.
     * @return string
     */
    private static function check_webp(string $path, int $width, int $height): string {
        $content = self::read($path, self::MAX_FILE);
        if (self::sniff($content) !== 'image/webp' || self::dimensions($content, 'image/webp') !== [$width, $height]) {
            throw new moodle_exception('asset:error:version', 'theme_brandable');
        }
        return $content;
    }

    /**
     * Makes the WebP version with GD when it can.
     *
     * @param string $content Version served.
     * @return string|null
     */
    private static function make_webp(string $content): ?string {
        if (!self::gd_webp() || !($image = self::gd_open($content))) {
            return null;
        }
        ob_start();
        imagewebp($image, null, 85);
        return (string) ob_get_clean();
    }

    /**
     * Checks the thumbnail prepared by the editor (contract 11.2).
     *
     * @param string $path Path.
     * @return array ['content', 'mime']
     */
    private static function check_thumbnail(string $path): array {
        $content = self::read($path, self::THUMBNAIL_BYTES);
        $mime = self::sniff($content);
        if (
            !in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)
                || max(self::dimensions($content, $mime)) > self::THUMBNAIL_SIDE
        ) {
            throw new moodle_exception('asset:error:thumbnail', 'theme_brandable');
        }
        return ['content' => $content, 'mime' => $mime];
    }

    /**
     * Makes a thumbnail with GD (JPEG, PNG when transparent), null when GD cannot read the image (SVG, icon...).
     *
     * @param string $content Image.
     * @return array|null ['content', 'mime']
     */
    public static function make_thumbnail(string $content): ?array {
        $mime = self::sniff($content);
        if ($mime === null || !self::gd_supports($mime) || !($image = self::gd_open($content))) {
            return null;
        }
        [$width, $height] = [imagesx($image), imagesy($image)];
        $scale = min(1, self::THUMBNAIL_SIDE / max($width, $height));
        if ($scale < 1) {
            $resized = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
            $image = $resized;
        }
        $transparent = in_array($mime, ['image/png', 'image/gif', 'image/webp'], true);
        foreach ($transparent ? ['image/png', 'image/jpeg'] : ['image/jpeg'] as $type) {
            foreach ($type === 'image/jpeg' ? [80, 60, 40] : [null] as $quality) {
                $out = self::gd_write($image, $type, $quality);
                if (strlen($out) <= self::THUMBNAIL_BYTES) {
                    return ['content' => $out, 'mime' => $type];
                }
            }
        }
        return null;
    }

    /**
     * Whether GD can read and write a type.
     *
     * @param string $mime Type.
     * @return bool
     */
    private static function gd_supports(string $mime): bool {
        if (!function_exists('imagecreatefromstring')) {
            return false;
        }
        return match ($mime) {
            'image/png' => function_exists('imagepng'),
            'image/jpeg' => function_exists('imagejpeg'),
            'image/gif' => function_exists('imagegif'),
            'image/webp' => self::gd_webp(),
            default => false,
        };
    }

    /**
     * Opens an image with GD (alpha kept), after a memory raise.
     *
     * @param string $content Content.
     * @return \GdImage|null
     */
    private static function gd_open(string $content): ?\GdImage {
        raise_memory_limit(MEMORY_EXTRA);
        $image = @imagecreatefromstring($content);
        if (!$image) {
            return null;
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);
        return $image;
    }

    /**
     * Writes an image with GD.
     *
     * @param \GdImage $image Image.
     * @param string $mime Type.
     * @param int|null $quality JPEG or WebP quality.
     * @return string
     */
    private static function gd_write(\GdImage $image, string $mime, ?int $quality = null): string {
        ob_start();
        match ($mime) {
            'image/png' => imagepng($image, null, 9),
            'image/jpeg' => imagejpeg(self::flatten($image), null, $quality ?? 85),
            'image/gif' => imagegif($image),
            'image/webp' => imagewebp($image, null, $quality ?? 85),
        };
        return (string) ob_get_clean();
    }

    /**
     * Returns the image on a white background (JPEG has no transparency).
     *
     * @param \GdImage $image Image.
     * @return \GdImage
     */
    private static function flatten(\GdImage $image): \GdImage {
        $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagealphablending($flat, true);
        imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        return $flat;
    }
}
