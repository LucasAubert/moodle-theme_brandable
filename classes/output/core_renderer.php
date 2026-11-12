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

namespace theme_brandable\output;

use theme_brandable\local\assets\settings;
use theme_brandable\output\mustache\injector;

/**
 * Brandable core renderer.
 *
 * Extends the core renderer of the parent theme through the {@see core_renderer_base} alias.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class core_renderer extends core_renderer_base {
    /**
     * Returns the Mustache engine, switched to the Brandable loader.
     *
     * The renderer factory already injects the loader into every renderer; this override keeps the core
     * renderer covered if injection is not supported (degraded mode, see injector).
     *
     * @return \Mustache_Engine
     */
    protected function get_mustache() {
        $engine = parent::get_mustache();
        injector::use_loader($engine);
        return $engine;
    }

    /**
     * Returns the URL of the logo: the one of the library when a setting change sets it (contract 11.4), else Moodle's.
     *
     * @param int|null $maxwidth Largest width, null when it does not matter.
     * @param int $maxheight Largest height.
     * @return \moodle_url|false
     */
    public function get_logo_url($maxwidth = null, $maxheight = 200) {
        $setting = during_initial_install() ? null : settings::url('logo', $maxwidth, $maxheight);
        return $setting ?? parent::get_logo_url($maxwidth, $maxheight);
    }

    /**
     * Returns the URL of the compact logo: the one of the library when a setting change sets it, else Moodle's.
     *
     * @param int|null $maxwidth Largest width.
     * @param int|null $maxheight Largest height.
     * @return \moodle_url|false
     */
    public function get_compact_logo_url($maxwidth = 300, $maxheight = 300) {
        $setting = during_initial_install() ? null : settings::url('logocompact', $maxwidth, $maxheight);
        return $setting ?? parent::get_compact_logo_url($maxwidth, $maxheight);
    }

    /**
     * Branding of the parent themes that build their navigation bar and login panel from logo settings of their own
     * (a get_branding_context() method) instead of {@see get_logo_url()}: the logos of the library replace them when a
     * setting change sets them. The dark logos too, as the version has one logo; the modes of the parent without a logo
     * are left alone.
     *
     * @return array|false Context of the branding template of the parent, false when it shows no logo.
     */
    public function get_branding_context() {
        if (!method_exists(parent::class, 'get_branding_context')) {
            return false;
        }
        $context = parent::get_branding_context();
        if (!is_array($context) || during_initial_install()) {
            return $context;
        }
        $logo = settings::url('logo', null, 200);
        $compact = settings::url('logocompact', 300, 300) ?? $logo;
        if ($logo !== null && isset($context['logourl'])) {
            $context['logourl'] = $logo->out(false);
            $context['darklogourl'] = $logo->out(false);
        }
        if ($compact !== null && isset($context['logominiurl'])) {
            $context['logominiurl'] = $compact->out(false);
            $context['darklogominiurl'] = $compact->out(false);
        }
        return $context;
    }

    /**
     * Returns the favicon URL.
     *
     * Core never inherits the theme favicon (theme_config::resolve_image_location() always points to the
     * theme's own pix/favicon.ico): use the parent's one unless the site defines a favicon.
     *
     * @return \moodle_url
     */
    public function favicon() {
        // Favicon taken from the library (setting change), in the preview or once published.
        $setting = during_initial_install() ? null : settings::url('favicon', 64, 64);
        if ($setting !== null) {
            return $setting;
        }
        $url = parent::favicon();
        if (!during_initial_install() && empty(get_config('core_admin', 'favicon'))) {
            $parent = \theme_config::load($this->page->theme->parents[0] ?? \theme_brandable\local\parent_theme::DEFAULT_PARENT);
            return $parent->image_url('favicon', 'theme');
        }
        return $url;
    }
}
