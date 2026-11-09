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

/**
 * Serves the files of the library (pluginfile, file area asset; spec A4).
 *
 * - <id>/<hash12>/<slug>.<ext>: the file served, public (login page, CSS, logos), immutable (the hash of its content
 *   is in the URL: a wrong hash is not found, so the library cannot be listed by guessing); WebP when the browser
 *   accepts it.
 * - <id>/thumbnail/<file>: the thumbnail, for the editor (theme/brandable:edit).
 *
 * The original is never served (it may carry metadata: GPS of a photo). SVG files are served sandboxed.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class server {
    /** @var int Lifetime of the files served (they never change at a given URL). */
    public const LIFETIME = YEARSECS;

    /**
     * Serves a file of the library, or answers not found.
     *
     * @param array $args Path after the file area.
     * @param array $options Options of the pluginfile callback.
     */
    public static function serve(array $args, array $options = []): void {
        [$id, $key] = [(int) ($args[0] ?? 0), (string) ($args[1] ?? '')];
        $record = $id > 0 ? library::get($id) : null;
        $size = count($args) === 4 ? (string) $args[2] : null;
        if (!$record || count($args) < 3 || count($args) > 4 || ($size !== null && !in_array($size, settings::SIZES, true))) {
            send_file_not_found();
        }
        if ($key === 'thumbnail') {
            require_login(null, false);
            require_capability('theme/brandable:edit', \context_system::instance());
            $file = library::get_file($record, 'thumbnail');
            if (!$file) {
                send_file_not_found();
            }
            send_stored_file($file, HOURSECS, 0, false, $options);
        }
        if ($key !== substr($record->contenthash, 0, 12)) {
            send_file_not_found();
        }
        $file = library::get_file($record, 'optimized');
        if (!$file) {
            send_file_not_found();
        }
        if ($size !== null) {
            // Logo at the size core asks for: reduced once, then kept (the WebP version is not reduced).
            $file = self::resized($record, $file, $size);
        }
        $webp = $size === null ? library::get_file($record, 'webp') : null;
        if ($webp) {
            // Same URL, two versions: caches keep one per Accept header.
            header('Vary: Accept');
            // Read only: content negotiation of the WebP version.
            if (self::accepts_webp($_SERVER['HTTP_ACCEPT'] ?? '')) {
                $file = $webp;
            }
        }
        if ($record->mime === 'image/svg+xml') {
            // Even opened directly, an SVG of the library runs nothing (sanitised at the upload, sandboxed here).
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
            $options['dontforcesvgdownload'] = true;
        }
        header('X-Content-Type-Options: nosniff');
        $options['cacheability'] = 'public';
        $options['immutable'] = true;
        send_stored_file($file, self::LIFETIME, 0, false, $options);
    }

    /**
     * Returns the file served at a size: a reduced copy (role size-WxH) made with GD when the image is larger, the
     * file itself otherwise (or when GD cannot read it: SVG, icons).
     *
     * @param \stdClass $record Asset.
     * @param \stored_file $file File served.
     * @param string $size WxH (0: no limit).
     * @return \stored_file
     */
    public static function resized(\stdClass $record, \stored_file $file, string $size): \stored_file {
        [$maxwidth, $maxheight] = array_map('intval', explode('x', $size));
        $scale = min(
            $maxwidth > 0 ? $maxwidth / max(1, (int) $record->width) : 1,
            $maxheight > 0 ? $maxheight / max(1, (int) $record->height) : 1
        );
        if ($scale >= 1 || !in_array($record->mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
            return $file;
        }
        $role = 'size-' . $size;
        $existing = library::get_file($record, $role);
        if ($existing) {
            return $existing;
        }
        $content = file_checker::resize(
            $file->get_content(),
            (int) round($record->width * $scale),
            (int) round($record->height * $scale)
        );
        if ($content === null) {
            return $file;
        }
        return get_file_storage()->create_file_from_string([
            'contextid' => $file->get_contextid(),
            'component' => 'theme_brandable',
            'filearea' => library::FILEAREA,
            'itemid' => (int) $record->id,
            'filepath' => '/' . $role . '/',
            'filename' => $file->get_filename(),
            'mimetype' => $record->mime,
        ], $content);
    }

    /**
     * Whether an Accept header accepts WebP (not refused with q=0).
     *
     * @param string $accept Header.
     * @return bool
     */
    public static function accepts_webp(string $accept): bool {
        foreach (explode(',', strtolower($accept)) as $part) {
            $pieces = array_map('trim', explode(';', $part));
            if ($pieces[0] !== 'image/webp') {
                continue;
            }
            foreach (array_slice($pieces, 1) as $parameter) {
                if (preg_match('/^q\s*=\s*0(\.0*)?$/', $parameter)) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }
}
