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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Calls to the Brandable external functions.
 *
 * Every editor action returns the full editor state (pointers and revision history).
 *
 * @module     theme_brandable/repository
 * @copyright  2026 Lucas Aubert
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

/** @type {Number} Timeout of the calls, in milliseconds (a validation compiling the theme takes a few seconds). */
const TIMEOUT = 120000;

/**
 * Calls an external function of theme_brandable.
 *
 * Failures are rejected with {call, error}: the name of the function and what core/ajax rejected with (an
 * exception {message, errorcode}, a request error {error, errorcode}, or the jQuery error text for network
 * failures and timeouts).
 *
 * @param {String} name Function name without the theme_brandable_ prefix.
 * @param {Object} args Arguments.
 * @returns {Promise<Object>}
 */
const call = (name, args = {}) => {
    const methodname = `theme_brandable_${name}`;
    return Promise.resolve(Ajax.call([{methodname, args}], true, true, false, TIMEOUT)[0])
        .catch((error) => {
            throw {call: methodname, error};
        });
};

export const getRevisions = () => call('get_revisions');
export const undo = () => call('undo');
export const redo = () => call('redo');
export const restore = (revisionid) => call('restore', {revisionid});
export const publish = (note = '') => call('publish', {note});
export const discard = () => call('discard');
export const revertVersion = () => call('revert_version');
export const proposeVersionNote = () => call('propose_version_note');
export const submitPrompt = (prompt, pageurl, selection = null, questionid = 0, attachments = []) => call('submit_prompt',
    {prompt, pageurl, selection: selection ? JSON.stringify(selection) : '', questionid, attachments});
export const resumeGeneration = (id) => call('resume_generation', {id});
export const getGeneration = (id) => call('get_generation', {id});
export const changeScope = (changeid, level, value) => call('change_scope', {changeid, level, value});
export const getPageTemplates = (url) => call('get_page_templates', {url});
export const reportVerification = (id, failures, warnings, checked, jserrors = [], colourwarnings = []) =>
    call('report_verification', {id, failures, warnings, checked, jserrors, colourwarnings});
export const setTemplateMarkers = (enabled) => call('set_template_markers', {enabled});
export const getAccount = (refresh = false) => call('get_account', {refresh});
export const billingCheckout = (kind, item, country = '') => call('billing_checkout', {kind, item, country});
export const disconnect = (force = false) => call('disconnect', {force});
export const getAssets = () => call('get_assets');
export const updateAsset = (asset) => call('update_asset', asset);
export const deleteAsset = (id) => call('delete_asset', {id});
export const resendGeneration = (id) => call('resend_generation', {id});
export const getJsConsent = () => call('get_js_consent');
export const setJsConsent = (enabled, version = '', resendid = 0) => call('set_js_consent', {enabled, version, resendid});
export const setJsSuspended = (suspended) => call('set_js_suspended', {suspended});
export const getJsReview = () => call('get_js_review');
export const markJsReviewed = (key, hash) => call('mark_js_reviewed', {key, hash});
export const askJsReview = (key) => call('ask_js_review', {key});
export const cloudRetry = () => call('cloud_retry');
export const getCloudHistory = (cursor = '') => call('get_cloud_history', {cursor});
export const restoreCloudVersion = (versionid, proposalid = '') => call('restore_cloud_version', {versionid, proposalid});
export const getCloudDiff = (from, to) => call('get_cloud_diff', {from, to});
export const getCloudProposal = () => call('get_cloud_proposal');
export const proposeCloudVersion = (versionid, environmentid, message) =>
    call('propose_cloud_version', {versionid, environmentid, message});
export const dismissCloudProposal = (proposalid) => call('dismiss_cloud_proposal', {proposalid});
export const dismissPluginUpdate = (version) => call('dismiss_plugin_update', {version});
