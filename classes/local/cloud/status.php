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

namespace theme_brandable\local\cloud;

use core_external\external_single_structure;
use core_external\external_value;
use theme_brandable\local\account\account;
use theme_brandable\local\account\api_exception;

/**
 * Backup of a version in the cloud, as the editor shows it (spec C5): saved (with the number of the project), waiting,
 * or stopped with the reason and what the administrator can do.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class status {
    /** @var string[] Errors of the service: the version is sent again by itself. */
    private const UNAVAILABLE = ['connection_failed', 'cloud_unavailable', 'service_unavailable', 'internal_error', 'http_500',
        'http_502', 'http_503', 'http_504'];

    /**
     * Exports the backup of a local version.
     *
     * @param \stdClass $version Local version.
     * @return array
     */
    public static function export(\stdClass $version): array {
        $status = (string) ($version->cloudstatus ?? '');
        $error = (string) ($version->clouderror ?? '');
        $message = '';
        $contact = '';
        if ($error !== '' && $status !== backup::SYNCED) {
            if ($error === 'storage_limit_reached') {
                $message = get_string('cloud:error:storage', 'theme_brandable');
                $contact = self::support_url();
            } else if ($error === 'plan_required') {
                $message = get_string('cloud:error:plan', 'theme_brandable');
            } else if (in_array($error, ['rate_limited', 'http_429'], true)) {
                $message = get_string('cloud:error:ratelimited', 'theme_brandable');
            } else if (in_array($error, self::UNAVAILABLE, true)) {
                $message = get_string('cloud:error:unavailable', 'theme_brandable');
            } else if (get_string_manager()->string_exists('cloud:error:' . $error, 'theme_brandable')) {
                $message = get_string('cloud:error:' . $error, 'theme_brandable');
            } else {
                $message = get_string('cloud:error:other', 'theme_brandable', s($error));
            }
        }
        return [
            'status' => $status,
            'number' => (int) ($version->cloudnumber ?? 0),
            'synced' => $status === backup::SYNCED,
            'pending' => $status === backup::PENDING,
            'stopped' => in_array($status, [backup::FAILED, backup::PAUSED], true),
            'errormessage' => $message,
            'contacturl' => $contact,
        ];
    }

    /**
     * Returns the contact of the support (storage limit): urls.support of the account, else the billing contact.
     *
     * @return string
     */
    private static function support_url(): string {
        try {
            $urls = account::get()['urls'] ?? [];
        } catch (api_exception $e) {
            return '';
        }
        foreach (['support', 'billingContact'] as $name) {
            if (preg_match('~^https?://~i', (string) ($urls[$name] ?? ''))) {
                return (string) $urls[$name];
            }
        }
        return '';
    }

    /**
     * Describes the backup of a version.
     *
     * @return external_single_structure
     */
    public static function structure(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'pending, synced, failed, paused, or empty without cloud'),
            'number' => new external_value(PARAM_INT, 'Number of the version in the project, 0 if not stored'),
            'synced' => new external_value(PARAM_BOOL, 'Stored in the cloud'),
            'pending' => new external_value(PARAM_BOOL, 'Waiting to be stored (sent again by itself)'),
            'stopped' => new external_value(PARAM_BOOL, 'Stopped: sent again by the administrator only'),
            'errormessage' => new external_value(PARAM_RAW, 'Why it is not stored, empty if nothing to say'),
            'contacturl' => new external_value(PARAM_RAW, 'Contact of the support (storage limit), empty otherwise'),
        ], 'Backup in the cloud of the Pro offer');
    }
}
