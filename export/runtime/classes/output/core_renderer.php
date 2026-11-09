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

namespace theme_brandablert\output;

use theme_brandablert\brandable\assets;

/**
 * Core renderer of the theme: logo, compact logo and favicon of the version (images of the theme), without changing
 * the settings of the site. Everything else is the renderer of the parent chain.
 *
 * @package    theme_brandablert
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class core_renderer extends core_renderer_base {
    /**
     * Returns the URL of the logo: the one of the version when it sets it, else the site's.
     *
     * @param int|null $maxwidth Largest width, null when it does not matter.
     * @param int $maxheight Largest height.
     * @return \moodle_url|false
     */
    public function get_logo_url($maxwidth = null, $maxheight = 200) {
        return assets::setting('logo', $maxwidth, $maxheight) ?? parent::get_logo_url($maxwidth, $maxheight);
    }

    /**
     * Returns the URL of the compact logo: the one of the version when it sets it, else the site's.
     *
     * @param int|null $maxwidth Largest width.
     * @param int|null $maxheight Largest height.
     * @return \moodle_url|false
     */
    public function get_compact_logo_url($maxwidth = 300, $maxheight = 300) {
        return assets::setting('logocompact', $maxwidth, $maxheight) ?? parent::get_compact_logo_url($maxwidth, $maxheight);
    }

    /**
     * Returns the URL of the favicon: the one of the version when it sets it, else the parent's behaviour.
     *
     * @return \moodle_url
     */
    public function favicon() {
        return assets::setting('favicon', 64, 64) ?? parent::favicon();
    }
}
