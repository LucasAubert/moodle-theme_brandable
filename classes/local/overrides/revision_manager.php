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

namespace theme_brandable\local\overrides;

use core\lock\lock;
use core\lock\lock_config;
use moodle_exception;
use theme_brandable\local\validation\validation_exception;
use theme_brandable\local\validation\validator;

/**
 * Sessions and versions of the Brandable overrides.
 *
 * A session is the work on a draft: linear, editor-like revisions (undo, redo, restore) on top of the version it
 * started from (session base). Publishing turns the draft into a version: a complete, self-contained revision
 * (parent 0); the revisions and generations of the session are then deleted and a new session starts. Two versions
 * are kept: the published one and the previous one (one step back).
 *
 * Revisions are immutable. Pointers (Brandable config) give the state:
 * - draft: what the editor shows and the preview renders;
 * - published: what the site renders (revision of the current version);
 * - redo head: tip of the branch left by undo, cleared as soon as a new revision is created;
 * - session id (UUID sent with every generation) and session base.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class revision_manager {
    /** @var string Config holding the draft revision id. */
    public const CONFIG_DRAFT = 'brandable_draftrevision';
    /** @var string Config holding the published revision id. */
    public const CONFIG_PUBLISHED = 'brandable_publishedrevision';
    /** @var string Config holding the redo head revision id. */
    public const CONFIG_REDOHEAD = 'brandable_redohead';
    /** @var string Config holding the id of the current session (UUID v4). */
    public const CONFIG_SESSION = 'brandable_sessionid';
    /** @var string Config holding the revision the current session started from. */
    public const CONFIG_SESSIONBASE = 'brandable_sessionbase';

    /** @var int Maximum length of a version note. */
    public const MAX_NOTE = 1000;

    /** @var int Seconds after which a running generation no longer blocks publishing (stalled). */
    private const RUNNING_TIMEOUT = 1800;

    /** @var int Seconds to wait for the revisions lock. */
    private const LOCK_TIMEOUT = 10;

    /**
     * Returns the draft revision id.
     *
     * @return int
     */
    public static function get_draft(): int {
        return (int) get_config('theme_brandable', self::CONFIG_DRAFT);
    }

    /**
     * Returns the published revision id.
     *
     * @return int
     */
    public static function get_published(): int {
        return (int) get_config('theme_brandable', self::CONFIG_PUBLISHED);
    }

    /**
     * Returns the id of the current session, started on first use.
     *
     * @return string UUID v4.
     */
    public static function get_session_id(): string {
        $id = (string) get_config('theme_brandable', self::CONFIG_SESSION);
        if ($id === '') {
            $id = \core\uuid::generate();
            set_config(self::CONFIG_SESSION, $id, 'theme_brandable');
        }
        return $id;
    }

    /**
     * Returns the revision the current session started from (its version, 0 before the first one).
     *
     * @return int
     */
    public static function get_session_base(): int {
        return (int) get_config('theme_brandable', self::CONFIG_SESSIONBASE);
    }

    /**
     * Returns the revisions of the session leading to the draft, from the draft down to the session base (excluded).
     *
     * @return int[]
     */
    public static function get_session_path(): array {
        $path = resolver::get_ancestry(self::get_draft());
        $base = array_search(self::get_session_base(), $path, true);
        return $base === false ? $path : array_slice($path, 0, $base);
    }

    /**
     * Returns the redo head revision id (0 when nothing can be redone).
     *
     * @return int
     */
    public static function get_redo_head(): int {
        return (int) get_config('theme_brandable', self::CONFIG_REDOHEAD);
    }

    /**
     * Validates and stores a new revision on top of the draft, which then points to it.
     *
     * @param change[] $changes Changes.
     * @param string $prompt Prompt that produced the changes.
     * @param string $summary Summary of the changes.
     * @param int|null $userid Author, current user when null.
     * @param int|null $expecteddraft When set, fails if the draft moved since the changes were generated.
     * @return int New revision id.
     * @throws validation_exception When the changes are invalid: the draft is left unchanged.
     */
    public static function create(
        array $changes,
        string $prompt,
        string $summary,
        ?int $userid = null,
        ?int $expecteddraft = null
    ): int {
        global $DB, $USER;

        $lock = self::lock();
        try {
            $draft = self::get_draft();
            if ($expecteddraft !== null && $expecteddraft !== $draft) {
                throw new moodle_exception('error:draftchanged', 'theme_brandable');
            }
            $records = validator::validate($changes, resolver::get_map($draft));

            $transaction = $DB->start_delegated_transaction();
            $revisionid = $DB->insert_record('theme_brandable_revision', (object) [
                'parentrevisionid' => $draft,
                'sessionid' => self::get_session_id(),
                'userid' => $userid ?? (int) $USER->id,
                'prompt' => $prompt,
                'summary' => $summary,
                'timecreated' => time(),
            ]);
            foreach ($records as $sortorder => $record) {
                $record->revisionid = $revisionid;
                $record->sortorder = $sortorder;
                $DB->insert_record('theme_brandable_change', $record);
            }
            $transaction->allow_commit();

            self::set_pointer(self::CONFIG_DRAFT, $revisionid);
            self::set_pointer(self::CONFIG_REDOHEAD, 0);
            return $revisionid;
        } finally {
            $lock->release();
        }
    }

    /**
     * Moves an override to another scope: new revision with the same content under the new scope (the override
     * under the old scope is removed), validated as any revision.
     *
     * @param int $changeid Change whose override is moved; it must be the active override of the draft.
     * @param scope $newscope New scope.
     * @return int New revision id.
     * @throws validation_exception When the moved override is invalid (e.g. a pre/ chunk out of the site).
     */
    public static function rescope(int $changeid, scope $newscope): int {
        global $DB;

        $record = $DB->get_record('theme_brandable_change', ['id' => $changeid], '*', MUST_EXIST);
        $oldscope = scope::from_columns($record->scopelevel, $record->scopevalue);
        $draft = self::get_draft();
        $entry = resolver::get_map($draft)[$record->type][resolver::entry_key($record->target, $oldscope)] ?? null;
        if ($record->deleted || $entry === null || (int) $entry['revisionid'] !== (int) $record->revisionid) {
            throw new moodle_exception('error:cannotrescope', 'theme_brandable');
        }
        if ($oldscope->key() === $newscope->key()) {
            return $draft;
        }

        $changes = [
            new change($record->type, $record->target, null, true, null, false, $oldscope),
            new change(
                $record->type,
                $record->target,
                $entry['content'],
                false,
                $entry['sourcehash'],
                $record->type === change::TYPE_TEMPLATE,
                $newscope,
                $entry['compiled'] ?? null,
                $entry['report'] ?? null,
                $entry['signature'] ?? null
            ),
        ];
        $summary = get_string('scopechanged', 'theme_brandable', (object) [
            'target' => $record->target,
            'from' => $oldscope->label(),
            'to' => $newscope->label(),
        ]);
        return self::create($changes, '', $summary, null, $draft);
    }

    /**
     * Abandons a revision that failed the verification in the preview: the draft goes back to where the
     * generation started (the revision stays in the history, flagged as abandoned).
     *
     * @param int $revisionid Revision to abandon; it must be the draft.
     * @param int $basedraft Draft the generation started from.
     * @return bool Whether the revision was abandoned (false when the draft moved meanwhile).
     */
    public static function abandon(int $revisionid, int $basedraft): bool {
        global $DB;

        $lock = self::lock();
        try {
            if (self::get_draft() !== $revisionid) {
                return false;
            }
            $DB->set_field('theme_brandable_revision', 'abandoned', 1, ['id' => $revisionid]);
            self::set_pointer(self::CONFIG_DRAFT, $basedraft);
            self::set_pointer(self::CONFIG_REDOHEAD, 0);
            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether the draft can be undone.
     *
     * @return bool
     */
    public static function can_undo(): bool {
        return self::get_draft() !== self::get_session_base();
    }

    /**
     * Moves the draft to its parent revision.
     *
     * @return int New draft revision id.
     */
    public static function undo(): int {
        $lock = self::lock();
        try {
            $draft = self::get_draft();
            if ($draft === self::get_session_base()) {
                throw new moodle_exception('error:nothingtoundo', 'theme_brandable');
            }
            if (self::get_redo_head() === 0) {
                self::set_pointer(self::CONFIG_REDOHEAD, $draft);
            }
            $parent = self::get_parent($draft);
            self::set_pointer(self::CONFIG_DRAFT, $parent);
            return $parent;
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether an undone revision can be redone.
     *
     * @return bool
     */
    public static function can_redo(): bool {
        return self::get_redo_next(self::get_draft(), self::get_redo_head()) !== null;
    }

    /**
     * Moves the draft one step towards the redo head.
     *
     * @return int New draft revision id.
     */
    public static function redo(): int {
        $lock = self::lock();
        try {
            $head = self::get_redo_head();
            $next = self::get_redo_next(self::get_draft(), $head);
            if ($next === null) {
                throw new moodle_exception('error:nothingtoredo', 'theme_brandable');
            }
            self::set_pointer(self::CONFIG_DRAFT, $next);
            if ($next === $head) {
                self::set_pointer(self::CONFIG_REDOHEAD, 0);
            }
            return $next;
        } finally {
            $lock->release();
        }
    }

    /**
     * Points the draft to any revision of the session, or to its base (non destructive).
     *
     * Restoring an ancestor of the draft behaves like several undos: the branch stays redoable.
     *
     * @param int $revisionid Revision id (the session base, or a revision of the session).
     */
    public static function restore(int $revisionid): void {
        global $DB;

        $lock = self::lock();
        try {
            self::require_revision($revisionid);
            if (
                $revisionid !== self::get_session_base() && !$DB->record_exists(
                    'theme_brandable_revision',
                    ['id' => $revisionid, 'sessionid' => self::get_session_id()]
                )
            ) {
                throw new moodle_exception('error:unknownrevision', 'theme_brandable', '', $revisionid);
            }
            $draft = self::get_draft();
            $head = self::get_redo_head();
            if ($revisionid !== $draft && in_array($revisionid, self::get_ancestors($draft), true)) {
                // Keep the existing redo branch when it continues the current draft.
                $keephead = $head > 0 && in_array($draft, resolver::get_ancestry($head), true);
                self::set_pointer(self::CONFIG_REDOHEAD, $keephead ? $head : $draft);
            } else if ($revisionid !== $draft) {
                self::set_pointer(self::CONFIG_REDOHEAD, 0);
            }
            self::set_pointer(self::CONFIG_DRAFT, $revisionid);
        } finally {
            $lock->release();
        }
    }

    /**
     * Publishes the draft: it becomes the current version, the session is deleted and a new one starts.
     *
     * @param string $note Version note (trimmed to MAX_NOTE characters).
     * @param int|null $userid Author, current user when null.
     * @return int New version id.
     */
    public static function publish(string $note = '', ?int $userid = null): int {
        global $DB;

        $lock = self::lock();
        try {
            $draft = self::get_draft();
            $published = self::get_published();
            if ($draft === $published) {
                throw new moodle_exception('error:nothingtopublish', 'theme_brandable');
            }
            self::require_no_running_generation();
            // Every module changed since the published version reviewed, for its exact source (spec B13).
            \theme_brandable\local\js\review::require_reviewed($draft);
            $prompts = count(array_filter(
                self::get_session_entries(),
                fn(array $entry) => trim($entry['prompt']) !== ''
            ));

            $transaction = $DB->start_delegated_transaction();
            $revisionid = self::snapshot(
                resolver::get_map($draft),
                null,
                '',
                \core_text::substr(trim($note), 0, self::MAX_NOTE),
                null,
                $userid
            );
            $versionid = self::add_version($revisionid, $note, $prompts, $userid);
            $transaction->allow_commit();
            $sessionid = self::get_session_id();
            self::switch_version($revisionid, $versionid, $published);
        } finally {
            $lock->release();
        }
        // The cloud of the Pro offer, never in the way of the publication (spec C3).
        \theme_brandable\local\cloud\backup::on_version(
            $versionid,
            \theme_brandable\local\cloud\backup::origin_of_draft($draft),
            $sessionid
        );
        return $versionid;
    }

    /**
     * Publishes overrides read from outside (import of an exported theme) as a new version, validated as any
     * revision: the current version becomes the previous one, the session is deleted and a new one starts.
     *
     * @param change[] $changes Changes adding the overrides of the version (none: a version without overrides).
     * @param string $note Version note (trimmed to MAX_NOTE characters).
     * @param int|null $userid Author, current user when null.
     * @return int New version id.
     * @throws validation_exception When the changes are invalid: nothing is changed.
     */
    public static function import(array $changes, string $note, ?int $userid = null): int {
        global $DB, $USER;

        $lock = self::lock();
        try {
            self::require_no_running_generation();
            $records = $changes ? validator::validate($changes, resolver::empty_map()) : [];
            $published = self::get_published();
            $note = \core_text::substr(trim($note), 0, self::MAX_NOTE);

            $transaction = $DB->start_delegated_transaction();
            $revisionid = $DB->insert_record('theme_brandable_revision', (object) [
                'parentrevisionid' => 0,
                'sessionid' => null,
                'userid' => $userid ?? (int) $USER->id,
                'prompt' => '',
                'summary' => $note,
                'timecreated' => time(),
            ]);
            foreach ($records as $sortorder => $record) {
                $record->revisionid = $revisionid;
                $record->sortorder = $sortorder;
                $DB->insert_record('theme_brandable_change', $record);
            }
            $versionid = self::add_version($revisionid, $note, 0, $userid);
            $transaction->allow_commit();
            self::switch_version($revisionid, $versionid, $published);
        } finally {
            $lock->release();
        }
        \theme_brandable\local\cloud\backup::on_version($versionid, ['kind' => 'publish']);
        return $versionid;
    }

    /**
     * Imports overrides read from outside as the draft of a new session, not published (import of an exported theme
     * with JavaScript modules, spec B15): the draft replaces the published version (what it does not have is removed),
     * the session in progress is deleted. The modules must then be reviewed on this site before publishing, even when
     * the same code was reviewed here before ({@see \theme_brandable\local\js\review::mark_imported()}).
     *
     * @param change[] $changes Changes adding the overrides of the version.
     * @param string $note Note of the import (summary of the revision).
     * @param int|null $userid Author, current user when null.
     * @return int Draft revision.
     * @throws validation_exception When the changes are invalid: nothing is changed.
     */
    public static function import_as_draft(array $changes, string $note, ?int $userid = null): int {
        global $DB, $USER;

        $lock = self::lock();
        try {
            self::require_no_running_generation();
            $published = self::get_published();
            $base = resolver::get_map($published);
            $imported = [];
            foreach ($changes as $change) {
                $imported[$change->type][resolver::entry_key($change->target, $change->scope)] = true;
            }
            foreach ($base as $type => $entries) {
                foreach ($entries as $key => $entry) {
                    if (!isset($imported[$type][$key])) {
                        $changes[] = change::from_array(['type' => $type, 'target' => $entry['target'], 'deleted' => true,
                            'scope' => resolver::entry_scope($entry)->to_array()]);
                    }
                }
            }
            // Checked before anything changes.
            $records = validator::validate($changes, $base);

            $transaction = $DB->start_delegated_transaction();
            self::delete_session();
            self::start_session($published);
            $revisionid = $DB->insert_record('theme_brandable_revision', (object) [
                'parentrevisionid' => $published,
                'sessionid' => self::get_session_id(),
                'userid' => $userid ?? (int) $USER->id,
                'prompt' => '',
                'summary' => \core_text::substr(trim($note), 0, self::MAX_NOTE),
                'timecreated' => time(),
            ]);
            foreach ($records as $sortorder => $record) {
                $record->revisionid = $revisionid;
                $record->sortorder = $sortorder;
                $DB->insert_record('theme_brandable_change', $record);
            }
            $transaction->allow_commit();
            self::set_pointer(self::CONFIG_DRAFT, $revisionid);
            self::set_pointer(self::CONFIG_REDOHEAD, 0);
            return $revisionid;
        } finally {
            $lock->release();
        }
    }

    /**
     * Records a version (number following the last one).
     *
     * @param int $revisionid Complete revision of the version.
     * @param string $note Version note.
     * @param int $prompts Number of prompts of the session.
     * @param int|null $userid Author, current user when null.
     * @return int Version id.
     */
    private static function add_version(int $revisionid, string $note, int $prompts, ?int $userid): int {
        global $DB, $USER;

        return $DB->insert_record('theme_brandable_version', (object) [
            'revisionid' => $revisionid,
            'number' => (int) $DB->get_field_sql('SELECT MAX(number) FROM {theme_brandable_version}') + 1,
            'note' => \core_text::substr(trim($note), 0, self::MAX_NOTE),
            'prompts' => $prompts,
            'userid' => $userid ?? (int) $USER->id,
            'timecreated' => time(),
        ]);
    }

    /**
     * Publishes a new version: it becomes the current one, the one it replaces the previous one; the session is
     * deleted and a new one starts from it.
     *
     * @param int $revisionid Revision of the new version.
     * @param int $versionid New version.
     * @param int $published Revision published until now.
     */
    private static function switch_version(int $revisionid, int $versionid, int $published): void {
        global $DB;

        self::set_published($revisionid);
        \theme_brandable\local\js\review::record_published($revisionid);
        // Kept: the new version and the one it replaces.
        $keep = [$versionid];
        if ($previous = $DB->get_record('theme_brandable_version', ['revisionid' => $published], 'id')) {
            $keep[] = (int) $previous->id;
        }
        self::delete_session();
        self::start_session($revisionid);
        self::delete_versions_except($keep);
    }

    /**
     * Publishes the previous version again: it becomes the current one, and the current one the previous one.
     * The session in progress is deleted (its draft builds on the version left).
     */
    public static function revert_to_previous(): void {
        $lock = self::lock();
        try {
            $previous = self::get_previous_version();
            if (!$previous) {
                throw new moodle_exception('error:noprevious', 'theme_brandable');
            }
            self::require_no_running_generation();
            self::set_published((int) $previous->revisionid);
            self::delete_session();
            self::start_session((int) $previous->revisionid);
            \theme_brandable\local\js\review::record_published((int) $previous->revisionid);
        } finally {
            $lock->release();
        }
        // The same version again: restored in the cloud (the same version of the project when it was stored).
        \theme_brandable\local\cloud\backup::on_version((int) $previous->id, $previous->cloudversionid
            ? ['kind' => 'restore', 'versionId' => $previous->cloudversionid] : ['kind' => 'publish']);
    }

    /**
     * Discards the draft: the session is deleted and a new one starts from the published version.
     */
    public static function discard(): void {
        $lock = self::lock();
        try {
            self::require_no_running_generation();
            self::delete_session();
            self::start_session(self::get_published());
        } finally {
            $lock->release();
        }
    }

    /**
     * Returns the current version (the published one), if any.
     *
     * @return \stdClass|null Version record.
     */
    public static function get_current_version(): ?\stdClass {
        global $DB;
        return $DB->get_record('theme_brandable_version', ['revisionid' => self::get_published()]) ?: null;
    }

    /**
     * Returns the previous version (the one that can be published again), if any.
     *
     * @return \stdClass|null Version record.
     */
    public static function get_previous_version(): ?\stdClass {
        global $DB;
        $versions = $DB->get_records_select(
            'theme_brandable_version',
            'revisionid <> ?',
            [self::get_published()],
            'number DESC',
            '*',
            0,
            1
        );
        return $versions ? reset($versions) : null;
    }

    /**
     * Returns the exchanges of the session leading to the draft, oldest first (version note, generations history).
     *
     * @return array List of ['prompt', 'summary'].
     */
    public static function get_session_entries(): array {
        global $DB;

        $ids = self::get_session_path();
        if (!$ids) {
            return [];
        }
        $revisions = $DB->get_records_list('theme_brandable_revision', 'id', $ids, '', 'id, prompt, summary, abandoned');
        $entries = [];
        foreach (array_reverse($ids) as $id) {
            if (!$revisions[$id]->abandoned) {
                $entries[] = ['prompt' => (string) $revisions[$id]->prompt, 'summary' => (string) $revisions[$id]->summary];
            }
        }
        return $entries;
    }

    /**
     * Returns the revisions of the current session, most recent first.
     *
     * @return \stdClass[] Revision records with extra isdraft, ispublished and changecount properties.
     */
    public static function get_history(): array {
        global $DB;

        $sql = 'SELECT r.id, r.parentrevisionid, r.userid, r.prompt, r.summary, r.timecreated, r.abandoned,
                       COUNT(c.id) AS changecount
                  FROM {theme_brandable_revision} r
             LEFT JOIN {theme_brandable_change} c ON c.revisionid = r.id
                 WHERE r.sessionid = ?
              GROUP BY r.id, r.parentrevisionid, r.userid, r.prompt, r.summary, r.timecreated, r.abandoned
              ORDER BY r.id DESC';
        $revisions = $DB->get_records_sql($sql, [self::get_session_id()]);
        $draft = self::get_draft();
        $published = self::get_published();
        foreach ($revisions as $revision) {
            $revision->isdraft = ((int) $revision->id === $draft);
            $revision->ispublished = ((int) $revision->id === $published);
            $revision->changecount = (int) $revision->changecount;
            $revision->abandoned = (bool) $revision->abandoned;
        }
        return $revisions;
    }

    /**
     * Stores a revision bringing a map on top of another one: copies of the changes that built the entries
     * (content, hashes, scope, selectors), and deletions of the entries gone.
     *
     * Applied to $basemap, the revision gives $map, entries in the same order. A complete revision has no base.
     *
     * @param array $map Resolved map to reach.
     * @param array|null $basemap Map of the parent revision, null for a complete revision (parent 0).
     * @param string $prompt Prompt of the revision.
     * @param string $summary Summary of the revision.
     * @param string|null $sessionid Session of the revision, null for a version.
     * @param int|null $userid Author, current user when null.
     * @param int $parent Parent revision (the revision of $basemap).
     * @return int Revision id.
     */
    public static function snapshot(
        array $map,
        ?array $basemap,
        string $prompt,
        string $summary,
        ?string $sessionid,
        ?int $userid = null,
        int $parent = 0
    ): int {
        global $DB, $USER;

        $base = $basemap ?? resolver::empty_map();
        $sources = [];
        $records = [];
        foreach (change::TYPES as $type) {
            $entries = $map[$type] ?? [];
            $typerecords = self::diff($base[$type] ?? [], $entries, $type, $sources);
            // Same entries in the same order once applied; otherwise the type is rebuilt (deleted, then added in order).
            $applied = resolver::apply([$type => $base[$type] ?? []], $typerecords, 0)[$type];
            if (
                array_keys($applied) !== array_keys($entries)
                    || array_column($applied, 'contenthash') !== array_column($entries, 'contenthash')
            ) {
                $typerecords = [];
                foreach ($base[$type] ?? [] as $entry) {
                    $typerecords[] = self::deletion($type, $entry);
                }
                foreach ($entries as $entry) {
                    $typerecords[] = self::copy($type, $entry, $sources);
                }
            }
            $records = array_merge($records, $typerecords);
        }

        $revisionid = $DB->insert_record('theme_brandable_revision', (object) [
            'parentrevisionid' => $basemap === null ? 0 : $parent,
            'sessionid' => $sessionid,
            'userid' => $userid ?? (int) $USER->id,
            'prompt' => $prompt,
            'summary' => $summary,
            'timecreated' => time(),
        ]);
        foreach ($records as $sortorder => $record) {
            $record->revisionid = $revisionid;
            $record->sortorder = $sortorder;
            $DB->insert_record('theme_brandable_change', $record);
        }
        return $revisionid;
    }

    /**
     * Returns the change records turning the entries of a type into others (copies of new or changed entries,
     * deletions of the entries gone).
     *
     * @param array $base Entries before.
     * @param array $entries Entries after.
     * @param string $type Change type.
     * @param array $sources Change records already read, by revision (filled).
     * @return \stdClass[]
     */
    private static function diff(array $base, array $entries, string $type, array &$sources): array {
        $records = [];
        foreach ($base as $key => $entry) {
            if (!isset($entries[$key])) {
                $records[] = self::deletion($type, $entry);
            }
        }
        foreach ($entries as $key => $entry) {
            if (($base[$key]['contenthash'] ?? null) !== $entry['contenthash']) {
                $records[] = self::copy($type, $entry, $sources);
            }
        }
        return $records;
    }

    /**
     * Returns a copy of the change that built a map entry (all its columns), or one rebuilt from the entry.
     *
     * @param string $type Change type.
     * @param array $entry Map entry.
     * @param array $sources Change records already read, by revision (filled).
     * @return \stdClass Change record, without id, revision and order.
     */
    private static function copy(string $type, array $entry, array &$sources): \stdClass {
        global $DB;

        $revisionid = (int) $entry['revisionid'];
        if ($revisionid > 0 && !isset($sources[$revisionid])) {
            $sources[$revisionid] = $DB->get_records(
                'theme_brandable_change',
                ['revisionid' => $revisionid, 'deleted' => 0],
                'sortorder ASC, id ASC'
            );
        }
        $scope = resolver::entry_scope($entry);
        $source = null;
        foreach ($sources[$revisionid] ?? [] as $record) {
            if (
                $record->type === $type && $record->target === $entry['target']
                    && scope::from_columns($record->scopelevel, $record->scopevalue)->key() === $scope->key()
            ) {
                $source = $record;
            }
        }
        $record = $source ? clone $source : (object) [
            'type' => $type,
            'target' => $entry['target'],
            'content' => $entry['content'],
            'contenthash' => $entry['contenthash'],
            'sourcehash' => $entry['sourcehash'],
            'needsreview' => (int) $entry['needsreview'],
            'scopelevel' => $scope->level,
            'scopevalue' => $scope->value,
            'compiled' => $entry['compiled'] ?? null,
            'report' => $entry['report'] ?? null,
            'signature' => $entry['signature'] ?? null,
            'jsanalyzer' => $entry['jsanalyzer'] ?? null,
        ];
        unset($record->id, $record->revisionid, $record->sortorder);
        $record->deleted = 0;
        return $record;
    }

    /**
     * Returns the change record removing a map entry.
     *
     * @param string $type Change type.
     * @param array $entry Map entry.
     * @return \stdClass
     */
    private static function deletion(string $type, array $entry): \stdClass {
        $scope = resolver::entry_scope($entry);
        return (object) [
            'type' => $type,
            'target' => $entry['target'],
            'content' => null,
            'deleted' => 1,
            'needsreview' => 0,
            'scopelevel' => $scope->level,
            'scopevalue' => $scope->value,
        ];
    }

    /**
     * Starts a new session from a revision: new id, empty thread, draft on the revision.
     *
     * @param int $base Revision the session starts from.
     */
    private static function start_session(int $base): void {
        set_config(self::CONFIG_SESSION, \core\uuid::generate(), 'theme_brandable');
        self::set_pointer(self::CONFIG_SESSIONBASE, $base);
        self::set_pointer(self::CONFIG_DRAFT, $base);
        self::set_pointer(self::CONFIG_REDOHEAD, 0);
    }

    /**
     * Deletes the revisions and generations of the current session.
     */
    private static function delete_session(): void {
        global $DB;

        $sessionid = self::get_session_id();
        self::delete_revisions($DB->get_fieldset_select('theme_brandable_revision', 'id', 'sessionid = ?', [$sessionid]));
        $DB->delete_records('theme_brandable_generation', ['sessionid' => $sessionid]);
    }

    /**
     * Deletes the versions not listed, and every revision that is neither a version nor in the current session.
     *
     * @param int[] $keep Versions kept.
     */
    private static function delete_versions_except(array $keep): void {
        global $DB;

        [$notin, $params] = $DB->get_in_or_equal($keep, SQL_PARAMS_QM, 'param', false);
        $DB->delete_records_select('theme_brandable_version', "id $notin", $params);
        $kept = $DB->get_fieldset_select('theme_brandable_version', 'revisionid', '');
        [$notin, $params] = $DB->get_in_or_equal($kept ?: [0], SQL_PARAMS_QM, 'param', false);
        self::delete_revisions($DB->get_fieldset_select(
            'theme_brandable_revision',
            'id',
            "sessionid IS NULL AND id $notin",
            $params
        ));
    }

    /**
     * Deletes revisions and their changes.
     *
     * @param int[] $ids Revision ids.
     */
    private static function delete_revisions(array $ids): void {
        global $DB;

        foreach (array_chunk($ids, 500) as $chunk) {
            $DB->delete_records_list('theme_brandable_change', 'revisionid', $chunk);
            $DB->delete_records_list('theme_brandable_revision', 'id', $chunk);
        }
    }

    /**
     * Throws while a generation of the session is running (its revision would build on a deleted draft).
     */
    private static function require_no_running_generation(): void {
        global $DB;

        if (
            $DB->record_exists_select(
                'theme_brandable_generation',
                'sessionid = ? AND status = ? AND timemodified > ?',
                [self::get_session_id(), 'running', time() - self::RUNNING_TIMEOUT]
            )
        ) {
            throw new moodle_exception('error:generationrunning', 'theme_brandable');
        }
    }

    /**
     * Sets the published pointer and invalidates what depends on it.
     *
     * Template overrides need no purge: they are read per request from the resolved map of the published
     * revision. Only the compiled CSS of Brandable is rebuilt, and only if the SCSS changed.
     *
     * @param int $revisionid Revision id.
     */
    private static function set_published(int $revisionid): void {
        $previous = self::get_published();
        self::set_pointer(self::CONFIG_PUBLISHED, $revisionid);
        $scss = fn(int $id) => array_map(fn(array $entry) => $entry['contenthash'], resolver::get_map($id)[change::TYPE_SCSS]);
        if ($scss($previous) !== $scss($revisionid)) {
            self::build_published_css($revisionid);
        }
    }

    /**
     * Builds the published CSS again (an asset its SCSS designates was added to the library).
     */
    public static function rebuild_published_css(): void {
        self::build_published_css(self::get_published());
    }

    /**
     * Compiles and stores the published CSS of Brandable right away, then switches to it.
     *
     * Building before switching the theme sub revision avoids core serving the previous stylesheet as a
     * fallback while the new one compiles. The revision is forced: publishing happens from the editor,
     * in a preview session, and the draft must never end up in the public CSS.
     *
     * @param int $revisionid Published revision.
     */
    private static function build_published_css(int $revisionid): void {
        context::with_revision($revisionid, function (): void {
            theme_build_css_for_themes([\theme_config::load('brandable')], ['ltr', 'rtl']);
        });
    }

    /**
     * Returns the revision following $draft on the way to $head, or null.
     *
     * @param int $draft Draft revision id.
     * @param int $head Redo head revision id.
     * @return int|null
     */
    private static function get_redo_next(int $draft, int $head): ?int {
        if ($head <= 0 || $head === $draft) {
            return null;
        }
        $path = resolver::get_ancestry($head);
        $position = $draft === 0 ? count($path) : array_search($draft, $path, true);
        if ($position === false || $position === 0) {
            return null;
        }
        return $path[$position - 1];
    }

    /**
     * Returns the strict ancestors of a revision, root (0) included.
     *
     * @param int $revisionid Revision id.
     * @return int[]
     */
    private static function get_ancestors(int $revisionid): array {
        $ancestry = resolver::get_ancestry($revisionid);
        array_shift($ancestry);
        $ancestry[] = 0;
        return $revisionid > 0 ? $ancestry : [];
    }

    /**
     * Returns the parent of a revision.
     *
     * @param int $revisionid Revision id.
     * @return int
     */
    private static function get_parent(int $revisionid): int {
        global $DB;
        return (int) $DB->get_field('theme_brandable_revision', 'parentrevisionid', ['id' => $revisionid], MUST_EXIST);
    }

    /**
     * Throws if a revision does not exist (0, the empty root, always exists).
     *
     * @param int $revisionid Revision id.
     */
    private static function require_revision(int $revisionid): void {
        global $DB;
        if ($revisionid < 0 || ($revisionid > 0 && !$DB->record_exists('theme_brandable_revision', ['id' => $revisionid]))) {
            throw new moodle_exception('error:unknownrevision', 'theme_brandable', '', $revisionid);
        }
    }

    /**
     * Sets a pointer.
     *
     * @param string $name Config name.
     * @param int $revisionid Revision id.
     */
    private static function set_pointer(string $name, int $revisionid): void {
        set_config($name, $revisionid, 'theme_brandable');
    }

    /**
     * Acquires the lock protecting the revision pointers.
     *
     * @return lock
     */
    private static function lock(): lock {
        $factory = lock_config::get_lock_factory('theme_brandable');
        $lock = $factory->get_lock('revisions', self::LOCK_TIMEOUT);
        if (!$lock) {
            throw new moodle_exception('error:locktimeout', 'theme_brandable');
        }
        return $lock;
    }
}
