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

use theme_brandable\local\account\api_client;
use theme_brandable\local\account\api_exception;
use theme_brandable\local\account\connection;

/**
 * Updates of the plugin (spec docs/SPEC-plugin-updates.md, contract 15.5 and 15.6).
 *
 * - Version required by Brandable: 426 plugin_upgrade_required, or minimumVersion of GET /plugin/latest above the
 *   version installed. Kept until the plugin is updated or a request goes through.
 * - New version: read once a day by a scheduled task, on a connected site only; the administrators are notified
 *   once per version (every week for a security update), and see a banner in the editor. Never installed by the
 *   plugin.
 * - One source of notifications: once the release is on the Plugins Directory (directory, or an update known to the
 *   update checker of Moodle), Moodle notifies and the plugin no longer does.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class plugin_updates {
    /** @var string Setting: version required by Brandable (JSON), after a 426. */
    public const REQUIRED_CONFIG = 'brandable_pluginrequired';
    /** @var string Setting: answer of GET /plugin/latest (JSON). */
    public const LATEST_CONFIG = 'brandable_pluginlatest';
    /** @var string Setting: version the administrators were notified of. */
    public const NOTIFIED_CONFIG = 'brandable_pluginnotified';
    /** @var string Setting: time of the last notification (security updates: every week). */
    public const NOTIFIEDAT_CONFIG = 'brandable_pluginnotifiedat';
    /** @var string Setting: minimum version the administrators were notified of. */
    public const NOTIFIEDREQUIRED_CONFIG = 'brandable_pluginnotifiedrequired';
    /** @var string User preference: version whose banner was closed. */
    public const DISMISSED_PREFERENCE = 'theme_brandable_updatedismissed';
    /** @var int Notification of a security update again after this time. */
    public const SECURITY_REMINDER = WEEKSECS;
    /** @var string Code of the 426 (contract 15.5). */
    public const UPGRADE_REQUIRED = 'plugin_upgrade_required';

    /**
     * Keeps the version required by Brandable after a 426 plugin_upgrade_required.
     *
     * @param api_exception $e Error.
     */
    public static function record_required(api_exception $e): void {
        // Contract 15.5: error.plugin { minimumVersion, latest { release, version } | null }.
        $plugin = is_array($e->details['plugin'] ?? null) ? $e->details['plugin'] : [];
        $latest = is_array($plugin['latest'] ?? null) ? $plugin['latest'] : [];
        set_config(self::REQUIRED_CONFIG, json_encode([
            'minimumversion' => self::to_version($plugin['minimumVersion'] ?? 0),
            'latestrelease' => self::to_release($latest['release'] ?? ''),
            'latestversion' => self::to_version($latest['version'] ?? 0),
            'url' => self::to_url($e->url),
            'message' => $e->displaymessage,
            'version' => plugin_info::version(),
        ]), 'theme_brandable');
    }

    /**
     * Forgets the version required, after a request that went through.
     */
    public static function clear_required(): void {
        if (get_config('theme_brandable', self::REQUIRED_CONFIG) !== false) {
            unset_config(self::REQUIRED_CONFIG, 'theme_brandable');
        }
    }

    /**
     * Returns the update required by Brandable, if any.
     *
     * @return array|null [minimumversion, latestrelease, latestversion, url, message]
     */
    public static function required(): ?array {
        $current = plugin_info::version();
        $recorded = json_decode((string) get_config('theme_brandable', self::REQUIRED_CONFIG), true);
        // Recorded by an older version of the code, or a minimum the version installed reaches: no longer true.
        if (
            is_array($recorded) && (int) ($recorded['version'] ?? 0) === $current
            && ((int) ($recorded['minimumversion'] ?? 0) === 0 || $current < (int) $recorded['minimumversion'])
        ) {
            return array_intersect_key($recorded, array_flip(['minimumversion', 'latestrelease', 'latestversion', 'url',
                'message']));
        }
        // Announced by GET /plugin/latest, before any request is refused.
        $latest = self::latest();
        if ($latest && $latest['minimumversion'] > $current) {
            return [
                'minimumversion' => $latest['minimumversion'],
                'latestrelease' => $latest['release'],
                'latestversion' => $latest['version'],
                'url' => $latest['installurl'],
                'message' => get_string('pluginupdate:requiredmessage', 'theme_brandable'),
            ];
        }
        return null;
    }

    /**
     * Reads the latest version from Brandable (GET /plugin/latest), on a connected site only, and keeps it.
     *
     * @param api_client|null $client Client of the API.
     * @return array|null Latest version (see latest()), null when none is published or the site is not connected.
     * @throws api_exception Network, 503...
     */
    public static function check(?api_client $client = null): ?array {
        global $CFG;
        if (!connection::is_connected()) {
            return null;
        }
        try {
            // The notes in the language of the site (Accept-Language), whoever runs the check.
            $language = force_current_language($CFG->lang);
            try {
                $data = ($client ?? new api_client())->request('GET', '/plugin/latest');
            } finally {
                force_current_language($language);
            }
        } catch (api_exception $e) {
            if ($e->apicode === 'not_found') {
                // No version published.
                unset_config(self::LATEST_CONFIG, 'theme_brandable');
                return null;
            }
            throw $e;
        }
        $moodle = is_array($data['moodle'] ?? null) ? $data['moodle'] : [];
        $notes = is_array($data['notes'] ?? null) ? $data['notes'] : [];
        $latest = [
            'release' => self::to_release($data['release'] ?? ''),
            'version' => self::to_version($data['version'] ?? 0),
            'maturity' => preg_replace('~[^A-Z_]~', '', (string) ($data['maturity'] ?? '')),
            'publishedat' => strtotime((string) ($data['publishedAt'] ?? '')) ?: 0,
            'supported' => array_values(array_map('intval', array_filter((array) ($moodle['supported'] ?? []), 'is_numeric'))),
            'moodlelabel' => clean_param((string) ($moodle['label'] ?? ''), PARAM_TEXT),
            'compatible' => !empty($moodle['compatibleWithThisSite']),
            'notes' => (string) ($notes['markdown'] ?? ''),
            'noteslang' => clean_param((string) ($notes['lang'] ?? ''), PARAM_LANG),
            'updateavailable' => !empty($data['updateAvailable']),
            'minimumversion' => self::to_version($data['minimumVersion'] ?? 0),
            'installurl' => self::to_url((string) ($data['installUrl'] ?? '')),
            'security' => !empty($data['security']),
            'directory' => !empty($data['directory']),
            'timechecked' => time(),
        ];
        set_config(self::LATEST_CONFIG, json_encode($latest), 'theme_brandable');
        return $latest;
    }

    /**
     * Returns the latest version kept by check().
     *
     * @return array|null
     */
    public static function latest(): ?array {
        $latest = json_decode((string) get_config('theme_brandable', self::LATEST_CONFIG), true);
        return is_array($latest) && isset($latest['version'], $latest['minimumversion']) ? $latest : null;
    }

    /**
     * Returns the update available for this site: newer than the version installed and compatible with this Moodle.
     *
     * @return array|null See latest().
     */
    public static function available(): ?array {
        global $CFG;
        $latest = self::latest();
        if (!$latest || !$latest['updateavailable'] || !$latest['compatible'] || $latest['version'] <= plugin_info::version()) {
            return null;
        }
        // The branch of this Moodle in the range supported by the release (checked again: never offer a zip that
        // Moodle would refuse).
        $supported = $latest['supported'];
        if (count($supported) === 2 && ($CFG->branch < $supported[0] || $CFG->branch > $supported[1])) {
            return null;
        }
        return $latest;
    }

    /**
     * Whether Moodle notifies of this update itself, so that the plugin does not: the release is on Moodle Marketplace
     * (or the update checker of Moodle knows it), Moodle looks for updates and notifies them, and the release is mature
     * enough for this site ($CFG->updateminmaturity, stable by default: an alpha or beta release is announced by the
     * plugin).
     *
     * @param array $latest Update (see latest()).
     * @return bool
     */
    public static function moodle_notifies(array $latest): bool {
        global $CFG;
        if (empty($CFG->updateautocheck) || !empty($CFG->disableupdatenotifications)) {
            return false;
        }
        $minmaturity = (int) ($CFG->updateminmaturity ?? MATURITY_STABLE);
        if (self::to_maturity($latest['maturity'] ?? '') < $minmaturity) {
            return false;
        }
        if (!empty($latest['directory'])) {
            return true;
        }
        try {
            return \core\update\checker::instance()->get_update_info('theme_brandable', [
                'minmaturity' => $minmaturity,
                'notifybuilds' => !empty($CFG->updatenotifybuilds),
            ]) !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Notifies the administrators of the site: the update required by Brandable once per minimum version, a new
     * version once (every week for a security update), unless Moodle does it.
     *
     * @return int Users notified.
     */
    public static function notify(): int {
        $required = self::required();
        if ($required !== null) {
            $key = $required['minimumversion'] . '/' . plugin_info::version();
            if (get_config('theme_brandable', self::NOTIFIEDREQUIRED_CONFIG) === $key) {
                return 0;
            }
            set_config(self::NOTIFIEDREQUIRED_CONFIG, $key, 'theme_brandable');
            $newer = $required['latestrelease'] !== '' && $required['latestversion'] > plugin_info::version();
            $a = (object) ['release' => plugin_info::release(), 'latest' => $newer ? $required['latestrelease'] : '-'];
            return self::send('pluginupdate:required', $a, $required['message'] . "\n\n", '', $required['url']);
        }

        $latest = self::available();
        if ($latest === null || self::moodle_notifies($latest)) {
            return 0;
        }
        $notified = (int) get_config('theme_brandable', self::NOTIFIED_CONFIG) === $latest['version'];
        $recent = time() - (int) get_config('theme_brandable', self::NOTIFIEDAT_CONFIG) < self::SECURITY_REMINDER;
        if ($notified && (!$latest['security'] || $recent)) {
            return 0;
        }
        set_config(self::NOTIFIED_CONFIG, $latest['version'], 'theme_brandable');
        set_config(self::NOTIFIEDAT_CONFIG, time(), 'theme_brandable');
        $a = (object) ['release' => $latest['release'], 'current' => plugin_info::release()];
        $prefix = $latest['security'] ? 'pluginupdate:security' : 'pluginupdate:available';
        $notes = $latest['notes'] !== '' ? "\n\n" . $latest['notes'] : '';
        return self::send($prefix, $a, '', $notes, $latest['installurl']);
    }

    /**
     * Returns the banner of the editor for a user: administrators of the site only, unless closed (never for a
     * security update).
     *
     * @return array|null Context of theme_brandable/editor_pluginupdate.
     */
    public static function banner(): ?array {
        if (!has_capability('moodle/site:config', \context_system::instance()) || self::required() !== null) {
            return null;
        }
        $latest = self::available();
        if ($latest === null) {
            return null;
        }
        if (!$latest['security'] && (int) get_user_preferences(self::DISMISSED_PREFERENCE, 0) === $latest['version']) {
            return null;
        }
        // On the Plugins Directory: the update page of Moodle, which installs it.
        $moodle = self::moodle_notifies($latest);
        $url = $moodle ? (new \moodle_url('/admin/plugins.php', ['updatesonly' => 1]))->out(false) : $latest['installurl'];
        return [
            'release' => $latest['release'],
            'version' => $latest['version'],
            'security' => $latest['security'],
            'dismissible' => !$latest['security'],
            'notes' => $latest['notes'] !== '' ? format_text(
                $latest['notes'],
                FORMAT_MARKDOWN,
                ['context' => \context_system::instance()]
            ) : '',
            'hasnotes' => $latest['notes'] !== '',
            'url' => $url,
            'hasurl' => $url !== '',
            'moodle' => $moodle,
        ];
    }

    /**
     * Returns the screen of the editor when Brandable requires a newer version.
     *
     * @return array|null Context of theme_brandable/editor_pluginupgrade.
     */
    public static function upgrade_screen(): ?array {
        global $CFG;
        $required = self::required();
        if ($required === null) {
            return null;
        }
        $canupdate = has_capability('moodle/site:config', \context_system::instance());
        return [
            'message' => (string) ($required['message'] ?? ''),
            'release' => plugin_info::release(),
            'latestrelease' => (string) ($required['latestrelease'] ?? ''),
            // Only a newer release (the latest published may be older than a release not published yet).
            'haslatest' => (string) ($required['latestrelease'] ?? '') !== ''
                && (int) ($required['latestversion'] ?? 0) > plugin_info::version(),
            'canupdate' => $canupdate,
            'url' => $canupdate ? (string) ($required['url'] ?? '') : '',
            'hasurl' => $canupdate && (string) ($required['url'] ?? '') !== '',
            'installaddonurl' => $canupdate && empty($CFG->disableupdateautodeploy)
                ? (new \moodle_url('/admin/tool/installaddon/index.php'))->out(false) : '',
        ];
    }

    /**
     * Closes the banner of a version for the current user.
     *
     * @param int $version Version.
     */
    public static function dismiss(int $version): void {
        set_user_preference(self::DISMISSED_PREFERENCE, $version);
    }

    /**
     * Sends a notification to the administrators of the site, each in their language.
     *
     * @param string $prefix Strings of the subject (<prefix>subject) and of the text (<prefix>body).
     * @param \stdClass $a Values of the strings.
     * @param string $before Text from Brandable before the body (message of the 426), in the language of the site.
     * @param string $after Text from Brandable after the body (release notes), in the language of the site.
     * @param string $url Page of the installation of Brandable, empty if none.
     * @return int Users notified.
     */
    private static function send(string $prefix, \stdClass $a, string $before, string $after, string $url): int {
        $editor = new \moodle_url('/theme/brandable/editor.php');
        $count = 0;
        // The administrators of the site, and whoever was given moodle/site:config.
        $users = get_admins();
        foreach (get_users_by_capability(\context_system::instance(), 'moodle/site:config') as $user) {
            $users[$user->id] = $user;
        }
        foreach ($users as $user) {
            if (!empty($user->deleted) || !empty($user->suspended)) {
                continue;
            }
            $language = force_current_language($user->lang ?? '');
            $subject = get_string($prefix . 'subject', 'theme_brandable', $a);
            $text = $before . get_string($prefix . 'body', 'theme_brandable', $a) . $after;
            $message = new \core\message\message();
            $message->component = 'theme_brandable';
            $message->name = 'pluginupdate';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $user;
            $message->subject = $subject;
            $message->fullmessage = $text . ($url !== '' ? "\n\n" . $url : '');
            $message->fullmessageformat = FORMAT_MARKDOWN;
            $message->fullmessagehtml = format_text(
                $message->fullmessage,
                FORMAT_MARKDOWN,
                ['context' => \context_system::instance()]
            );
            $message->smallmessage = $subject;
            $message->notification = 1;
            $message->contexturl = $url !== '' ? $url : $editor->out(false);
            $message->contexturlname = get_string($url !== '' ? 'pluginupdate:install' : 'editor', 'theme_brandable');
            force_current_language($language);
            if (message_send($message)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * The maturity of a release (MATURITY_ALPHA... as sent by Brandable); unknown: alpha (the plugin notifies).
     *
     * @param string $name Name of the constant.
     * @return int
     */
    private static function to_maturity(string $name): int {
        $known = ['MATURITY_ALPHA' => MATURITY_ALPHA, 'MATURITY_BETA' => MATURITY_BETA, 'MATURITY_RC' => MATURITY_RC,
            'MATURITY_STABLE' => MATURITY_STABLE];
        return $known[$name] ?? MATURITY_ALPHA;
    }

    /**
     * A version of the contract ($plugin->version), 0 when invalid.
     *
     * @param mixed $value Value.
     * @return int
     */
    private static function to_version($value): int {
        return is_numeric($value) && preg_match('~^\d{10}$~', (string) $value) ? (int) $value : 0;
    }

    /**
     * A release of the contract (X.Y.Z), empty when invalid.
     *
     * @param mixed $value Value.
     * @return string
     */
    private static function to_release($value): string {
        return is_string($value) && preg_match('~^[0-9A-Za-z.+-]{1,30}$~', $value) ? $value : '';
    }

    /**
     * A link given by Brandable: http(s) only, empty otherwise.
     *
     * @param string $url Link.
     * @return string
     */
    private static function to_url(string $url): string {
        return preg_match('~^https?://~i', $url) ? clean_param($url, PARAM_URL) : '';
    }
}
