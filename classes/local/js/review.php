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

namespace theme_brandable\local\js;

use theme_brandable\local\account\account;
use theme_brandable\local\account\api_client;
use theme_brandable\local\account\api_exception;
use theme_brandable\local\generator\generation_context;
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\resolver;
use theme_brandable\local\overrides\revision_manager;

/**
 * Review of the generated JavaScript before publishing (spec B13): each module of the draft whose source differs from
 * the published version needs a review of its exact source (sha256) and target by a user managing the JavaScript
 * (managejs), recorded on the site, valid with no time limit. Publishing is refused server side until then.
 *
 * The security review of Brandable (POST /js/review, contract 12.6) only helps: it never counts as a review.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class review {
    /** @var string Setting: modules of the last import (hashes and time), reviewed again after it (spec B15). */
    public const IMPORT_CONFIG = 'brandable_jsimport';

    /**
     * Returns the sha256 of the source of a module.
     *
     * @param string $content Source.
     * @return string
     */
    public static function hash(string $content): string {
        return hash('sha256', $content);
    }

    /**
     * Returns the modules of a revision whose source differs from the published version, with their review.
     *
     * @param int|null $revisionid Revision (the draft when null).
     * @return array[] By key of the entry: ['key', 'target', 'scope' (scope), 'content', 'published' (source of the
     *                 same variant in the published version, null when new), 'entry', 'hash', 'reviewed' (bool)].
     */
    public static function get_changed(?int $revisionid = null): array {
        global $DB;
        $draft = resolver::get_map($revisionid ?? revision_manager::get_draft())[change::TYPE_JS] ?? [];
        $published = resolver::get_map(revision_manager::get_published())[change::TYPE_JS] ?? [];
        $changed = [];
        foreach ($draft as $key => $entry) {
            $content = (string) $entry['content'];
            $before = isset($published[$key]) ? (string) $published[$key]['content'] : null;
            if ($before === $content) {
                continue;
            }
            $changed[$key] = [
                'key' => (string) $key,
                'target' => (string) $entry['target'],
                'scope' => resolver::entry_scope($entry),
                'content' => $content,
                'published' => $before,
                'entry' => $entry,
                'hash' => self::hash($content),
                'reviewed' => false,
            ];
        }
        if ($changed) {
            [$sql, $params] = $DB->get_in_or_equal(array_column($changed, 'hash'));
            $reviews = $DB->get_records_select(
                'theme_brandable_jsreview',
                "contenthash $sql",
                $params,
                '',
                'id, contenthash, target, timecreated'
            );
            // A module imported from another site is reviewed again here, after the import.
            $import = json_decode((string) get_config('theme_brandable', self::IMPORT_CONFIG), true);
            $importedat = is_array($import) ? array_fill_keys((array) ($import['hashes'] ?? []), (int) ($import['at'] ?? 0)) : [];
            foreach ($changed as $key => $module) {
                $since = $importedat[$module['hash']] ?? 0;
                foreach ($reviews as $review) {
                    if (
                        $review->contenthash === $module['hash'] && $review->target === $module['target']
                            && (int) $review->timecreated >= $since
                    ) {
                        $changed[$key]['reviewed'] = true;
                    }
                }
            }
        }
        return $changed;
    }

    /**
     * Whether the current user can record a review: those managing the generated JavaScript (managejs), as the
     * modules run with the rights of every visitor. Those who edit the theme only see what is to review.
     *
     * @return bool
     */
    public static function can_review(): bool {
        return has_capability(consent::CAPABILITY, \context_system::instance());
    }

    /**
     * Records that the current user reviewed the source of a module of the draft.
     *
     * @param string $key Key of the entry (target and scope).
     * @param string $hash sha256 of the source the user read (the draft may have changed meanwhile).
     * @throws \required_capability_exception Without managejs.
     * @throws \moodle_exception When the module is not one to review, or its source changed.
     */
    public static function record(string $key, string $hash): void {
        global $DB, $USER;
        require_capability(consent::CAPABILITY, \context_system::instance());
        $module = self::get_changed()[$key] ?? null;
        if ($module === null) {
            throw new \moodle_exception('error:jsnoreview', 'theme_brandable');
        }
        if (!hash_equals($module['hash'], $hash)) {
            throw new \moodle_exception('error:jsreviewchanged', 'theme_brandable');
        }
        if ($module['reviewed']) {
            return;
        }
        $DB->insert_record('theme_brandable_jsreview', (object) [
            'contenthash' => $module['hash'],
            'target' => $module['target'],
            'userid' => (int) $USER->id,
            'timecreated' => time(),
        ]);
    }

    /**
     * Records the modules of an import: their reviews made before it no longer count (spec B15, "reviewed again on
     * the site that imports").
     *
     * @param string[] $hashes sha256 of the sources imported.
     */
    public static function mark_imported(array $hashes): void {
        set_config(
            self::IMPORT_CONFIG,
            json_encode(['at' => time(), 'hashes' => array_values(array_unique($hashes))]),
            'theme_brandable'
        );
    }

    /**
     * Records the modules of a revision now published on this site (spec C12: code never published here is reviewed
     * again when it comes from the cloud).
     *
     * @param int $revisionid Revision published.
     */
    public static function record_published(int $revisionid): void {
        global $DB;
        foreach (resolver::get_map($revisionid)[change::TYPE_JS] ?? [] as $entry) {
            $hash = self::hash((string) $entry['content']);
            if (!$DB->record_exists('theme_brandable_jspublished', ['contenthash' => $hash, 'target' => $entry['target']])) {
                $DB->insert_record('theme_brandable_jspublished', (object) ['contenthash' => $hash,
                    'target' => (string) $entry['target'], 'timecreated' => time()]);
            }
        }
    }

    /**
     * Whether a module was published on this site (the same source and target).
     *
     * @param string $hash sha256 of the source.
     * @param string $target Target.
     * @return bool
     */
    public static function was_published(string $hash, string $target): bool {
        global $DB;
        return $DB->record_exists('theme_brandable_jspublished', ['contenthash' => $hash, 'target' => $target]);
    }

    /**
     * Refuses to publish a revision while a module changed since the published version is not reviewed.
     *
     * @param int $revisionid Revision about to be published.
     * @throws \moodle_exception error:jsnotreviewed, with the targets.
     */
    public static function require_reviewed(int $revisionid): void {
        $missing = array_filter(self::get_changed($revisionid), fn(array $module) => !$module['reviewed']);
        if ($missing) {
            throw new \moodle_exception(
                'error:jsnotreviewed',
                'theme_brandable',
                '',
                implode(', ', array_unique(array_column($missing, 'target')))
            );
        }
    }

    /**
     * Whether the offer of the site includes the security review of Brandable (plan.limits.jsReview).
     *
     * @return bool
     */
    public static function can_ask_brandable(): bool {
        if (consent::is_mock()) {
            return false;
        }
        try {
            return !empty(account::get()['plan']['limits']['jsReview']);
        } catch (api_exception $e) {
            return false;
        }
    }

    /**
     * Asks Brandable for its security review of a module of the draft (POST /js/review, no credit). An aid to the
     * review only: its notice is always shown with it.
     *
     * @param string $key Key of the entry.
     * @param api_client|null $client Client of the API.
     * @return array ['report' => array, 'explanation' => string, 'concerns' => string[], 'notice' => string]
     * @throws api_exception plan_required, signature_invalid, rate_limited, service_unavailable...
     * @throws \moodle_exception When the module is not one of the draft.
     */
    public static function ask_brandable(string $key, ?api_client $client = null): array {
        $entry = resolver::get_map(revision_manager::get_draft())[change::TYPE_JS][$key] ?? null;
        if ($entry === null) {
            throw new \moodle_exception('error:jsnoreview', 'theme_brandable');
        }
        try {
            $data = ($client ?? new api_client())->request('POST', '/js/review', [
                'target' => (string) $entry['target'],
                'content' => (string) $entry['content'],
                'signature' => (string) ($entry['signature'] ?? ''),
                'lang' => generation_context::get_lang(),
            ]);
        } catch (api_exception $e) {
            account::handle_error($e->apicode);
            throw $e;
        }
        $notice = trim((string) ($data['notice'] ?? ''));
        return [
            'report' => is_array($data['report'] ?? null) ? $data['report'] : [],
            'explanation' => (string) ($data['explanation'] ?? ''),
            'concerns' => array_values(array_map('strval', array_filter((array) ($data['concerns'] ?? []), 'is_scalar'))),
            // Always shown: never presented as a validation (contract 12.6).
            'notice' => $notice !== '' ? $notice : get_string('jsreview:defaultnotice', 'theme_brandable'),
        ];
    }
}
