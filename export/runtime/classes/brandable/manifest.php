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
 * Manifest of the exported version (brandable/manifest.json): parent, SCSS chunks and template variants with their
 * scopes. Data only: no secret (the licence is in licence.php).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manifest {
    /** @var array|null Manifest, read once per request. */
    private static ?array $data = null;

    /**
     * Returns the manifest.
     *
     * @return array
     */
    public static function get(): array {
        if (self::$data === null) {
            $raw = @file_get_contents(dirname(__DIR__, 2) . '/brandable/manifest.json');
            $data = $raw === false ? null : json_decode($raw, true);
            self::$data = is_array($data) ? $data : [];
        }
        return self::$data;
    }

    /**
     * Returns the parent theme fixed at the export.
     *
     * @return string
     */
    public static function get_parent(): string {
        $parent = (string) (self::get()['parent'] ?? 'boost');
        return clean_param($parent, PARAM_PLUGIN) === $parent && $parent !== '' ? $parent : 'boost';
    }

    /**
     * Whether some templates have variants for a page type, a page, a course or an activity.
     *
     * @return bool
     */
    public static function has_variants(): bool {
        return !empty(self::get()['variants']);
    }

    /**
     * Returns the content of a file of the export (relative path listed in the manifest).
     *
     * @param string $file Relative path.
     * @return string Empty when missing.
     */
    public static function read(string $file): string {
        if (!preg_match('~^brandable/(?:(?:scss|variants)/[a-z0-9_-]+\.(?:scss|mustache)|js/[a-z0-9-]+/compiled\.js)$~', $file)) {
            return '';
        }
        $content = @file_get_contents(dirname(__DIR__, 2) . '/' . $file);
        return $content === false ? '' : $content;
    }

    /**
     * Replaces the manifest (unit tests), or reads it again (null).
     *
     * @param array|null $data Manifest.
     */
    public static function reset(?array $data = null): void {
        self::$data = $data;
    }
}
