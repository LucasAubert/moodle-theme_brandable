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

use cache;
use context_system;
use moodle_url;
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\context;
use theme_brandable\local\overrides\resolver;
use theme_brandable\local\overrides\revision_manager;
use theme_config;

/**
 * Preview mode: the current session renders the draft instead of the published revision.
 *
 * Enabled only from the editor, by a user with theme/brandable:edit. The rest of the site keeps seeing
 * the published revision. Being session-wide, the preview follows the navigation inside the editor's
 * iframe (and also applies to the user's other tabs, where the preview banner is shown).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preview {
    /** @var string Key of the preview state in $SESSION. */
    private const SESSION_KEY = 'theme_brandable_preview';

    /** @var bool|null Whether the user may preview (memoised per request). */
    private static ?bool $canpreview = null;

    /**
     * Enables the preview for the current session.
     *
     * @param int|null $revisionid Revision to preview; null follows the draft.
     */
    public static function enable(?int $revisionid = null): void {
        global $SESSION;
        require_capability('theme/brandable:edit', context_system::instance());
        $markers = $SESSION->{self::SESSION_KEY}->markers ?? true;
        $SESSION->{self::SESSION_KEY} = (object) ['revision' => $revisionid, 'markers' => $markers];
    }

    /**
     * Turns the preview back on when the editor is used while it is off.
     *
     * The preview is a session flag: leaving it (editor's close button, banner) and coming back to an editor
     * page restored by the browser (back button) would otherwise leave the editor working on the draft while
     * its iframe shows the published site. Called by the editor's external functions. A preview targeting a
     * given revision is kept as is. Never done for web service clients (token requests).
     */
    public static function keep_alive(): void {
        global $SESSION;

        if ((defined('WS_SERVER') && WS_SERVER) || !empty($SESSION->{self::SESSION_KEY})) {
            return;
        }
        self::enable();
        self::$canpreview = null;
    }

    /**
     * Whether the template markers are enabled for the preview (default: yes).
     *
     * @return bool
     */
    public static function markers_enabled(): bool {
        global $SESSION;
        return (bool) ($SESSION->{self::SESSION_KEY}->markers ?? true);
    }

    /**
     * Turns the template markers on or off for the preview of the session (editor switch, for pages that
     * would not display well with them).
     *
     * @param bool $enabled Enabled.
     */
    public static function set_markers(bool $enabled): void {
        global $SESSION;
        if (empty($SESSION->{self::SESSION_KEY})) {
            self::enable();
        }
        $SESSION->{self::SESSION_KEY}->markers = $enabled;
    }

    /**
     * Disables the preview for the current session.
     */
    public static function disable(): void {
        global $SESSION;
        unset($SESSION->{self::SESSION_KEY});
    }

    /**
     * Whether the current request renders the preview.
     *
     * @return bool
     */
    public static function is_active(): bool {
        global $SESSION;

        if (during_initial_install() || empty($SESSION->{self::SESSION_KEY})) {
            return false;
        }
        if (self::$canpreview === null) {
            self::$canpreview = isloggedin() && !isguestuser()
                && has_capability('theme/brandable:edit', context_system::instance());
        }
        return self::$canpreview;
    }

    /**
     * Returns the previewed revision (the draft unless a revision was targeted).
     *
     * @return int
     */
    public static function get_revision(): int {
        global $SESSION;
        $revision = $SESSION->{self::SESSION_KEY}->revision ?? null;
        return $revision === null ? revision_manager::get_draft() : (int) $revision;
    }

    /**
     * Theme CSS URLs for the preview: replaces the theme stylesheet when the previewed SCSS differs from
     * the published one (alter_css_urls callback).
     *
     * @param moodle_url[] $urls Theme CSS URLs.
     * @return moodle_url[]
     */
    public static function alter_css_urls(array $urls): array {
        if (!self::is_active()) {
            return $urls;
        }
        $revision = self::get_revision();
        $scss = resolver::get_map($revision)[change::TYPE_SCSS];
        if ($scss === resolver::get_map(revision_manager::get_published())[change::TYPE_SCSS]) {
            return $urls;
        }
        return [new moodle_url('/theme/brandable/preview_css.php', [
            'rev' => $revision,
            'rtl' => (int) right_to_left(),
            'hash' => self::get_css_key($revision, right_to_left()),
        ])];
    }

    /**
     * Returns the full theme CSS of a revision (same pipeline as styles.php), cached by content.
     *
     * @param int $revisionid Revision id.
     * @param bool $rtl Right-to-left.
     * @return string
     */
    public static function get_css(int $revisionid, bool $rtl): string {
        $key = self::get_css_key($revisionid, $rtl);
        $cache = cache::make('theme_brandable', 'previewcss');
        $css = $cache->get($key);
        if ($css !== false) {
            return $css;
        }

        $lock = \core\lock\lock_config::get_lock_factory('theme_brandable')->get_lock('previewcss_' . $key, 120);
        try {
            $css = $cache->get($key);
            if ($css === false) {
                $css = context::with_revision($revisionid, function () use ($rtl) {
                    $theme = theme_config::load('brandable');
                    $theme->force_svg_use(true);
                    $theme->set_rtl_mode($rtl);
                    return $theme->get_css_content();
                });
                $cache->set($key, $css);
            }
        } finally {
            if ($lock) {
                $lock->release();
            }
        }
        return $css;
    }

    /**
     * Key identifying the CSS of a revision: its SCSS chunks, the parent, the theme revisions and the direction.
     *
     * @param int $revisionid Revision id.
     * @param bool $rtl Right-to-left.
     * @return string
     */
    public static function get_css_key(int $revisionid, bool $rtl): string {
        return sha1(json_encode([
            resolver::get_map($revisionid)[change::TYPE_SCSS],
            parent_theme::get_name(),
            theme_get_revision(),
            theme_get_sub_revision_for_theme('brandable'),
            // The URLs of the assets designated by the markers.
            \theme_brandable\local\assets\library::get_revision(),
            $rtl,
        ]));
    }

    /**
     * Resets the per-request memo (unit tests).
     */
    public static function reset(): void {
        self::$canpreview = null;
    }
}
