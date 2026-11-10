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

namespace theme_brandable\external;

use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use theme_brandable\local\generator\generation_manager;
use theme_brandable\local\js\consent;
use theme_brandable\local\js\modules;
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\resolver;
use theme_brandable\local\overrides\revision_manager;
use theme_brandable\local\overrides\scope;
use theme_brandable\local\plugin_updates;

/**
 * Editor state shared by the editor external functions: pointers, revision history and recent generations
 * (their steps are shown under the revisions; failed attempts stay in the thread).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class editor_state {
    /** @var int Generations returned with the state. */
    public const MAX_GENERATIONS = 100;

    /**
     * Builds the current state.
     *
     * @return array
     */
    public static function export(): array {
        global $DB;

        $history = revision_manager::get_history();
        $userids = array_filter(array_unique(array_map(fn($revision) => (int) $revision->userid, $history)));
        $fields = 'id,' . implode(',', \core_user\fields::get_name_fields());
        $users = $userids ? $DB->get_records_list('user', 'id', $userids, '', $fields) : [];

        // Changes of each revision, with their scope; a change can be rescoped while it is the active override.
        $draftmap = resolver::get_map(revision_manager::get_draft());
        $changes = [];
        foreach (
            $DB->get_records(
                'theme_brandable_change',
                null,
                'revisionid ASC, sortorder ASC, id ASC',
                'id, revisionid, type, target, deleted, scopelevel, scopevalue, selectors, report, signature'
            ) as $record
        ) {
            $scope = scope::from_columns($record->scopelevel, $record->scopevalue);
            $active = $draftmap[$record->type][resolver::entry_key($record->target, $scope)] ?? null;
            // Analysis of a module (spec B12): APIs used, findings (not blocking), errors (never run).
            $report = $record->type === change::TYPE_JS ? json_decode((string) $record->report, true) : null;
            $report = is_array($report) ? $report : [];
            $changes[$record->revisionid][] = [
                'id' => (int) $record->id,
                'type' => $record->type,
                'target' => $record->target,
                'deleted' => (bool) $record->deleted,
                'scopelevel' => $scope->level,
                'scopevalue' => (string) $scope->value,
                'scopelabel' => $scope->label(),
                'canrescope' => !$record->deleted && $active !== null && (int) $active['revisionid'] === (int) $record->revisionid,
                'selectors' => json_decode($record->selectors ?? '[]', true) ?: [],
                'apis' => array_values(array_filter(array_map('strval', (array) ($report['apis'] ?? [])))),
                'findings' => array_values(array_map(fn($finding) => [
                    'line' => (int) ($finding['line'] ?? 0),
                    'column' => (int) ($finding['column'] ?? 0),
                    'message' => (string) ($finding['message'] ?? ''),
                ], array_filter((array) ($report['findings'] ?? []), 'is_array'))),
                'jsblocked' => !empty($report['errors']),
                // Restored from the cloud after a change out of Brandable (js_unsigned, contract 13.4).
                'jsunsigned' => $record->type === change::TYPE_JS && !$record->deleted && (string) $record->signature === '',
            ];
        }

        $revisions = [];
        foreach ($history as $revision) {
            $author = $users[$revision->userid] ?? null;
            $revisions[] = [
                'id' => (int) $revision->id,
                'parentid' => (int) $revision->parentrevisionid,
                'prompt' => (string) $revision->prompt,
                'summary' => (string) $revision->summary,
                'author' => $author ? fullname($author) : '',
                'timecreated' => (int) $revision->timecreated,
                'changecount' => $revision->changecount,
                'isdraft' => $revision->isdraft,
                'ispublished' => $revision->ispublished,
                'abandoned' => $revision->abandoned,
                'changes' => $changes[$revision->id] ?? [],
            ];
        }

        // The thread of the session in progress.
        $generations = [];
        $records = $DB->get_records(
            'theme_brandable_generation',
            ['sessionid' => revision_manager::get_session_id()],
            'id DESC',
            '*',
            0,
            self::MAX_GENERATIONS
        );
        foreach ($records as $record) {
            $generation = generation_manager::export($record);
            $generations[] = [
                'id' => $generation['id'],
                'status' => $generation['status'],
                'prompt' => $generation['prompt'],
                // The question asked, or why the request needs the JavaScript.
                'summary' => in_array($generation['status'], [generation_manager::QUESTION, generation_manager::JSACTIVATION,
                    generation_manager::JSRESENT], true) ? $generation['summary'] : '',
                'questionid' => $generation['questionid'],
                'selection' => $generation['selection'],
                'revisionid' => $generation['revisionid'],
                'errormessage' => $generation['errormessage'],
                'retryable' => $generation['retryable'],
                'retryat' => $generation['retryat'],
                'resumable' => $generation['resumable'],
                'action' => $generation['action'],
                'errorurl' => $generation['errorurl'],
                'timecreated' => (int) $record->timecreated,
                'events' => array_map(fn($event) => [
                    'type' => $event['type'],
                    'message' => $event['message'],
                    'run' => $event['run'],
                ], $generation['events']),
            ];
        }

        // The published version, and the previous one (it can be published again).
        $versions = [];
        foreach ([revision_manager::get_current_version(), revision_manager::get_previous_version()] as $i => $version) {
            if ($version) {
                $author = $DB->get_record('user', ['id' => $version->userid], 'id,' . implode(
                    ',',
                    \core_user\fields::get_name_fields()
                ));
                $versions[] = [
                    'number' => (int) $version->number,
                    'note' => (string) $version->note,
                    'author' => $author ? fullname($author) : '',
                    'timecreated' => (int) $version->timecreated,
                    'iscurrent' => $i === 0,
                    // Download of its JavaScript, for a review out of Moodle (spec B14), when it has modules.
                    'jsdownloadurl' => !empty(resolver::get_map((int) $version->revisionid)[change::TYPE_JS])
                        ? (new \moodle_url('/theme/brandable/jsdownload.php', ['version' => (int) $version->number]))->out(false)
                        : '',
                    // Its backup in the cloud of the Pro offer (spec C5).
                    'cloud' => \theme_brandable\local\cloud\status::export($version),
                ];
            }
        }

        $draft = revision_manager::get_draft();
        $published = revision_manager::get_published();
        return [
            'draft' => $draft,
            'published' => $published,
            'sessionbase' => revision_manager::get_session_base(),
            'versions' => $versions,
            'canrevert' => revision_manager::get_previous_version() !== null,
            'canundo' => revision_manager::can_undo(),
            'canredo' => revision_manager::can_redo(),
            'unpublished' => $draft !== $published,
            'revisions' => $revisions,
            'generations' => $generations,
            // Generated JavaScript (spec B1, B2): state of the site, and whether the user manages it.
            'js' => [
                'enabled' => modules::is_enabled(),
                'suspended' => modules::is_suspended(),
                'canmanage' => has_capability(consent::CAPABILITY, \context_system::instance()),
            ],
        ] + (($upgrade = plugin_updates::upgrade_screen()) !== null ? ['pluginupgrade' => $upgrade] : []);
    }

    /**
     * Describes the state.
     *
     * @return external_single_structure
     */
    public static function structure(): external_single_structure {
        return new external_single_structure([
            'draft' => new external_value(PARAM_INT, 'Draft revision id (0: no override)'),
            'published' => new external_value(PARAM_INT, 'Published revision id (0: no override)'),
            'sessionbase' => new external_value(PARAM_INT, 'Revision the session in progress started from'),
            'versions' => new external_multiple_structure(new external_single_structure([
                'number' => new external_value(PARAM_INT, 'Version number'),
                'note' => new external_value(PARAM_RAW, 'Version note'),
                'author' => new external_value(PARAM_TEXT, 'Who published, empty when anonymised'),
                'timecreated' => new external_value(PARAM_INT, 'Publication time'),
                'iscurrent' => new external_value(PARAM_BOOL, 'Whether this is the published version (else the previous one)'),
                'jsdownloadurl' => new external_value(PARAM_RAW, 'Download of its JavaScript, empty without modules'),
                'cloud' => \theme_brandable\local\cloud\status::structure(),
            ]), 'Published version, then the previous one'),
            'canrevert' => new external_value(PARAM_BOOL, 'Whether the previous version can be published again'),
            'canundo' => new external_value(PARAM_BOOL, 'Whether the draft can be undone'),
            'canredo' => new external_value(PARAM_BOOL, 'Whether an undone revision can be redone'),
            'unpublished' => new external_value(PARAM_BOOL, 'Whether the draft differs from the published revision'),
            'revisions' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Revision id'),
                'parentid' => new external_value(PARAM_INT, 'Parent revision id'),
                'prompt' => new external_value(PARAM_RAW, 'Prompt'),
                'summary' => new external_value(PARAM_RAW, 'Summary of the changes'),
                'author' => new external_value(PARAM_TEXT, 'Author full name, empty when anonymised'),
                'timecreated' => new external_value(PARAM_INT, 'Creation time'),
                'changecount' => new external_value(PARAM_INT, 'Number of changes'),
                'isdraft' => new external_value(PARAM_BOOL, 'Whether this is the draft'),
                'ispublished' => new external_value(PARAM_BOOL, 'Whether this is the published revision'),
                'abandoned' => new external_value(PARAM_BOOL, 'Whether the revision failed the verification and was abandoned'),
                'changes' => new external_multiple_structure(new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Change id'),
                    'type' => new external_value(PARAM_ALPHA, 'template, scss or js'),
                    'target' => new external_value(PARAM_RAW, 'Target'),
                    'deleted' => new external_value(PARAM_BOOL, 'Whether the change removes the override'),
                    'scopelevel' => new external_value(PARAM_ALPHA, 'Scope level'),
                    'scopevalue' => new external_value(PARAM_RAW, 'Scope value, empty for the site'),
                    'scopelabel' => new external_value(PARAM_TEXT, 'Scope, human readable'),
                    'canrescope' => new external_value(PARAM_BOOL, 'Whether the scope can be changed (active override)'),
                    'selectors' => new external_multiple_structure(
                        new external_value(PARAM_RAW, 'Selector'),
                        'SCSS: selectors of the compiled chunk, scope wrapper included'
                    ),
                    'apis' => new external_multiple_structure(
                        new external_value(PARAM_RAW, 'API of b'),
                        'JavaScript: APIs of b used by the module'
                    ),
                    'findings' => new external_multiple_structure(new external_single_structure([
                        'line' => new external_value(PARAM_INT, 'Line in the source, 0 if unknown'),
                        'column' => new external_value(PARAM_INT, 'Column, 0 if unknown'),
                        'message' => new external_value(PARAM_RAW, 'Finding (not blocking)'),
                    ]), 'JavaScript: findings of the analysis'),
                    'jsblocked' => new external_value(PARAM_BOOL, 'JavaScript: the analysis has errors, the module never runs'),
                    'jsunsigned' => new external_value(PARAM_BOOL, 'JavaScript: without the signature of Brandable'),
                ]), 'Changes of the revision'),
            ]), 'Revisions of the session in progress, most recent first'),
            'generations' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Generation id'),
                'status' => new external_value(PARAM_ALPHA, 'running, completed, failed, question, jsactivation or jsresent'),
                'prompt' => new external_value(PARAM_RAW, 'Prompt'),
                'summary' => new external_value(PARAM_RAW, 'Question asked, or why the JavaScript is needed; empty otherwise'),
                'questionid' => new external_value(PARAM_INT, 'Generation whose question the prompt answers, 0 if none'),
                'selection' => new external_value(PARAM_RAW, 'Element targeted in the preview (JSON), empty if none'),
                'revisionid' => new external_value(PARAM_INT, 'Created revision, 0 if none'),
                'errormessage' => new external_value(PARAM_RAW, 'Error shown as is when failed'),
                'retryable' => new external_value(PARAM_BOOL, 'Can be sent again (service failure, credits, plan)'),
                'retryat' => new external_value(PARAM_INT, 'Brandable unavailable at the start: time of the new try, 0 if none'),
                'resumable' => new external_value(PARAM_BOOL, 'Fonts not downloaded: tried again with the same result'),
                'action' => new external_value(PARAM_ALPHA, 'Account error: connect, relink, credits, plan, or empty'),
                'errorurl' => new external_value(PARAM_RAW, 'Page where the administrator can act on the error'),
                'timecreated' => new external_value(PARAM_INT, 'Time the prompt was sent'),
                'events' => new external_multiple_structure(new external_single_structure([
                    'type' => new external_value(PARAM_ALPHANUMEXT, 'Event type'),
                    'message' => new external_value(PARAM_RAW, 'Message, shown as is'),
                    'run' => new external_value(PARAM_INT, '0: initial generation, 1..n: repairs'),
                ])),
            ]), 'Recent generations (steps of the revisions, failed attempts, questions), most recent first'),
            'js' => new external_single_structure([
                'enabled' => new external_value(PARAM_BOOL, 'Whether the generated JavaScript is on'),
                'suspended' => new external_value(PARAM_BOOL, 'Whether the kill switch is on'),
                'canmanage' => new external_value(PARAM_BOOL, 'Whether the user turns it on or off (managejs)'),
            ], 'Generated JavaScript'),
            // Brandable requires a newer plugin (426, spec U2): the screen replacing the thread and the composer.
            'pluginupgrade' => new external_single_structure([
                'message' => new external_value(PARAM_RAW, 'Message of Brandable, shown as is'),
                'release' => new external_value(PARAM_RAW, 'Release installed'),
                'latestrelease' => new external_value(PARAM_RAW, 'Latest release, empty if unknown'),
                'haslatest' => new external_value(PARAM_BOOL, 'Whether the latest release is known'),
                'canupdate' => new external_value(PARAM_BOOL, 'Whether the user can update the plugin'),
                'url' => new external_value(PARAM_RAW, 'Page of the installation of Brandable, empty for the others'),
                'hasurl' => new external_value(PARAM_BOOL, 'Whether the page is offered'),
                'installaddonurl' => new external_value(PARAM_RAW, 'Install plugins page of Moodle, empty if not offered'),
            ], 'Update of the plugin required, missing when none', VALUE_OPTIONAL),
        ]);
    }
}
