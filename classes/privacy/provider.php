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

namespace theme_brandable\privacy;

use context;
use context_system;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider of theme_brandable.
 *
 * Revisions store their author and the prompt. Revisions cannot be deleted without breaking the history
 * chain (and possibly the published design), so deletion requests anonymise them: the author is set to 0
 * and the prompt is cleared. Generations (prompt, page URL) are deleted. Prompts and page data are sent to
 * the Brandable API when the remote generator is used.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\user_preference_provider {
    /**
     * Describes the stored personal data.
     *
     * @param collection $collection Collection.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('theme_brandable_revision', [
            'userid' => 'privacy:metadata:revision:userid',
            'prompt' => 'privacy:metadata:revision:prompt',
            'summary' => 'privacy:metadata:revision:summary',
            'timecreated' => 'privacy:metadata:revision:timecreated',
        ], 'privacy:metadata:revision');
        $collection->add_database_table('theme_brandable_version', [
            'userid' => 'privacy:metadata:version:userid',
            'note' => 'privacy:metadata:version:note',
            'timecreated' => 'privacy:metadata:version:timecreated',
        ], 'privacy:metadata:version');
        $collection->add_database_table('theme_brandable_generation', [
            'userid' => 'privacy:metadata:generation:userid',
            'prompt' => 'privacy:metadata:generation:prompt',
            'pageurl' => 'privacy:metadata:generation:pageurl',
            'selection' => 'privacy:metadata:generation:selection',
            'timecreated' => 'privacy:metadata:generation:timecreated',
        ], 'privacy:metadata:generation');
        $collection->add_database_table('theme_brandable_asset', [
            'userid' => 'privacy:metadata:asset:userid',
            'alt' => 'privacy:metadata:asset:alt',
            'timecreated' => 'privacy:metadata:asset:timecreated',
        ], 'privacy:metadata:asset');
        $collection->add_database_table('theme_brandable_jsconsent', [
            'userid' => 'privacy:metadata:jsconsent:userid',
            'version' => 'privacy:metadata:jsconsent:version',
            'enabled' => 'privacy:metadata:jsconsent:enabled',
            'timecreated' => 'privacy:metadata:jsconsent:timecreated',
        ], 'privacy:metadata:jsconsent');
        $collection->add_database_table('theme_brandable_jsreview', [
            'userid' => 'privacy:metadata:jsreview:userid',
            'target' => 'privacy:metadata:jsreview:target',
            'contenthash' => 'privacy:metadata:jsreview:contenthash',
            'timecreated' => 'privacy:metadata:jsreview:timecreated',
        ], 'privacy:metadata:jsreview');
        $collection->add_database_table('theme_brandable_cloudqueue', [
            'userid' => 'privacy:metadata:cloudqueue:userid',
            'payload' => 'privacy:metadata:cloudqueue:payload',
            'timecreated' => 'privacy:metadata:cloudqueue:timecreated',
        ], 'privacy:metadata:cloudqueue');
        // The cloud of the Pro offer (contract 13): the versions published, with the name of their author.
        $collection->add_external_location_link('brandable_cloud', [
            'author' => 'privacy:metadata:cloud:author',
            'content' => 'privacy:metadata:cloud:content',
            'proposal' => 'privacy:metadata:cloud:proposal',
        ], 'privacy:metadata:cloud');
        $collection->add_external_location_link('brandable_api', [
            'prompt' => 'privacy:metadata:api:prompt',
            'page' => 'privacy:metadata:api:page',
            'pagetemplates' => 'privacy:metadata:api:pagetemplates',
            'history' => 'privacy:metadata:api:history',
            'selection' => 'privacy:metadata:api:selection',
            'lang' => 'privacy:metadata:api:lang',
            'country' => 'privacy:metadata:api:country',
            'assets' => 'privacy:metadata:api:assets',
        ], 'privacy:metadata:api');
        // Banner of a new version of the plugin closed (spec U3).
        $collection->add_user_preference(
            \theme_brandable\local\plugin_updates::DISMISSED_PREFERENCE,
            'privacy:metadata:preference:updatedismissed'
        );
        return $collection;
    }

    /**
     * Exports the preferences of a user.
     *
     * @param int $userid User id.
     */
    public static function export_user_preferences(int $userid): void {
        $name = \theme_brandable\local\plugin_updates::DISMISSED_PREFERENCE;
        $value = get_user_preferences($name, null, $userid);
        if ($value !== null) {
            writer::export_user_preference(
                'theme_brandable',
                $name,
                $value,
                get_string('privacy:metadata:preference:updatedismissed', 'theme_brandable')
            );
        }
    }

    /**
     * Returns the contexts holding data of a user (the system context, when they authored revisions).
     *
     * @param int $userid User id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = 'SELECT ctx.id
                  FROM {context} ctx
                 WHERE ctx.contextlevel = :contextlevel
                   AND (EXISTS (SELECT 1 FROM {theme_brandable_revision} r WHERE r.userid = :userid1)
                        OR EXISTS (SELECT 1 FROM {theme_brandable_generation} g WHERE g.userid = :userid2)
                        OR EXISTS (SELECT 1 FROM {theme_brandable_version} v WHERE v.userid = :userid3)
                        OR EXISTS (SELECT 1 FROM {theme_brandable_asset} a WHERE a.userid = :userid4)
                        OR EXISTS (SELECT 1 FROM {theme_brandable_jsconsent} c WHERE c.userid = :userid5)
                        OR EXISTS (SELECT 1 FROM {theme_brandable_jsreview} j WHERE j.userid = :userid6)
                        OR EXISTS (SELECT 1 FROM {theme_brandable_cloudqueue} q WHERE q.userid = :userid7))';
        $contextlist->add_from_sql($sql, ['contextlevel' => CONTEXT_SYSTEM, 'userid1' => $userid, 'userid2' => $userid,
            'userid3' => $userid, 'userid4' => $userid, 'userid5' => $userid, 'userid6' => $userid, 'userid7' => $userid]);
        return $contextlist;
    }

    /**
     * Returns the users who have data in a context.
     *
     * @param userlist $userlist User list.
     */
    public static function get_users_in_context(userlist $userlist): void {
        if (!$userlist->get_context() instanceof context_system) {
            return;
        }
        $userlist->add_from_sql('userid', 'SELECT userid FROM {theme_brandable_revision} WHERE userid > 0', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {theme_brandable_generation} WHERE userid > 0', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {theme_brandable_version} WHERE userid > 0', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {theme_brandable_asset} WHERE userid > 0', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {theme_brandable_jsconsent} WHERE userid > 0', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {theme_brandable_jsreview} WHERE userid > 0', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {theme_brandable_cloudqueue} WHERE userid > 0', []);
    }

    /**
     * Exports the revisions authored by the user.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (!self::has_system_context($contextlist->get_contexts())) {
            return;
        }
        $userid = $contextlist->get_user()->id;
        $writer = writer::with_context(context_system::instance());
        $generations = $DB->get_records('theme_brandable_generation', ['userid' => $userid], 'id ASC');
        if ($generations) {
            $writer->export_data(
                [get_string('pluginname', 'theme_brandable'), get_string('privacy:path:generations', 'theme_brandable')],
                (object) ['generations' => array_map(fn($generation) => (object) [
                    'prompt' => $generation->prompt,
                    'pageurl' => $generation->pageurl,
                    'selection' => $generation->selection !== null ? json_decode($generation->selection) : null,
                    'status' => $generation->status,
                    'timecreated' => transform::datetime($generation->timecreated),
                ], array_values($generations))]
            );
        }
        $versions = $DB->get_records('theme_brandable_version', ['userid' => $userid], 'id ASC');
        if ($versions) {
            $writer->export_data(
                [get_string('pluginname', 'theme_brandable'), get_string('privacy:path:versions', 'theme_brandable')],
                (object) ['versions' => array_map(fn($version) => (object) [
                    'number' => $version->number,
                    'note' => $version->note,
                    'timecreated' => transform::datetime($version->timecreated),
                ], array_values($versions))]
            );
        }
        $assets = $DB->get_records('theme_brandable_asset', ['userid' => $userid], 'id ASC');
        if ($assets) {
            $writer->export_data(
                [get_string('pluginname', 'theme_brandable'), get_string('privacy:path:assets', 'theme_brandable')],
                (object) ['assets' => array_map(fn($asset) => (object) [
                    'slug' => $asset->slug,
                    'kind' => $asset->kind,
                    'alt' => $asset->alt,
                    'timecreated' => transform::datetime($asset->timecreated),
                ], array_values($assets))]
            );
        }
        $consents = $DB->get_records('theme_brandable_jsconsent', ['userid' => $userid], 'id ASC');
        if ($consents) {
            $writer->export_data(
                [get_string('pluginname', 'theme_brandable'), get_string('privacy:path:jsconsents', 'theme_brandable')],
                (object) ['consents' => array_map(fn($consent) => (object) [
                    'version' => $consent->version,
                    'enabled' => transform::yesno($consent->enabled),
                    'timecreated' => transform::datetime($consent->timecreated),
                ], array_values($consents))]
            );
        }
        $reviews = $DB->get_records('theme_brandable_jsreview', ['userid' => $userid], 'id ASC');
        if ($reviews) {
            $writer->export_data(
                [get_string('pluginname', 'theme_brandable'), get_string('privacy:path:jsreviews', 'theme_brandable')],
                (object) ['reviews' => array_map(fn($review) => (object) [
                    'target' => $review->target,
                    'contenthash' => $review->contenthash,
                    'timecreated' => transform::datetime($review->timecreated),
                ], array_values($reviews))]
            );
        }
        $queued = $DB->get_records('theme_brandable_cloudqueue', ['userid' => $userid], 'id ASC');
        if ($queued) {
            $writer->export_data(
                [get_string('pluginname', 'theme_brandable'), get_string('privacy:path:cloudqueue', 'theme_brandable')],
                (object) ['versions' => array_map(function ($row) {
                    $payload = json_decode($row->payload, true) ?: [];
                    return (object) [
                        'note' => (string) ($payload['note'] ?? ''),
                        'author' => (string) ($payload['author']['name'] ?? ''),
                        'timecreated' => transform::datetime($row->timecreated),
                    ];
                }, array_values($queued))]
            );
        }
        $revisions = $DB->get_records('theme_brandable_revision', ['userid' => $userid], 'id ASC');
        if (!$revisions) {
            return;
        }
        $data = array_map(fn($revision) => (object) [
            'id' => $revision->id,
            'prompt' => $revision->prompt,
            'summary' => $revision->summary,
            'timecreated' => transform::datetime($revision->timecreated),
        ], array_values($revisions));
        $writer->export_data(
            [get_string('pluginname', 'theme_brandable'), get_string('privacy:path:revisions', 'theme_brandable')],
            (object) ['revisions' => $data]
        );
    }

    /**
     * Anonymises all revisions of a context.
     *
     * @param context $context Context.
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        if ($context instanceof context_system) {
            self::anonymise('userid > 0', []);
        }
    }

    /**
     * Anonymises the revisions of a user.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        if (self::has_system_context($contextlist->get_contexts())) {
            self::anonymise('userid = ?', [$contextlist->get_user()->id]);
        }
    }

    /**
     * Anonymises the revisions of several users.
     *
     * @param approved_userlist $userlist Approved users.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        if (!$userlist->get_context() instanceof context_system || !$userlist->get_userids()) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userlist->get_userids());
        self::anonymise("userid $insql", $params);
    }

    /**
     * Sets the author to 0 and clears the prompt of the matching revisions.
     *
     * @param string $select SQL condition on theme_brandable_revision.
     * @param array $params Parameters.
     */
    private static function anonymise(string $select, array $params): void {
        global $DB;
        // Generations are transient: deleted. Revisions make the design history: anonymised.
        $DB->delete_records_select('theme_brandable_generation', $select, $params);
        $DB->set_field_select('theme_brandable_revision', 'prompt', '', $select, $params);
        $DB->set_field_select('theme_brandable_revision', 'userid', 0, $select, $params);
        // Versions keep their note (it describes the design, not the user).
        $DB->set_field_select('theme_brandable_version', 'userid', 0, $select, $params);
        // Images and fonts belong to the site design: kept, without their author.
        $DB->set_field_select('theme_brandable_asset', 'userid', 0, $select, $params);
        // Consents to the JavaScript and reviews of modules are the history of the site (D12): without their author.
        $DB->set_field_select('theme_brandable_jsconsent', 'userid', 0, $select, $params);
        $DB->set_field_select('theme_brandable_jsreview', 'userid', 0, $select, $params);
        // Versions waiting for the cloud: sent without their author.
        foreach ($DB->get_records_select('theme_brandable_cloudqueue', $select, $params) as $row) {
            $payload = json_decode($row->payload, true) ?: [];
            $payload['author'] = ['hash' => \theme_brandable\local\cloud\cloud::author_hash(0)];
            $DB->update_record('theme_brandable_cloudqueue', (object) ['id' => $row->id, 'userid' => 0,
                'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
        }
    }

    /**
     * Whether a list of contexts contains the system context.
     *
     * @param context[] $contexts Contexts.
     * @return bool
     */
    private static function has_system_context(array $contexts): bool {
        foreach ($contexts as $context) {
            if ($context instanceof context_system) {
                return true;
            }
        }
        return false;
    }
}
