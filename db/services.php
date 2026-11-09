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

/**
 * External functions of theme_brandable.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'theme_brandable_get_account' => [
        'classname' => 'theme_brandable\\external\\get_account',
        'description' => 'Returns the Brandable account of the site (plan, credits, usage, offers).',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit',
    ],
    'theme_brandable_billing_checkout' => [
        'classname' => 'theme_brandable\\external\\billing_checkout',
        'description' => 'Starts the purchase of a plan or a credit pack (payment page).',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'moodle/site:config',
    ],
    'theme_brandable_disconnect' => [
        'classname' => 'theme_brandable\\external\\disconnect',
        'description' => 'Disconnects the site from its Brandable account.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'moodle/site:config',
    ],
    'theme_brandable_get_revisions' => [
        'classname' => 'theme_brandable\\external\\get_revisions',
        'description' => 'Returns the editor state: pointers and revision history.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit',
    ],
    'theme_brandable_undo' => [
        'classname' => 'theme_brandable\\external\\undo',
        'description' => 'Undoes the draft.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit',
    ],
    'theme_brandable_redo' => [
        'classname' => 'theme_brandable\\external\\redo',
        'description' => 'Redoes the last undone revision.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit',
    ],
    'theme_brandable_restore' => [
        'classname' => 'theme_brandable\\external\\restore',
        'description' => 'Points the draft to a revision of the history.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit',
    ],
    'theme_brandable_publish' => [
        'classname' => 'theme_brandable\\external\\publish',
        'description' => 'Publishes the draft.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit',
    ],
    'theme_brandable_discard' => [
        'classname' => 'theme_brandable\\external\\discard',
        'description' => 'Discards the draft: the session is deleted, a new one starts from the published version.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit',
    ],
    'theme_brandable_revert_version' => [
        'classname' => 'theme_brandable\\external\\revert_version',
        'description' => 'Publishes the previous version again (the session in progress is deleted).',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit',
    ],
    'theme_brandable_propose_version_note' => [
        'classname' => 'theme_brandable\\external\\propose_version_note',
        'description' => 'Proposes the note of the version about to be published.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit',
    ],
    'theme_brandable_get_assets' => [
        'classname' => 'theme_brandable\\external\\get_assets',
        'description' => 'Lists the library of images and fonts.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit',
    ],
    'theme_brandable_update_asset' => [
        'classname' => 'theme_brandable\\external\\update_asset',
        'description' => 'Updates the description of an asset of the library.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit',
    ],
    'theme_brandable_delete_asset' => [
        'classname' => 'theme_brandable\\external\\delete_asset',
        'description' => 'Deletes an asset of the library that nothing kept uses.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit',
    ],
    'theme_brandable_submit_prompt' => [
        'classname' => 'theme_brandable\\external\\submit_prompt',
        'description' => 'Sends a prompt to the active generator.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit',
    ],
    'theme_brandable_get_generation' => [
        'classname' => 'theme_brandable\\external\\get_generation',
        'description' => 'Relays the status of a generation; creates the revision once its result is valid.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit',
    ],
    'theme_brandable_resume_generation' => [
        'classname' => 'theme_brandable\\external\\resume_generation',
        'description' => 'Tries again a generation whose fonts could not be downloaded (same result).',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit',
    ],
    'theme_brandable_resend_generation' => [
        'classname' => 'theme_brandable\\external\\resend_generation',
        'description' => 'Sends again a request that needed the generated JavaScript, once it is on.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit',
    ],
    'theme_brandable_get_js_consent' => [
        'classname' => 'theme_brandable\\external\\get_js_consent',
        'description' => 'Returns the text of the consent to the generated JavaScript and its state on the site.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'theme/brandable:managejs',
    ],
    'theme_brandable_set_js_consent' => [
        'classname' => 'theme_brandable\\external\\set_js_consent',
        'description' => 'Turns the generated JavaScript on (consent) or off.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'theme/brandable:managejs',
    ],
    'theme_brandable_set_js_suspended' => [
        'classname' => 'theme_brandable\\external\\set_js_suspended',
        'description' => 'Kill switch of the generated JavaScript.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'theme/brandable:managejs',
    ],
    'theme_brandable_get_js_review' => [
        'classname' => 'theme_brandable\\external\\get_js_review',
        'description' => 'Returns the JavaScript modules of the draft to review before publishing.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit',
    ],
    'theme_brandable_mark_js_reviewed' => [
        'classname' => 'theme_brandable\\external\\mark_js_reviewed',
        'description' => 'Records that the user reviewed the source of a JavaScript module of the draft.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit, theme/brandable:managejs',
    ],
    'theme_brandable_ask_js_review' => [
        'classname' => 'theme_brandable\\external\\ask_js_review',
        'description' => 'Asks Brandable for its security review of a JavaScript module of the draft (an aid only).',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit',
    ],
    'theme_brandable_cloud_retry' => [
        'classname' => 'theme_brandable\\external\\cloud_retry',
        'description' => 'Sends again to the cloud the versions stopped, and returns the editor state.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit',
    ],
    'theme_brandable_get_cloud_history' => [
        'classname' => 'theme_brandable\\external\\get_cloud_history',
        'description' => 'Returns a page of the history of the versions of the project in the cloud.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit',
    ],
    'theme_brandable_restore_cloud_version' => [
        'classname' => 'theme_brandable\\external\\restore_cloud_version',
        'description' => 'Restores or pulls a version of the project as the draft of a new session.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit',
    ],
    'theme_brandable_get_cloud_diff' => [
        'classname' => 'theme_brandable\\external\\get_cloud_diff',
        'description' => 'Compares two versions of the project, or a version with the one published here.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit',
    ],
    'theme_brandable_get_cloud_proposal' => [
        'classname' => 'theme_brandable\\external\\get_cloud_proposal',
        'description' => 'Returns the proposal waiting for the environment of this site.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit',
    ],
    'theme_brandable_propose_cloud_version' => [
        'classname' => 'theme_brandable\\external\\propose_cloud_version',
        'description' => 'Proposes a version of the project to another environment.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit',
    ],
    'theme_brandable_dismiss_plugin_update' => [
        'classname' => 'theme_brandable\\external\\dismiss_plugin_update',
        'description' => 'Closes the banner of a new version of the plugin for the current user.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'moodle/site:config',
    ],
    'theme_brandable_dismiss_cloud_proposal' => [
        'classname' => 'theme_brandable\\external\\dismiss_cloud_proposal',
        'description' => 'Dismisses the proposal waiting for the environment of this site.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit',
    ],
    'theme_brandable_apply_changes' => [
        'classname' => 'theme_brandable\\external\\apply_changes',
        'description' => 'Validates changes pushed by a remote backend and stores them as a new draft revision.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit',
    ],
    'theme_brandable_get_template_source' => [
        'classname' => 'theme_brandable\\external\\get_template_source',
        'description' => 'Returns the original source of a template and its draft override.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit',
    ],
    'theme_brandable_get_page_templates' => [
        'classname' => 'theme_brandable\\external\\get_page_templates',
        'description' => 'Returns the templates rendered on a page of the preview.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit',
    ],
    'theme_brandable_change_scope' => [
        'classname' => 'theme_brandable\\external\\change_scope',
        'description' => 'Changes the scope of an override (new draft revision, same content).',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit',
    ],
    'theme_brandable_report_verification' => [
        'classname' => 'theme_brandable\\external\\report_verification',
        'description' => 'Records the verification of a generated revision in the preview; asks for a repair if needed.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit',
    ],
    'theme_brandable_set_template_markers' => [
        'classname' => 'theme_brandable\\external\\set_template_markers',
        'description' => 'Turns the template markers of the preview on or off.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'theme/brandable:edit',
    ],
    'theme_brandable_get_template_overrides' => [
        'classname' => 'theme_brandable\\external\\get_template_overrides',
        'description' => 'Returns the template overrides of a revision (published: public; others: theme/brandable:edit).',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => false,
    ],
];

$services = [
    // For a remote backend pushing changes with a token (disabled by default, authorised users only).
    'Brandable API' => [
        'shortname' => 'theme_brandable_api',
        'functions' => [
            'theme_brandable_apply_changes',
            'theme_brandable_get_revisions',
            'theme_brandable_get_template_source',
        ],
        'restrictedusers' => 1,
        'enabled' => 0,
    ],
];
