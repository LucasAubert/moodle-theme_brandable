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

namespace theme_brandablert\brandable;

/**
 * Images and fonts of the exported version (brandable/assets/, listed in the manifest): served by asset.php of the
 * theme, designated by the markers of the SCSS, and used as logo, compact logo and favicon (settings of the version,
 * applied by the core renderer of the theme without changing the settings of the site).
 *
 * The markers of the templates were replaced at the export (root of the site, /theme/<name>/asset.php/...): templates
 * of the whole site are standard files, read by core for the rendering in JavaScript too.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assets {
    /** @var string Marker in the SCSS (contract 11.3). */
    public const SCSS_MARKER = '/brandable-asset\(\s*(["\'])([a-z0-9-]+)\1\s*\)/';

    /** @var string Name of a file of brandable/assets/. */
    public const FILE = '~^[a-z0-9]+(?:-[a-z0-9]+)*(?:\.[0-9]+x[0-9]+)?\.(png|jpg|gif|webp|svg|ico|woff2)$~';

    /** @var string[] Type of each extension. */
    public const TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'svg' => 'image/svg+xml', 'ico' => 'image/vnd.microsoft.icon', 'woff2' => 'font/woff2'];

    /**
     * Returns the assets of the version, by slug.
     *
     * @return array
     */
    public static function get(): array {
        $assets = [];
        foreach (manifest::get()['assets'] ?? [] as $asset) {
            if (is_array($asset) && isset($asset['slug'], $asset['file'], $asset['hash'])) {
                $assets[(string) $asset['slug']] = $asset;
            }
        }
        return $assets;
    }

    /**
     * Returns the URL of an asset (a reduced copy for a logo size, when the export made one).
     *
     * @param string $slug Slug.
     * @param string|null $size Size (WxH) of a logo, null for the file as it is.
     * @return \moodle_url|null Null when the version has no such asset.
     */
    public static function url(string $slug, ?string $size = null): ?\moodle_url {
        $asset = self::get()[$slug] ?? null;
        if ($asset === null) {
            return null;
        }
        $file = (string) ($size !== null ? ($asset['sizes'][$size] ?? $asset['file']) : $asset['file']);
        return new \moodle_url('/theme/brandablert/asset.php/' . $asset['hash'] . '/' . basename($file));
    }

    /**
     * Replaces the markers of SCSS code: url() of the file, url("data:,") for an asset the version does not have.
     *
     * @param string $code SCSS.
     * @return string
     */
    public static function scss(string $code): string {
        if (strpos($code, 'brandable-asset') === false) {
            return $code;
        }
        return preg_replace_callback(self::SCSS_MARKER, function (array $match): string {
            $url = self::url($match[2]);
            return 'url("' . ($url ? $url->out(false) : 'data:,') . '")';
        }, $code);
    }

    /**
     * Returns the URL of a setting of the version (logo, logocompact, favicon), null when it is the site's own.
     *
     * @param string $target Target.
     * @param int|null $maxwidth Largest width (null or 0: no limit).
     * @param int|null $maxheight Largest height.
     * @return \moodle_url|null
     */
    public static function setting(string $target, ?int $maxwidth = null, ?int $maxheight = null): ?\moodle_url {
        $slug = (string) (manifest::get()['settings'][$target] ?? '');
        return $slug === '' ? null : self::url($slug, (int) $maxwidth . 'x' . (int) $maxheight);
    }

    /**
     * Serves a file of brandable/assets/ (asset.php): public, immutable (the hash of its content is in the URL), WebP
     * when the browser accepts it, SVG sandboxed. Answers not found otherwise.
     *
     * @param string $path Path after asset.php: /<hash>/<file>.
     */
    public static function serve(string $path): void {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $parts = explode('/', trim($path, '/'));
        $asset = null;
        if (count($parts) === 2 && preg_match(self::FILE, $parts[1])) {
            foreach (self::get() as $candidate) {
                $files = array_merge([$candidate['file']], array_values($candidate['sizes'] ?? []));
                if ($candidate['hash'] === $parts[0] && in_array('brandable/assets/' . $parts[1], $files, true)) {
                    $asset = $candidate;
                    break;
                }
            }
        }
        $file = $asset ? dirname(__DIR__, 2) . '/brandable/assets/' . $parts[1] : null;
        if ($file === null || !is_readable($file)) {
            send_file_not_found();
        }
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        if (!empty($asset['webp']) && $parts[1] === basename($asset['file'])) {
            // Same URL, two versions: caches keep one per Accept header.
            header('Vary: Accept');
            if (preg_match('~(^|,)\s*image/webp(?!\s*;\s*q\s*=\s*0(\.0*)?\s*(,|$))~i', $_SERVER['HTTP_ACCEPT'] ?? '')) {
                $file = dirname(__DIR__, 2) . '/' . $asset['webp'];
                $extension = 'webp';
            }
        }
        if ($extension === 'svg') {
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        }
        header('X-Content-Type-Options: nosniff');
        send_file(
            $file,
            $parts[1],
            YEARSECS,
            0,
            false,
            false,
            self::TYPES[$extension] ?? 'application/octet-stream',
            false,
            ['immutable' => true, 'cacheability' => 'public', 'dontforcesvgdownload' => true]
        );
    }
}
