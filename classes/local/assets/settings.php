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

use moodle_url;
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\context;

/**
 * Settings changes (contract 11.4, spec A10): logo, compact logo and favicon taken from the library, applied by the
 * theme renderer (draft in the preview session, published version otherwise). Nothing is written in core_admin.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings {
    /** @var string[] Targets (closed list of the contract). */
    public const TARGETS = ['logo', 'logocompact', 'favicon'];

    /** @var string[] Types accepted for the favicon. */
    public const FAVICON_TYPES = ['image/png', 'image/svg+xml', 'image/vnd.microsoft.icon'];

    /**
     * Sizes a logo can be served at (maxwidth x maxheight, 0 for no limit): those core asks for. Any other size is
     * served at the size of the library, so that nobody can fill the file storage with resized copies.
     */
    public const SIZES = ['0x200', '300x300', '100x100', '0x100', '64x64'];

    /**
     * Returns the asset a setting designates in the active overrides, null when the setting is Moodle's own.
     *
     * @param string $target logo, logocompact or favicon.
     * @param array|null $map Resolved map, the active one when null.
     * @return \stdClass|null Asset record; null too when the asset is no longer in the library.
     */
    public static function get_asset(string $target, ?array $map = null): ?\stdClass {
        $map = $map ?? context::get_active_map();
        $slug = (string) ($map[change::TYPE_SETTING][$target]['content'] ?? '');
        if ($slug === '') {
            return null;
        }
        return library::get_all()[$slug] ?? null;
    }

    /**
     * Returns the URL of a setting at a size, null when the setting is Moodle's own.
     *
     * @param string $target logo, logocompact or favicon.
     * @param int|null $maxwidth Largest width (null or 0: no limit).
     * @param int|null $maxheight Largest height (null or 0: no limit).
     * @return moodle_url|null
     */
    public static function url(string $target, ?int $maxwidth = null, ?int $maxheight = null): ?moodle_url {
        $asset = self::get_asset($target);
        if ($asset === null) {
            return null;
        }
        $size = (int) $maxwidth . 'x' . (int) $maxheight;
        return library::url($asset, in_array($size, self::SIZES, true) ? $size : null);
    }

    /**
     * Checks the content of a setting change: an image of the library (for the favicon: PNG, SVG or ICO; icons only
     * for the favicon).
     *
     * @param string $target Target.
     * @param string $slug Slug.
     * @return string|null Error message.
     */
    public static function check(string $target, string $slug): ?string {
        if (!in_array($target, self::TARGETS, true)) {
            return get_string('error:settingtarget', 'theme_brandable');
        }
        $asset = library::get_all()[$slug] ?? null;
        if ($asset === null || $asset->kind !== 'image') {
            return get_string('error:settingasset', 'theme_brandable', s($slug));
        }
        if ($target === 'favicon' ? !in_array($asset->mime, self::FAVICON_TYPES, true) : !empty($asset->favicononly)) {
            return get_string('error:settingtype', 'theme_brandable', s($slug));
        }
        return null;
    }
}
