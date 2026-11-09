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

use stdClass;
use theme_brandable\local\account\api_client;
use theme_brandable\local\account\api_exception;
use theme_brandable\local\overrides\revision_manager;

/**
 * Backup of the versions in the cloud (contract 13.4, spec C3 to C5, C7): each version published in Moodle is put in a
 * queue with its snapshot, then sent (assets first), at once and again by the task cloud_sync until it is stored.
 *
 * A publication never depends on the cloud: nothing here throws into it.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup {
    /** @var string Table of the queue. */
    public const TABLE = 'theme_brandable_cloudqueue';

    /** @var string Status of a version waiting to be stored. */
    public const PENDING = 'pending';

    /** @var string Status of a version stored in the cloud. */
    public const SYNCED = 'synced';

    /** @var string Refused by Brandable (sent again by the administrator only). */
    public const FAILED = 'failed';

    /** @var string Stopped until the administrator acts (storage limit, plan): later versions wait too. */
    public const PAUSED = 'paused';

    /** @var int nextattempt of a row only sent again by the administrator. */
    public const STOPPED = -1;

    /** @var string Setting: origin of the draft (restore or pull, spec section 2). */
    public const ORIGIN_CONFIG = 'brandable_cloudorigin';

    /** @var string Setting: environment whose catch-up was queued. */
    public const BACKFILL_CONFIG = 'brandable_cloudbackfill';

    /** @var string[] Failures of the service, or a plugin to update (426): sent again later. */
    public const RETRY_CODES = ['connection_failed', 'cloud_unavailable', 'rate_limited', 'service_unavailable', 'internal_error',
        'plugin_upgrade_required', 'http_429', 'http_500', 'http_502', 'http_503', 'http_504'];

    /** @var string[] Failures stopping the queue until the administrator acts. */
    public const PAUSE_CODES = ['storage_limit_reached', 'plan_required', 'site_not_connected', 'site_mismatch'];

    /** @var int Longest wait between two attempts, in seconds. */
    public const MAX_DELAY = 6 * HOURSECS;

    /** @var int Sendings again of a version after 409 assets_missing. */
    private const ASSETS_RETRIES = 2;

    /**
     * After a version is published in Moodle (publication, previous version again, import): queued and marked
     * pending when the cloud is full. Never throws.
     *
     * @param int $versionid Local version now published.
     * @param array $origin Origin (see snapshot::build()).
     * @param string $sessionid Session that produced it, empty if none.
     */
    public static function on_version(int $versionid, array $origin, string $sessionid = ''): void {
        global $DB;
        try {
            if (cloud::get_access() !== cloud::FULL) {
                return;
            }
            $version = $DB->get_record('theme_brandable_version', ['id' => $versionid], '*', MUST_EXIST);
            self::enqueue($version, $origin, true, $sessionid);
        } catch (\Throwable $e) {
            debugging('theme_brandable: version not queued for the cloud: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Returns the origin of a publication of the draft (spec section 2): restore or pull when the draft is the one
     * restored or pulled, unchanged; publish with the version it started from when changed since; publish otherwise.
     * The origin kept with the draft is forgotten.
     *
     * @param int $draft Draft published.
     * @return array
     */
    public static function origin_of_draft(int $draft): array {
        $origin = json_decode((string) get_config('theme_brandable', self::ORIGIN_CONFIG), true);
        unset_config(self::ORIGIN_CONFIG, 'theme_brandable');
        if (!is_array($origin) || empty($origin['versionId'])) {
            return ['kind' => 'publish'];
        }
        if ((int) ($origin['revision'] ?? 0) === $draft) {
            return array_intersect_key($origin, array_flip(['kind', 'versionId', 'proposalId']));
        }
        return ['kind' => 'publish', 'versionId' => $origin['versionId']];
    }

    /**
     * Puts a version in the queue: its snapshot now (Moodle keeps two versions only), a new UUID for this sending.
     *
     * @param stdClass $version Local version.
     * @param array $origin Origin.
     * @param bool $published Whether it becomes the version of this environment.
     * @param string $sessionid Session that produced it, empty if none.
     * @return stdClass Row of the queue.
     */
    public static function enqueue(stdClass $version, array $origin, bool $published, string $sessionid = ''): stdClass {
        global $DB;
        $clientversionid = \core\uuid::generate();
        [$payload, $files] = snapshot::build($version, $origin, $published, $clientversionid, $sessionid);
        $row = (object) [
            'versionid' => (int) $version->id,
            'clientversionid' => $clientversionid,
            'userid' => (int) $version->userid,
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'files' => json_encode($files),
            'attempts' => 0,
            'nextattempt' => 0,
            'lasterror' => null,
            'timecreated' => time(),
        ];
        $row->id = $DB->insert_record(self::TABLE, $row);
        $DB->update_record('theme_brandable_version', (object) ['id' => $version->id, 'clientversionid' => $clientversionid,
            'cloudstatus' => self::PENDING, 'clouderror' => null]);
        return $row;
    }

    /**
     * Sends the queue after the response of the request is sent (spec C3: a publication never waits for the cloud):
     * under PHP-FPM, at the end of the request, once the response is closed (fastcgi_finish_request); in any case, an
     * ad-hoc task run by the next cron (seconds with cron.php --keep-alive), which finds the queue empty when already
     * sent. Never throws.
     */
    public static function send_after_response(): void {
        global $DB;
        try {
            if (!$DB->record_exists_select(self::TABLE, 'nextattempt >= 0')) {
                return;
            }
            $task = new \theme_brandable\task\cloud_send();
            \core\task\manager::queue_adhoc_task($task, true);
            if (function_exists('fastcgi_finish_request') && !(defined('CLI_SCRIPT') && CLI_SCRIPT) && !PHPUNIT_TEST) {
                \core_shutdown_manager::register_function([self::class, 'send_at_shutdown']);
            }
        } catch (\Throwable $e) {
            debugging('theme_brandable: cloud sending not planned: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * At the end of the request (PHP-FPM): the response is closed first, then the queue is sent.
     */
    public static function send_at_shutdown(): void {
        try {
            \core\session\manager::write_close();
            fastcgi_finish_request();
            if (cloud::get_access() === cloud::FULL) {
                self::process(60);
            }
        } catch (\Throwable $e) {
            debugging('theme_brandable: cloud not reached: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Sends the queue in order, within a time budget: the rows due, until one stops the queue.
     *
     * @param int $budget Seconds.
     * @param api_client|null $client Client of the API.
     * @return array Counters: sent, waiting, stopped.
     */
    public static function process(int $budget = 20, ?api_client $client = null): array {
        global $DB;
        $counts = ['sent' => 0, 'waiting' => 0, 'stopped' => 0];
        $lock = \core\lock\lock_config::get_lock_factory('theme_brandable')->get_lock('cloud_sync', 0);
        if (!$lock) {
            // Another request or the task is sending.
            return $counts;
        }
        try {
            // Stopped by the storage limit or the plan: nothing is sent until the administrator acts (a version
            // refused alone does not stop the next ones).
            [$insql, $params] = $DB->get_in_or_equal(self::PAUSE_CODES);
            $paused = $DB->record_exists_select(
                self::TABLE,
                "nextattempt = ? AND lasterror $insql",
                array_merge([self::STOPPED], $params)
            );
            if ($paused) {
                return $counts;
            }
            $client = $client ?? cloud::client();
            $until = time() + $budget;
            $rows = $DB->get_records_select(self::TABLE, 'nextattempt >= 0', null, 'id ASC');
            foreach ($rows as $row) {
                if ((int) $row->nextattempt > time()) {
                    // Order kept: nothing after a version waiting for its next attempt.
                    $counts['waiting']++;
                    break;
                }
                if (time() >= $until) {
                    break;
                }
                $result = self::send($row, $client);
                $counts[$result === 'sent' ? 'sent' : ($result === 'retry' ? 'waiting' : 'stopped')]++;
                if ($result === 'retry' || $result === self::PAUSED) {
                    break;
                }
            }
        } finally {
            $lock->release();
        }
        if ($counts['sent']) {
            cloud::clear_state();
        }
        return $counts;
    }

    /**
     * Sends a version: the files Brandable does not have, then the version (again after 409 assets_missing).
     *
     * @param stdClass $row Row of the queue.
     * @param api_client $client Client of the API.
     * @return string sent, retry, paused or failed.
     */
    private static function send(stdClass $row, api_client $client): string {
        global $DB;
        $payload = json_decode($row->payload, true);
        $files = json_decode($row->files, true) ?: [];
        try {
            if ($payload['assets']) {
                $check = $client->request('POST', '/cloud/assets/check', ['assets' => array_map(fn($asset) => [
                    'sha256' => $asset['sha256'], 'size' => $asset['size'], 'mime' => $asset['mime']], $payload['assets'])]);
                self::upload((array) ($check['missing'] ?? []), $files, $client);
            }
            for ($attempt = 0;; $attempt++) {
                try {
                    $stored = $client->request('POST', '/cloud/versions', $payload);
                    break;
                } catch (api_exception $e) {
                    if ($e->apicode !== 'assets_missing' || $attempt >= self::ASSETS_RETRIES) {
                        throw $e;
                    }
                    self::upload((array) ($e->details['missing'] ?? []), $files, $client);
                }
            }
        } catch (api_exception $e) {
            return self::failed($row, $e->apicode);
        }
        if ($row->versionid && $DB->record_exists('theme_brandable_version', ['id' => $row->versionid])) {
            $DB->update_record('theme_brandable_version', (object) [
                'id' => $row->versionid,
                'cloudversionid' => \core_text::substr((string) ($stored['versionId'] ?? ''), 0, 36) ?: null,
                'cloudnumber' => isset($stored['number']) ? (int) $stored['number'] : null,
                'cloudstatus' => self::SYNCED,
                'clouderror' => null,
            ]);
        }
        $DB->delete_records(self::TABLE, ['id' => $row->id]);
        return 'sent';
    }

    /**
     * Uploads files Brandable does not have (raw body, contract 13.3).
     *
     * @param string[] $missing sha256.
     * @param array $files Where each file is read.
     * @param api_client $client Client of the API.
     * @throws api_exception asset_changed when a file is no longer in the library as it was.
     */
    private static function upload(array $missing, array $files, api_client $client): void {
        foreach ($missing as $sha256) {
            $file = isset($files[$sha256]) ? snapshot::read_file((string) $sha256, $files[$sha256]) : null;
            if ($file === null) {
                throw new api_exception('asset_changed', get_string('cloud:error:asset_changed', 'theme_brandable'));
            }
            $client->request('PUT', '/cloud/assets/' . $sha256, null, true, $file['content'], $file['mime']);
        }
    }

    /**
     * Records a failed sending: again later, stopped (paused) or refused.
     *
     * @param stdClass $row Row of the queue.
     * @param string $code Error code.
     * @return string retry, paused or failed.
     */
    private static function failed(stdClass $row, string $code): string {
        global $DB;
        $attempts = (int) $row->attempts + 1;
        if (in_array($code, self::RETRY_CODES, true)) {
            $result = 'retry';
            $status = self::PENDING;
            $next = time() + min(self::MAX_DELAY, 60 * (2 ** min($attempts, 10)));
        } else {
            $result = in_array($code, self::PAUSE_CODES, true) ? self::PAUSED : self::FAILED;
            $status = $result;
            $next = self::STOPPED;
        }
        $DB->update_record(self::TABLE, (object) ['id' => $row->id, 'attempts' => $attempts, 'nextattempt' => $next,
            'lasterror' => \core_text::substr($code, 0, 100)]);
        if ($row->versionid && $DB->record_exists('theme_brandable_version', ['id' => $row->versionid])) {
            $DB->update_record('theme_brandable_version', (object) ['id' => $row->versionid, 'cloudstatus' => $status,
                'clouderror' => \core_text::substr($code, 0, 100)]);
        }
        return $result;
    }

    /**
     * Sends again the versions stopped (refused, storage limit, plan), at the request of the administrator.
     */
    public static function retry(): void {
        global $DB;
        foreach ($DB->get_records(self::TABLE, ['nextattempt' => self::STOPPED]) as $row) {
            $DB->update_record(self::TABLE, (object) ['id' => $row->id, 'nextattempt' => 0]);
            if ($row->versionid) {
                $DB->set_field('theme_brandable_version', 'cloudstatus', self::PENDING, ['id' => $row->versionid]);
            }
        }
    }

    /**
     * Catches up (contract 13.4, spec C7): when Brandable knows no version for this environment, the previous version
     * (not published) then the published one are queued, with their dates, once per environment; when the published
     * version was never sent (published while the cloud was not available), it is queued.
     *
     * @param array $state State of the project (GET /cloud/state).
     * @return int Versions queued.
     */
    public static function catch_up(array $state): int {
        global $DB;
        $current = revision_manager::get_current_version();
        if (($state['access'] ?? cloud::NONE) !== cloud::FULL || !$current || $DB->record_exists(self::TABLE, [])) {
            return 0;
        }
        $environment = (string) ($state['environment']['id'] ?? '');
        if (($state['environment']['published'] ?? null) === null) {
            if ($environment === '' || (string) get_config('theme_brandable', self::BACKFILL_CONFIG) === $environment) {
                return 0;
            }
            $count = 0;
            if ($previous = revision_manager::get_previous_version()) {
                self::enqueue($previous, ['kind' => 'backfill'], false);
                $count++;
            }
            self::enqueue($current, ['kind' => 'backfill'], true);
            set_config(self::BACKFILL_CONFIG, $environment, 'theme_brandable');
            return $count + 1;
        }
        if ((string) ($current->cloudstatus ?? '') === '') {
            self::enqueue($current, ['kind' => 'publish'], true);
            return 1;
        }
        return 0;
    }
}
