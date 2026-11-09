// This file is part of Moodle - http://moodle.org/
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
 * History of the versions of the project in the cloud of the Pro offer (spec C8, C9, C14, C15): the panel of the editor,
 * the comparison of two versions, the restoration of a version as the draft of a new session and its report, the
 * proposal of a version to another environment, and the banner of the proposal waiting for this one.
 *
 * @module     theme_brandable/cloud
 * @copyright  2026 Lucas Aubert
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import * as Repository from 'theme_brandable/repository';
import Notification from 'core/notification';
import ModalSaveCancel from 'core/modal_save_cancel';
import ModalEvents from 'core/modal_events';
import {add as addToast} from 'core/toast';
import Templates from 'core/templates';
import {getString, getStrings} from 'core/str';
import {renderDiff} from 'theme_brandable/linediff';

/**
 * Returns the context of the history panel.
 *
 * @param {Object} history get_cloud_history result.
 * @returns {Promise<Object>}
 */
const historyContext = async(history) => ({
    ...history,
    full: history.access === 'full',
    read: history.access === 'read',
    none: history.access === 'none',
    readonlyuntil: history.retaineduntil
        ? new Date(history.retaineduntil * 1000).toLocaleDateString(document.documentElement.lang || undefined) : '',
    environments: history.environments.map((environment) => ({...environment, hasnumber: environment.number > 0})),
    hasenvironments: history.environments.length > 0,
    hasversions: history.versions.length > 0,
    // Proposed to another environment: writing, full access only (spec C1).
    proposable: history.access === 'full' && history.environments.length > 0,
});

/**
 * Returns the context of the comparison panel.
 *
 * @param {Object} result get_cloud_diff result.
 * @param {String} versionid Version compared.
 * @param {Boolean} restorable Whether it can be restored here (not the one running here).
 * @returns {Promise<Object>}
 */
const diffContext = async(result, versionid, restorable) => {
    const statuses = ['added', 'modified', 'removed'];
    const labels = await getStrings(statuses.map((status) => ({key: `cloud:diff:status:${status}`, component: 'theme_brandable'})));
    const label = (status) => labels[statuses.indexOf(status)];
    return {
        ...result,
        hasfrom: result.fromnumber > 0,
        versionid,
        restorable: restorable && !result.errormessage,
        changes: result.changes.map((change, index) => ({...change, statuslabel: label(change.status), index})),
        haschanges: result.changes.length > 0,
        assets: result.assets.map((asset, index) => ({...asset, statuslabel: label(asset.status), index,
            licence: asset.kind === 'license'})),
        hasassets: result.assets.length > 0,
        empty: !result.changes.length && !result.assets.length,
        findings: result.findings.map((finding) => ({...finding, blocking: finding.severity === 'blocking'})),
        hasfindings: result.findings.length > 0,
        notindexed: !result.indexed,
        notexact: result.indexed && !result.exact,
    };
};

/**
 * Shows the report of a restoration (plain text through the template).
 *
 * @param {Object} result restore_cloud_version result.
 */
const showReport = async(result) => {
    const {html} = await Templates.renderForPromise('theme_brandable/editor_cloudreport', {
        number: result.number,
        removed: result.removed,
        hasremoved: result.removed.length > 0,
        blocked: result.blocked.join(', '),
        hasblocked: result.blocked.length > 0,
        downloaded: result.downloaded,
        renamed: result.renamed.map((item) => `${item.from} → ${item.to}`).join(', '),
        hasrenamed: result.renamed.length > 0,
        refused: result.refused.map((item) => item.slug).join(', '),
        hasrefused: result.refused.length > 0,
        findings: result.findings.map((finding) => ({...finding, blocking: finding.severity === 'blocking'})),
        hasfindings: result.findings.length > 0,
    });
    await Notification.alert(await getString(result.kind === 'pull' ? 'cloud:report:pulltitle' : 'cloud:report:title',
        'theme_brandable'), html,
        await getString('ok', 'core'));
};

/**
 * The history panel.
 */
export default class History {
    /**
     * Constructor.
     *
     * @param {HTMLElement} panel Region of the panel.
     * @param {HTMLElement} banner Region of the banner of the proposal waiting for this environment.
     * @param {Object} handlers {restored(result), error(error), confirm(key, buttonkey, param): Promise, busy(): Boolean,
     *     opened()}: opened tells the editor the panel was opened from the banner.
     */
    constructor(panel, banner, handlers) {
        this.panel = panel;
        this.banner = banner;
        this.handlers = handlers;
        /** @var {Object[]} Other environments of the project (the targets of a proposal). */
        this.environments = [];
        /** @var {Object|null} Proposal waiting for this environment (get_cloud_proposal), null if none. */
        this.proposal = null;
        /** @var {String} Version running here, among those read. */
        this.current = '';
        /** @var {Object|null} Version chosen to compare with another one: {version, number}. */
        this.picked = null;
        panel.addEventListener('click', (e) => this.handleClick(e));
        banner.addEventListener('click', (e) => this.handleBannerClick(e));
    }

