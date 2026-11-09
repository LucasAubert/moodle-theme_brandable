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

use theme_brandable\local\account\api_client;
use theme_brandable\local\account\api_exception;
use theme_brandable\local\assets\file_checker;
use theme_brandable\local\assets\library;
use theme_brandable\local\export\importer;
use theme_brandable\local\js\review;
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\revision_manager;
use theme_brandable\local\overrides\scope;
use theme_brandable\local\validation\validation_exception;

/**
 * Restoration of a version of the project (contract 13.5, spec C9 to C13): the same path for this environment
 * (restore) and another one (pull). The version is read with the Moodle release and the parent theme of the site, its
 * missing files downloaded and checked, its scopes found here, validated here, and becomes the draft of a new session:
 * nothing is published without the administrator.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore {
    /** @var int Largest file downloaded (contract 13.3). */
    public const MAX_FILE = 15 * 1024 * 1024;

    /** @var int Changes left out after a failed validation, then validated again (rounds). */
    private const VALIDATION_ROUNDS = 3;

    /** @var array Report: changes left out, assets, findings. */
    private array $report = ['removed' => [], 'blocked' => [], 'downloaded' => 0, 'renamed' => [], 'refused' => [],
        'findings' => []];

    /** @var callable Downloads a signed URL: (url) => content. */
    private $fetch;

    /**
     * Constructor.
     *
     * @param api_client|null $client Client of the API (key of the site).
     * @param callable|null $fetch Downloads a signed URL, without authentication (tests).
     */
    public function __construct(
        /** @var api_client|null Client of the API. */
        private ?api_client $client = null,
        ?callable $fetch = null,
    ) {
        $this->client = $client ?? cloud::client();
        $this->fetch = $fetch ?? [self::class, 'download'];
    }

    /**
     * Restores or pulls a version: the draft of a new session (the session in progress is deleted).
     *
     * @param string $versionid Version of the project.
     * @param string $proposalid Proposal it comes from, empty if none.
     * @return array ['draft' => revision id, 'kind' => restore or pull, 'number' => number of the project, 'report']
     * @throws api_exception Read of the version (403 plan_required, 404, 503...).
     * @throws \moodle_exception Nothing valid left in the version.
     */
    public function run(string $versionid, string $proposalid = ''): array {
        $version = $this->read($versionid);
        $state = cloud::get_state(true, $this->client);
        $pending = null;
        if ($proposalid !== '') {
            // Read again just before the pull: replaced, accepted or dismissed meanwhile (spec R9).
            $pending = proposals::pending($state);
            if ($pending === null || $pending['id'] !== $proposalid || $pending['versionid'] !== $versionid) {
                throw new \moodle_exception('cloud:error:proposalgone', 'theme_brandable');
            }
        }
        $here = (string) ($state['environment']['id'] ?? '');
        $kind = (string) ($version['version']['environment']['id'] ?? '') === $here && $proposalid === '' ? 'restore' : 'pull';

        $renamed = $this->assets($version, $versionid);
        $changes = importer::rename_slugs($this->changes($version['changes'] ?? [], $kind === 'restore'), $renamed);
        $this->report['findings'] = cloud::findings($version['findings'] ?? []);
        $finding = \theme_brandable\local\colour_modes::compatibility_finding(array_map(
            fn($change) => (string) $change->content,
            array_filter($changes, fn($change) => $change->type === change::TYPE_SCSS && !$change->deleted)
        ));
        if ($finding !== null) {
            $this->report['findings'][] = $finding;
        }
        $number = (int) ($version['version']['number'] ?? 0);
        $note = get_string($kind === 'restore' ? 'cloud:restorednote' : 'cloud:pullednote', 'theme_brandable', (object) [
            'number' => $number, 'note' => trim((string) ($version['version']['note'] ?? '')),
            // Pulled from the environment that proposed it, which may not be the one the version comes from.
            'environment' => (string) ($pending['from'] ?? ($version['version']['environment']['name'] ?? ''))]);
        $draft = $this->draft($changes, $note);

        // JavaScript never published here: reviewed again on this site, after this restoration (spec C12).
        $hashes = [];
        foreach ($changes as $change) {
            if ($change->type === change::TYPE_JS && !$change->deleted) {
                $hash = review::hash((string) $change->content);
                if (!review::was_published($hash, $change->target)) {
                    $hashes[] = $hash;
                }
            }
        }
        review::mark_imported($hashes);
        // Published as restore or pull while unchanged (spec section 2).
        set_config(backup::ORIGIN_CONFIG, json_encode(array_filter(['kind' => $kind, 'versionId' => $versionid,
            'proposalId' => $proposalid, 'revision' => $draft])), 'theme_brandable');
        return ['draft' => $draft, 'kind' => $kind, 'number' => $number, 'report' => $this->report];
    }

    /**
     * Reads the complete version, for the Moodle release and the parent theme of this site.
     *
     * @param string $versionid Version.
     * @return array
     * @throws api_exception
     */
    private function read(string $versionid): array {
        if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $versionid)) {
            throw new \invalid_parameter_exception('versionid');
        }
        return $this->client->request('GET', '/cloud/versions/' . $versionid . '?'
            . http_build_query(cloud::read_parameters(), '', '&'));
    }

    /**
     * Puts the files of the version in the library: those already here (same sha256) are used as they are, the others
     * downloaded, checked like an upload and stored as they were served (no new encoding: the same sha256). A slug
     * already used here by another file gets a new one.
     *
     * @param array $version Complete version.
     * @param string $versionid Version (read again when the signed URLs expired).
     * @return array Renamed slugs: slug of the version => slug here.
     */
    private function assets(array $version, string $versionid): array {
        global $DB;
        $renamed = [];
        $assets = (array) ($version['assets'] ?? []);
        $licences = [];
        foreach ($assets as $asset) {
            if (($asset['kind'] ?? '') === 'font_license') {
                $licences[\core_text::strtolower((string) ($asset['family'] ?? ''))] = $asset;
            }
        }
        foreach ($assets as $asset) {
            $kind = (string) ($asset['kind'] ?? '');
            $slug = (string) ($asset['slug'] ?? '');
            $sha256 = strtolower((string) ($asset['sha256'] ?? ''));
            if (
                !in_array($kind, ['image', 'font'], true) || !preg_match(library::SLUG_PATTERN, $slug)
                    || !preg_match('/^[0-9a-f]{64}$/', $sha256)
            ) {
                continue;
            }
            $same = $DB->get_records('theme_brandable_asset', ['sha256' => $sha256], 'id ASC', 'id, slug', 0, 1);
            if ($same) {
                $local = reset($same)->slug;
                if ($local !== $slug) {
                    $renamed[$slug] = $local;
                }
                continue;
            }
            try {
                $content = $this->fetch_asset($asset, $versionid);
                $path = make_request_directory() . '/asset';
                file_put_contents($path, $content);
                if ($kind === 'image') {
                    // The file as served (no new encoding); an SVG changed by the sanitising is refused (R2).
                    $checked = file_checker::check_image($path, $path);
                    if (!hash_equals($sha256, hash('sha256', $checked['files']['optimized']['content']))) {
                        throw new \moodle_exception('cloud:error:assetchanged', 'theme_brandable');
                    }
                } else {
                    $checked = file_checker::check_font($path);
                }
                $existing = $DB->record_exists('theme_brandable_asset', ['slug' => $slug]);
                $newslug = $existing ? library::suggest_slug($slug) : $slug;
                library::add($checked, ['slug' => $newslug, 'alt' => (string) ($asset['alt'] ?? ''),
                    'family' => (string) ($asset['family'] ?? ''), 'weight' => (int) ($asset['weight'] ?? 0),
                    'style' => (string) ($asset['style'] ?? ''), 'subset' => (string) ($asset['subset'] ?? ''),
                    'origin' => 'import', 'licence' => (string) ($asset['license'] ?? '')]);
                $this->report['downloaded']++;
                if ($newslug !== $slug) {
                    $renamed[$slug] = $newslug;
                    $this->report['renamed'][] = ['from' => $slug, 'to' => $newslug];
                }
                // The licence of the family, when this site has none.
                $family = \core_text::strtolower((string) ($asset['family'] ?? ''));
                if ($kind === 'font' && isset($licences[$family]) && !library::get_family((string) $asset['family'])) {
                    $text = file_checker::check_licence($this->fetch_asset($licences[$family], $versionid));
                    if (hash_equals(strtolower((string) $licences[$family]['sha256']), hash('sha256', $text))) {
                        library::set_family_licence(
                            (string) $asset['family'],
                            (string) ($licences[$family]['license'] ?? ''),
                            $text
                        );
                    }
                }
            } catch (\moodle_exception $e) {
                // Left out: its markers show nothing (unknown here), reported.
                $this->report['refused'][] = ['slug' => $slug, 'message' => $e->getMessage()];
            }
        }
        return $renamed;
    }

    /**
     * Downloads a file of the version; its signed URL read again once when it expired (10 minutes).
     *
     * @param array $asset Asset of the version.
     * @param string $versionid Version.
     * @return string Content, of the sha256 announced.
     * @throws \moodle_exception
     */
    private function fetch_asset(array $asset, string $versionid): string {
        $sha256 = strtolower((string) $asset['sha256']);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $url = (string) ($asset['url'] ?? '');
            $content = preg_match('~^https?://~i', $url) ? ($this->fetch)($url) : null;
            if ($content !== null && strlen($content) <= self::MAX_FILE && hash_equals($sha256, hash('sha256', $content))) {
                return $content;
            }
            // Expired or not the file announced: the URL read again.
            foreach ((array) ($this->read($versionid)['assets'] ?? []) as $fresh) {
                if (strtolower((string) ($fresh['sha256'] ?? '')) === $sha256) {
                    $asset = $fresh;
                }
            }
        }
        throw new \moodle_exception('cloud:error:download', 'theme_brandable');
    }

    /**
     * Downloads a signed URL (no authentication headers, contract 13.5).
     *
     * @param string $url URL.
     * @return string|null Content, null on an error.
     */
    public static function download(string $url): ?string {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $content = $curl->get($url, [], ['CURLOPT_TIMEOUT' => 60, 'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_MAXFILESIZE' => self::MAX_FILE]);
        $status = (int) ($curl->get_info()['http_code'] ?? 0);
        return $curl->get_errno() || $status !== 200 ? null : (string) $content;
    }

    /**
     * Builds the changes of the version for this site: the scopes of courses and activities found here, modules refused
     * by Brandable left out (spec C11, C12).
     *
     * @param array $items Changes of the version.
     * @param bool $sameenvironment Whether the version comes from this environment (ids kept without scopeRef).
     * @return change[]
     */
    private function changes(array $items, bool $sameenvironment): array {
        $changes = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = (string) ($item['type'] ?? '');
            $target = (string) ($item['target'] ?? '');
            $scope = $this->scope($item, $sameenvironment);
            if ($scope === null) {
                $this->report['removed'][] = ['type' => $type, 'target' => $target,
                    'reason' => get_string('cloud:report:noscope', 'theme_brandable', s(cloud::scope_label($item)))];
                continue;
            }
            if ($type === change::TYPE_JS && (!empty($item['blocked']) || (string) ($item['compiled'] ?? '') === '')) {
                // Refused by the analysis of Brandable: never run, never taken (Q3).
                $this->report['blocked'][] = $target;
                continue;
            }
            $data = ['type' => $type, 'target' => $target, 'content' => (string) ($item['content'] ?? ''),
                'scope' => $scope->to_array()];
            if ($type === change::TYPE_TEMPLATE) {
                $data['sourceHash'] = $item['sourceHash'] ?? null;
            }
            if ($type === change::TYPE_JS) {
                // The compilation of Brandable only (contract 13.4); unsigned when changed out of Brandable.
                $data['compiled'] = (string) $item['compiled'];
                $data['report'] = is_array($item['report'] ?? null) ? $item['report'] : [];
                $data['signature'] = ($item['signature'] ?? null) !== null ? (string) $item['signature'] : null;
            }
            $changes[] = change::from_array($data);
        }
        return $changes;
    }

    /**
     * Returns the scope of a change here: courses by idnumber then shortname, activities by idnumber; without scopeRef,
     * the id is kept for this environment only.
     *
     * @param array $item Change of the version.
     * @param bool $sameenvironment Whether the version comes from this environment.
     * @return scope|null Null when the course or the activity is not found here.
     */
    private function scope(array $item, bool $sameenvironment): ?scope {
        global $DB;
        $level = (string) ($item['scope']['level'] ?? scope::SITE);
        $value = (string) ($item['scope']['value'] ?? '');
        if (!in_array($level, [scope::COURSE, scope::CM], true)) {
            return scope::from_array(['level' => $level, 'value' => $value]);
        }
        $ref = is_array($item['scopeRef'] ?? null) ? $item['scopeRef'] : [];
        $id = null;
        if ($level === scope::COURSE) {
            foreach (['idnumber', 'shortname'] as $field) {
                if ($id === null && trim((string) ($ref[$field] ?? '')) !== '') {
                    $wanted = trim((string) $ref[$field]);
                    $ids = $DB->get_fieldset_select('course', 'id', "$field = ? AND id <> ?", [$wanted, SITEID]);
                    $id = count($ids) === 1 ? (int) reset($ids) : null;
                }
            }
        } else if (trim((string) ($ref['idnumber'] ?? '')) !== '') {
            $ids = $DB->get_fieldset_select('course_modules', 'id', 'idnumber = ?', [trim((string) $ref['idnumber'])]);
            $id = count($ids) === 1 ? (int) reset($ids) : null;
        }
        if (
            $id === null && !$ref && $sameenvironment && ctype_digit($value)
                && $DB->record_exists($level === scope::COURSE ? 'course' : 'course_modules', ['id' => (int) $value])
        ) {
            $id = (int) $value;
        }
        return $id === null ? null : new scope($level, (string) $id);
    }

    /**
     * Makes the draft: validated here like a generation; a change refused here is left out and reported, the rest
     * validated again.
     *
     * @param change[] $changes Changes.
     * @param string $note Summary of the revision.
     * @return int Draft revision.
     * @throws \moodle_exception When nothing valid is left.
     */
    private function draft(array $changes, string $note): int {
        for ($round = 0;; $round++) {
            try {
                return revision_manager::import_as_draft($changes, $note);
            } catch (validation_exception $e) {
                $refused = array_unique(array_filter(array_column($e->errors, 'target')));
                if (!$refused || $round >= self::VALIDATION_ROUNDS) {
                    throw new \moodle_exception('cloud:error:invalidversion', 'theme_brandable', '', implode(
                        '; ',
                        array_map(fn($error) => trim($error['target'] . ' ' . $error['message']), $e->errors)
                    ));
                }
                foreach ($e->errors as $error) {
                    if (in_array($error['target'], $refused, true)) {
                        $this->report['removed'][] = ['type' => '', 'target' => (string) $error['target'],
                            'reason' => (string) $error['message']];
                    }
                }
                $changes = array_values(array_filter($changes, fn(change $change) => !in_array($change->target, $refused, true)));
            }
        }
    }
}
