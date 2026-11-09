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

namespace theme_brandable\local;

/**
 * Light and dark colour modes (Boost, Moodle 5.3 and later; spec docs/SPEC-colour-modes.md).
 *
 * - The brandable-dark mixin, put at the top of the pre-SCSS on every version, so that a chunk written for the dark
 *   mode compiles everywhere (on 4.5 to 5.2, or with the modes off, its rules are inert).
 * - Whether the modes exist on this site, are on, and the default mode, for the generator and the editor.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class colour_modes {
    /** @var string Attribute of the html tag giving the resolved mode (Bootstrap 5.3). */
    public const ATTRIBUTE = 'data-bs-theme';

    /** @var string Prefix of the rules of the dark mode in the compiled CSS. */
    public const DARK_SELECTOR = '[data-bs-theme="dark"]';

    /**
     * @var string The brandable-dark mixin (contract with the generator: spec section 2 bis). $parent is read before
     * the @at-root, and its length checked, for scssphp: & is an empty list at the root, and empty when read in the
     * selector of an @at-root inside an @if. Kept identical to the one of the runtime (export/runtime, scss.php).
     */
    public const MIXIN = <<<'SCSS'
        @mixin brandable-dark {
            $parent: &;
            @if $parent and length($parent) > 0 {
                @at-root #{selector-nest('[data-bs-theme="dark"]', $parent)} { @content; }
            } @else {
                [data-bs-theme="dark"] { @content; }
            }
        }

        SCSS;

    /** @var string Pattern of the prefix of the dark mode in a compiled selector (quotes optional). */
    private const DARK_PATTERN = '~\[data-bs-theme=(["\']?)dark\1\]~';

    /** @var string[] Modes of Boost. */
    public const MODES = ['light', 'dark', 'auto'];

    /**
     * Whether the theme of this site can have colour modes: Moodle 5.3 or later (theme_boost\colour_mode), Boost in
     * the theme chain of Brandable.
     *
     * @return bool
     */
    public static function available(): bool {
        return class_exists('\theme_boost\colour_mode')
            && in_array('boost', parent_theme::get_chain(parent_theme::get_name()), true);
    }

    /**
     * Whether the colour modes are on (experimental setting of Boost).
     *
     * @return bool
     */
    public static function enabled(): bool {
        return self::available() && \theme_boost\colour_mode::is_enabled();
    }

    /**
     * Default mode of the site: light, dark or auto (light when the modes are off or missing).
     *
     * @return string
     */
    public static function default_mode(): string {
        return self::enabled() ? \theme_boost\colour_mode::get_site_default() : 'light';
    }

    /**
     * Checks the compiled selectors of a SCSS chunk against the rules of the brandable-dark mixin (spec section 2 bis,
     * point 6): never in a pre-SCSS chunk, never nested, never on a rule targeting html or :root.
     *
     * @param string $target Target of the chunk (pre/... or post/...).
     * @param string[] $selectors Its compiled selectors.
     * @return string|null Error message.
     */
    public static function check_selectors(string $target, array $selectors): ?string {
        foreach ($selectors as $selector) {
            $count = preg_match_all(self::DARK_PATTERN, $selector);
            if ($count === 0) {
                continue;
            }
            if (strpos($target, 'pre/') === 0) {
                return get_string('error:darkinpre', 'theme_brandable');
            }
            if ($count > 1) {
                return get_string('error:darknested', 'theme_brandable');
            }
            if (preg_match('~^\[data-bs-theme=(["\']?)dark\1\]\s+(html\b|:root\b)~i', $selector)) {
                return get_string('error:darkroot', 'theme_brandable');
            }
        }
        return null;
    }

    /**
     * Whether compiled CSS has rules of the dark mode.
     *
     * @param string $css CSS.
     * @return bool
     */
    public static function has_dark_rules(string $css): bool {
        return preg_match(self::DARK_PATTERN, $css) === 1;
    }

    /**
     * Finding of a version of the cloud read on a site without colour modes (spec CM9): its dark variants stay inert
     * here. Computed locally, from the SCSS of the version.
     *
     * @param string[] $scss SCSS of the changes of the version.
     * @return array|null Finding (code, severity, target, message), null when the site has the modes or the version no
     *     dark variant.
     */
    public static function compatibility_finding(array $scss): ?array {
        if (self::available()) {
            return null;
        }
        foreach ($scss as $code) {
            if (str_contains($code, 'brandable-dark') || preg_match(self::DARK_PATTERN, $code)) {
                return ['code' => 'colour_modes_unavailable', 'severity' => 'warning', 'target' => '',
                    'message' => get_string('cloud:finding:colourmodes', 'theme_brandable')];
            }
        }
        return null;
    }

    /**
     * The colourModes field of the generation context (always sent, also when the modes are off).
     *
     * @return array available, enabled, default.
     */
    public static function to_context(): array {
        return ['available' => self::available(), 'enabled' => self::enabled(), 'default' => self::default_mode()];
    }
}