    /**
     * Whether the panel is open.
     *
     * @returns {Boolean}
     */
    isOpen() {
        return !this.panel.hidden;
    }

    /**
     * Opens the panel on the latest versions.
     */
    async open() {
        this.panel.hidden = false;
        try {
            const history = await Repository.getCloudHistory('');
            this.current = (history.versions.find((version) => version.current) || {}).id || '';
            this.environments = history.environments;
            this.picked = null;
            const {html, js} = await Templates.renderForPromise('theme_brandable/editor_history', await historyContext(history));
            Templates.replaceNodeContents(this.panel, html, js);
        } catch (error) {
            this.panel.hidden = true;
            this.handlers.error(error);
        }
    }

    /**
     * Closes the panel.
     */
    close() {
        this.panel.hidden = true;
        this.panel.replaceChildren();
    }

    /**
     * Handles the clicks of the panel (checkout is left to the editor).
     *
     * @param {MouseEvent} e Click.
     */
    async handleClick(e) {
        const button = e.target.closest('[data-action]');
        if (!button) {
            return;
        }
        if (button.dataset.action === 'cloudmore') {
            e.stopPropagation();
            button.disabled = true;
            try {
                const history = await Repository.getCloudHistory(button.dataset.cursor);
                const {html} = await Templates.renderForPromise('theme_brandable/editor_history', await historyContext(history));
                const page = document.createElement('div');
                page.innerHTML = html;
                this.panel.querySelector('[data-region="historylist"]')
                    .append(...page.querySelectorAll('[data-region="historylist"] > li'));
                const next = page.querySelector('[data-action="cloudmore"]');
                if (next) {
                    button.replaceWith(next);
                } else {
                    button.remove();
                }
            } catch (error) {
                button.disabled = false;
                this.handlers.error(error);
            }
        } else if (button.dataset.action === 'cloudcompare') {
            e.stopPropagation();
            await this.compare('published', button.dataset.version);
        } else if (button.dataset.action === 'cloudpick') {
            e.stopPropagation();
            this.pick(button);
        } else if (button.dataset.action === 'cloudback') {
            e.stopPropagation();
            await this.open();
        } else if (button.dataset.action === 'cloudrestore') {
            e.stopPropagation();
            await this.restore(button, button.dataset.version, Number(button.dataset.number), '');
        } else if (button.dataset.action === 'cloudpropose') {
            e.stopPropagation();
            await this.propose(button.dataset.version, Number(button.dataset.number));
        }
    }

    /**
     * Restores a version, or pulls the version of a proposal, after a confirmation; then the report.
     *
     * @param {HTMLElement} button Button, disabled meanwhile.
     * @param {String} versionid Version.
     * @param {Number} number Number of the version.
     * @param {String} proposalid Proposal (a pull), empty if none.
     */
    async restore(button, versionid, number, proposalid) {
        if (this.handlers.busy()) {
            return;
        }
        try {
            await (proposalid ? this.handlers.confirm('cloud:pull:confirm', 'cloud:proposal:pull', number)
                : this.handlers.confirm('cloud:restore:confirm', 'cloud:restore', number));
        } catch (cancelled) {
            return;
        }
        button.disabled = true;
        try {
            const result = await Repository.restoreCloudVersion(versionid, proposalid);
            if (result.errormessage) {
                this.handlers.error({error: {message: result.errormessage}});
                button.disabled = false;
                return;
            }
            this.close();
            await this.handlers.restored(result);
            await showReport(result);
        } catch (error) {
            button.disabled = false;
            this.handlers.error(error);
            // Replaced, accepted or dismissed meanwhile: the banner follows.
            await this.refreshProposal();
        }
    }

