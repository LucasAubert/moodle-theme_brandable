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

/**
 * Migration to sessions and versions: the published revision becomes version 1, the draft (when it differs) the
 * session in progress; every other revision, change and generation is deleted.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class migration {
    /**
     * Migrates the revision history to sessions and versions.
     */
    public static function to_sessions(): void {
        global $DB;

        $published = revision_manager::get_published();
        $draft = revision_manager::get_draft();
        $publishedmap = resolver::get_map($published);
        $draftmap = resolver::get_map($draft);
        $author = fn(int $id) => $id > 0 ? (int) $DB->get_field('theme_brandable_revision', 'userid', ['id' => $id]) : 0;
        $sessionid = \core\uuid::generate();

        $transaction = $DB->start_delegated_transaction();
        $versionrevision = 0;
        if ($published > 0) {
            $note = get_string('version:migrated', 'theme_brandable');
            $versionrevision = revision_manager::snapshot($publishedmap, null, '', $note, null, $author($published));
            $DB->insert_record('theme_brandable_version', (object) [
                'revisionid' => $versionrevision,
                'number' => 1,
                'note' => $note,
                'prompts' => 0,
                'userid' => $author($published),
                'timecreated' => time(),
            ]);
        }
        $draftrevision = $versionrevision;
        if (!resolver::same_content($draftmap, $publishedmap)) {
            $draftrevision = revision_manager::snapshot(
                $draftmap,
                $publishedmap,
                '',
                get_string('session:migrateddraft', 'theme_brandable'),
                $sessionid,
                $author($draft),
                $versionrevision
            );
        }
        $kept = array_filter([$versionrevision, $draftrevision]);
        [$notin, $params] = $DB->get_in_or_equal($kept ?: [0], SQL_PARAMS_QM, 'param', false);
        $DB->delete_records_select('theme_brandable_change', "revisionid $notin", $params);
        $DB->delete_records_select('theme_brandable_revision', "id $notin", $params);
        $DB->delete_records('theme_brandable_generation');
        $transaction->allow_commit();

        set_config(revision_manager::CONFIG_SESSION, $sessionid, 'theme_brandable');
        set_config(revision_manager::CONFIG_SESSIONBASE, $versionrevision, 'theme_brandable');
        set_config(revision_manager::CONFIG_PUBLISHED, $versionrevision, 'theme_brandable');
        set_config(revision_manager::CONFIG_DRAFT, $draftrevision, 'theme_brandable');
        set_config(revision_manager::CONFIG_REDOHEAD, 0, 'theme_brandable');
        \cache::make('theme_brandable', 'resolved')->purge();
    }
}