    /**
     * Proposes a version to another environment: the environment and a message, in a dialog.
     *
     * @param {String} versionid Version.
     * @param {Number} number Number of the version.
     */
    async propose(versionid, number) {
        const [title, send] = await Promise.all([getString('cloud:propose:title', 'theme_brandable', number),
            getString('cloud:propose:send', 'theme_brandable')]);
        const {html} = await Templates.renderForPromise('theme_brandable/editor_cloudpropose', {
            environments: this.environments,
            maxlength: 500,
        });
        const modal = await ModalSaveCancel.create({title, body: html, buttons: {save: send}, removeOnClose: true});
        modal.getRoot().on(ModalEvents.save, async() => {
            const form = modal.getRoot()[0];
            const environmentid = form.querySelector('[data-region="proposeenvironment"]').value;
            const message = form.querySelector('[data-region="proposemessage"]').value;
            try {
                const result = await Repository.proposeCloudVersion(versionid, environmentid, message);
                if (result.errormessage) {
                    this.handlers.error({error: {message: result.errormessage}});
                    return;
                }
                addToast(await getString('cloud:propose:done', 'theme_brandable', {number, environment: result.environment}));
            } catch (error) {
                this.handlers.error(error);
            }
        });
        modal.show();
    }

    /**
     * Reads the proposal waiting for this environment and shows its banner (at the opening of the editor, after a
     * publication); nothing when the cloud cannot be read.
     */
    async refreshProposal() {
        try {
            const proposal = await Repository.getCloudProposal();
            this.proposal = proposal.hasproposal ? proposal : null;
            if (!this.proposal) {
                this.banner.hidden = true;
                this.banner.replaceChildren();
                return;
            }
            const {html, js} = await Templates.renderForPromise('theme_brandable/editor_cloudproposal', proposal);
            Templates.replaceNodeContents(this.banner, html, js);
            this.banner.hidden = false;
        } catch (error) {
            // Read again at the next opening.
        }
    }

    /**
     * Handles the clicks of the banner: the changes, pull, dismiss.
     *
     * @param {MouseEvent} e Click.
     */
    async handleBannerClick(e) {
        const button = e.target.closest('[data-action]');
        if (!button || !this.proposal) {
            return;
        }
        const proposal = this.proposal;
        if (button.dataset.action === 'cloudproposalchanges') {
            e.stopPropagation();
            this.panel.hidden = false;
            this.handlers.opened();
            await this.compare('published', proposal.versionid);
        } else if (button.dataset.action === 'cloudpull') {
            e.stopPropagation();
            await this.restore(button, proposal.versionid, proposal.number, proposal.id);
        } else if (button.dataset.action === 'clouddismiss') {
            e.stopPropagation();
            try {
                await this.handlers.confirm('cloud:proposal:dismissconfirm', 'cloud:proposal:dismiss', proposal.number);
            } catch (cancelled) {
                return;
            }
            button.disabled = true;
            try {
                const result = await Repository.dismissCloudProposal(proposal.id);
                if (result.errormessage) {
                    this.handlers.error({error: {message: result.errormessage}});
                }
            } catch (error) {
                this.handlers.error(error);
            }
            await this.refreshProposal();
        }
    }

    /**
     * Chooses a version to compare with another one; the second choice compares them (the older one first). Choosing
     * the same version again cancels.
     *
     * @param {HTMLElement} button Button of the version.
     */
    pick(button) {
        const hint = this.panel.querySelector('[data-region="cloudpicked"]');
        const chosen = {version: button.dataset.version, number: Number(button.dataset.number)};
        if (!this.picked || this.picked.version === chosen.version) {
            this.picked = this.picked ? null : chosen;
            this.panel.querySelectorAll('[data-action="cloudpick"]').forEach((other) => {
                other.setAttribute('aria-pressed', String(other === button && this.picked !== null));
            });
            hint.hidden = this.picked === null;
            return;
        }
        const [older, newer] = this.picked.number < chosen.number ? [this.picked, chosen] : [chosen, this.picked];
        this.picked = null;
        this.compare(older.version, newer.version);
    }

    /**
     * Shows the comparison of two versions in the panel (from: a version, or published for the one running here); the
     * line diff of a change is rendered when it is opened.
     *
     * @param {String} from Version, or published.
     * @param {String} to Version.
     */
    async compare(from, to) {
        try {
            const result = await Repository.getCloudDiff(from, to);
            const {html, js} = await Templates.renderForPromise('theme_brandable/editor_clouddiff',
                await diffContext(result, to, to !== this.current));
            Templates.replaceNodeContents(this.panel, html, js);
            this.panel.querySelectorAll('[data-region="clouddiffitem"]').forEach((details) => {
                details.addEventListener('toggle', () => {
                    if (details.open && !details.dataset.rendered) {
                        const item = result[details.dataset.group][Number(details.dataset.index)];
                        details.append(renderDiff(item.before, item.after));
                        details.dataset.rendered = '1';
                    }
                });
            });
            this.panel.querySelector('[data-action="cloudback"]').focus();
        } catch (error) {
            this.handlers.error(error);
        }
    }
}
