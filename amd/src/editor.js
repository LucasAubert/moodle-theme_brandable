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
 * Brandable editor: revision history, draft actions and preview iframe controls.
 *
 * @module     theme_brandable/editor
 * @copyright  2026 Lucas Aubert
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import * as Repository from 'theme_brandable/repository';
import Notification from 'core/notification';
import ModalSaveCancel from 'core/modal_save_cancel';
import ModalEvents from 'core/modal_events';
import Templates from 'core/templates';
import {add as addToast} from 'core/toast';
import {getString, getStrings} from 'core/str';
import {Picker, describe} from 'theme_brandable/picker';
import {verify, colourFindings} from 'theme_brandable/verifier';
import * as Assets from 'theme_brandable/assets';
import JsReview from 'theme_brandable/jsreview';
import History from 'theme_brandable/cloud';

/** @type {HTMLElement} Editor root. */
let root;

/** @type {Object|null} Last known editor state. */
let state = null;

/** @type {Boolean} Whether an action is running. */
let busy = false;

/** @type {Number} Delay between two polls of a generation, in milliseconds. */
const POLL_INTERVAL = 1500;
/** @type {Set<Number>} Prompts sent again automatically after an outage of Brandable (once each). */
const autoRetried = new Set();
/** @type {Number|null} Countdown of the automatic new attempt. */
let autoRetryTimer = null;

/** @type {Object|null} Generation being followed. */
let generation = null;

/** @type {Array} Scopes available for the page shown in the preview ({level, value, label}). */
let pageScopes = [];

/** @type {Array} Errors of the last action, shown at the end of the thread ({message, details}). */
let errors = [];

/** @type {Picker|null} Element picker of the preview. */
let picker = null;

/** @type {Object|null} Element targeted in the preview, sent with the next prompt. */
let selection = null;

/** @type {String} Page on which the element was targeted. */
let selectionPage = '';

/** @type {Object|null} Question of the generator answered by the next prompt (open and not set aside). */
let answering = null;

/** @type {Number} Question set aside by the administrator, to send a new request instead. */
let setAside = 0;

/** @type {History|null} History of the versions in the cloud (spec C8). */
let history = null;

/** @type {JsReview|null} Review of the JavaScript before publishing (spec B13). */
let jsReview = null;

/** @type {Number} Refreshes of the state left while a version waits for the cloud (5 s apart: 5 minutes). */
let cloudRefreshes = 0;

/** @type {Number|null} Timer of the next refresh. */
let cloudTimer = null;

/** @type {Map} Errors of the JavaScript modules in the page of the preview, by target (spec B11). */
const jsErrors = new Map();

/** @type {Object|null} Consent panel open ({id, consent, warning}): shown again when the thread is rendered again. */
let jsConsentOpen = null;

/**
 * @type {Object|null} Purchase or change of plan started from the editor, until /account shows it (contract 5):
 * {state: billingstate before, started: time}.
 */
let purchase = null;

/** @type {Number} Reads of /account after the return from a payment, every POLL_ACCOUNT ms for about 30 s. */
const POLL_ACCOUNT = 2500;
const POLL_ACCOUNT_TIMES = 12;

/** @type {Boolean} Whether /account is being read again after a payment. */
let pollingAccount = false;

/** @type {Object|null} Brandable account of the site (get_account result). */
let account = null;

/** @type {String} Why Brandable could not revoke the key of the site (offers to forget it locally). */
let disconnectError = '';

/** @type {Boolean} Whether Brandable asked for the billing country at the last purchase (400 country_required). */
let countryRequired = false;

/** @type {String} Billing country chosen by the administrator (kept when the offers are rendered again). */
let chosenCountry = '';

/** @type {String|null} Colour mode chosen for the preview (light or dark), null: the one the page was rendered in. */
let previewMode = null;

/** @type {Boolean} Whether the yearly prices of the plans are shown (kept when the offers are rendered again). */
let yearly = false;

/** @type {Number} Time given to the page to settle before the verification, in milliseconds. */
const SETTLE = 1500;

/** @type {Object} Strings of the error box, loaded upfront: they must show even when the network is down. */
const errorStrings = {
    title: 'The action failed.',
    network: 'The server could not be reached.',
    timeout: 'The server did not answer in time.',
    dismiss: 'Dismiss',
};

/**
 * Turns whatever an editor call was rejected with into a message and details.
 *
 * @param {*} failure Rejection: {call, error} from the repository, or anything else.
 * @returns {{message: String, details: String}}
 */
export const describeError = (failure) => {
    const call = failure && failure.call ? failure.call : '';
    const {message, code} = describeCause(failure && failure.call ? failure.error : failure);
    return {message: message || errorStrings.network, details: [call, code].filter((part) => part).join(' · ')};
};

/**
 * Turns the error of an editor call into a message (empty when unknown) and a code.
 *
 * @param {*} error Error.
 * @returns {{message: String, code: String}}
 */
const describeCause = (error) => {
    if (error instanceof Error || (typeof DOMException !== 'undefined' && error instanceof DOMException)) {
        // Failure of fetch() (core/ajax from Moodle 5.3): TypeError when the network failed, AbortError on the timeout,
        // Error "HTTP <status>" on an answer that is not one of service.php. Never an errorcode.
        const timedout = error.name === 'AbortError' || error.name === 'TimeoutError';
        return timedout ? {message: errorStrings.timeout, code: 'timeout'} : {message: errorStrings.network, code: 'network'};
    }
    if (error && typeof error === 'object') {
        // Exception of the function ({message, errorcode}) or error of the whole request ({error, errorcode}).
        return {message: error.message || (typeof error.error === 'string' ? error.error : ''), code: error.errorcode || ''};
    }
    if (typeof error !== 'string') {
        return {message: '', code: ''};
    }
    // Error text of jQuery: 'timeout', or '' / 'error' / 'abort' when the network failed.
    if (error === 'timeout') {
        return {message: errorStrings.timeout, code: 'timeout'};
    }
    return {message: ['', 'error', 'abort'].includes(error) ? errorStrings.network : error, code: 'network'};
};

/**
 * Builds the billing country selector shown with a 400 country_required, and the button starting the purchase again.
 *
 * @param {Object} retry Purchase ({kind, item}) and labels ({label, choose, action}).
 * @returns {HTMLElement}
 */
const buildCountryForm = (retry) => {
    const wrapper = document.createElement('div');
    wrapper.className = 'tbe-billingcountry mt-1';
    const label = document.createElement('label');
    label.className = 'tbe-hint d-block mb-0';
    label.textContent = retry.label;
    const select = document.createElement('select');
    select.className = 'custom-select form-select form-select-sm';
    select.dataset.region = 'billingcountry';
    const selected = chosenCountry || root.dataset.defaultcountry;
    [{code: '', name: retry.choose}, ...JSON.parse(root.dataset.countries || '[]')].forEach((country) => {
        const option = new Option(country.name, country.code, false, country.code === selected);
        select.append(option);
    });
    label.append(select);
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-sm tbe-btn-accent mt-1';
    button.dataset.action = 'checkout';
    button.dataset.kind = retry.kind;
    button.dataset.item = retry.item;
    button.textContent = retry.action;
    wrapper.append(label, button);
    return wrapper;
};

/**
 * Shows the errors at the end of the thread (kept across re-renders until the next action).
 */
const showErrors = () => {
    const thread = region('thread');
    thread.querySelectorAll('[data-region="ajaxerror"]').forEach((node) => node.remove());
    errors.forEach((error) => {
        // Plain DOM: rendering a template could fail for the very same reason.
        const entry = document.createElement('div');
        entry.className = 'tbe-entry tbe-ajax-error';
        entry.dataset.region = 'ajaxerror';
        const reply = document.createElement('div');
        reply.className = 'tbe-bubble-reply is-failed';
        const box = document.createElement('div');
        box.className = 'tbe-error';
        box.setAttribute('role', 'alert');
        const title = document.createElement('strong');
        title.textContent = errorStrings.title;
        box.append(title, document.createElement('br'), document.createTextNode(error.message));
        if (error.url) {
            const link = document.createElement('a');
            link.className = 'btn btn-sm btn-outline-primary mt-1';
            link.href = error.url;
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = error.label;
            box.append(document.createElement('br'), link);
        }
        if (error.country) {
            box.append(buildCountryForm(error.country));
        }
        reply.append(box);
        if (error.details) {
            const meta = document.createElement('div');
            meta.className = 'tbe-meta';
            meta.textContent = error.details;
            reply.append(meta);
        }
        const dismiss = document.createElement('button');
        dismiss.type = 'button';
        dismiss.className = 'tbe-link';
        dismiss.dataset.action = 'dismisserrors';
        dismiss.textContent = errorStrings.dismiss;
        reply.append(dismiss);
        entry.append(reply);
        thread.append(entry);
    });
    if (errors.length) {
        thread.scrollTop = thread.scrollHeight;
    }
};

/**
 * Reports a failed call in the thread.
 *
 * @param {*} failure Rejection.
 */
const reportError = (failure) => {
    const error = describeError(failure);
    const last = errors[errors.length - 1];
    if (!last || last.message !== error.message || last.details !== error.details) {
        errors.push(error);
    }
    showErrors();
};

/**
 * Returns an element of the editor.
 *
 * @param {String} name Value of data-region.
 * @returns {HTMLElement}
 */
const region = (name) => root.querySelector(`[data-region="${name}"]`);

/**
 * Ids of the revisions on the way from the draft to the root.
 *
 * @param {Object} current Editor state.
 * @returns {Set<Number>}
 */
const getDraftPath = (current) => {
    const byid = new Map(current.revisions.map((revision) => [revision.id, revision]));
    const path = new Set();
    let id = current.draft;
    while (id > 0 && byid.has(id) && !path.has(id)) {
        path.add(id);
        id = byid.get(id).parentid;
    }
    return path;
};

/**
 * Renders the state: toolbar buttons, status line and thread.
 *
 * @param {Object} current Editor state.
 */
const render = async(current) => {
    state = current;
    const path = getDraftPath(current);
    const byrevision = new Map(current.generations.filter((g) => g.revisionid > 0).map((g) => [g.revisionid, g]));
    const revisions = await Promise.all(current.revisions.map(async(revision) => {
        const runs = byrevision.has(revision.id) ? await buildRuns(byrevision.get(revision.id).events) : [];
        const changes = revision.changes.map((change) => ({
            ...change,
            hasapis: !!(change.apis && change.apis.length),
            hasfindings: !!(change.findings && change.findings.length),
            // Offer the scopes of the previewed page, except the current one.
            scopeoptions: pageScopes.filter((option) => option.level !== change.scopelevel || option.value !== change.scopevalue),
        }));
        return {
            ...revision,
            changes,
            haschanges: changes.length > 0,
            inactive: !path.has(revision.id),
            canrestore: !revision.isdraft,
            isfailed: false,
            isquestion: false,
            isanswer: byrevision.has(revision.id) && byrevision.get(revision.id).questionid > 0,
            retryable: false,
            abandoned: !!revision.abandoned,
            hassteps: runs.length > 0,
            runs,
        };
    }));
    // Failed attempts stay in the thread (the one being followed is shown separately at the end).
    const failures = await Promise.all(current.generations
        .filter((g) => g.status === 'failed' && (!generation || g.id !== generation.id))
        .map(async(g) => {
            const runs = await buildRuns(g.events);
            return {
                isfailed: true,
                isquestion: false,
                isanswer: g.questionid > 0,
                accountaction: g.action ? await buildAccountAction(g) : null,
                abandoned: false,
                retryable: !!g.retryable && !g.resumable,
                resumable: !!g.resumable,
                // Brandable unavailable when it was sent: new attempt at that time (only while it is to come).
                retryat: g.retryat && g.retryat * 1000 > Date.now() && !autoRetried.has(g.id) ? g.retryat : 0,
                generationid: g.id,
                prompt: g.prompt,
                errormessage: g.errormessage,
                timecreated: g.timecreated,
                hassteps: runs.length > 0,
                runs,
            };
        }));
    // Questions of the generator: the last one, when nothing was sent since, waits for the answer. A request needing
    // the JavaScript is shown the same way, with the activation instead of the answer.
    const open = openQuestion(current);
    const answered = new Set(current.generations.map((g) => g.questionid));
    const jsstate = current.js || {enabled: false, suspended: false, canmanage: false};
    const questions = await Promise.all(current.generations
        .filter((g) => ['question', 'jsactivation', 'jsresent'].includes(g.status) && (!generation || g.id !== generation.id))
        .map(async(g) => {
            const runs = await buildRuns(g.events);
            return {
                isquestion: true,
                isjs: g.status !== 'question',
                jspending: g.status === 'jsactivation',
                canmanagejs: jsstate.canmanage,
                jsenabled: jsstate.enabled,
                isfailed: false,
                isanswer: g.questionid > 0,
                abandoned: false,
                generationid: g.id,
                prompt: g.prompt,
                question: g.summary,
                isopen: !!open && open.id === g.id,
                isanswered: answered.has(g.id),
                timecreated: g.timecreated,
                hassteps: runs.length > 0,
                runs,
            };
        }));
    // Oldest first; an attempt without revision sent at the same second as a revision comes after it.
    const late = (entry) => (entry.isfailed || entry.isquestion ? 1 : 0);
    const sorted = revisions.concat(failures, questions)
        .sort((a, b) => a.timecreated - b.timecreated || late(a) - late(b));
    // Consecutive abandoned revisions (failed verification) are folded together.
    const entries = [];
    sorted.forEach((entry) => {
        const last = entries[entries.length - 1];
        if (!entry.abandoned) {
            entries.push({...entry, isgroup: false});
        } else if (last && last.isgroup) {
            last.items.push(entry);
            last.count++;
        } else {
            entries.push({isgroup: true, count: 1, items: [entry]});
        }
    });

    root.querySelector('[data-action="undo"]').disabled = busy || !current.canundo;
    root.querySelector('[data-action="redo"]').disabled = busy || !current.canredo;
    root.querySelector('[data-action="publish"]').disabled = busy || !current.unpublished;
    root.querySelector('[data-action="discard"]').disabled = busy || !current.unpublished;

    // Kill switch of the generated JavaScript, for those managing it, once it is on.
    region('jssuspend').hidden = !(jsstate.canmanage && jsstate.enabled);
    root.querySelector('[data-action="jssuspend"]').checked = !!jsstate.suspended;

    const status = region('status');
    status.textContent = await getString(current.unpublished ? 'status_unpublished' : 'status_published', 'theme_brandable');
    status.title = await getString('status_detail', 'theme_brandable', current);

    const {html, js} = await Templates.renderForPromise('theme_brandable/editor_thread', {
        versions: current.versions,
        canrevert: current.canrevert,
        exporturl: root.dataset.exporturl,
        importurl: root.dataset.importurl,
        entries,
        hasentries: entries.length > 0,
    });
    const thread = region('thread');
    Templates.replaceNodeContents(thread, html, js);
    if (jsConsentOpen) {
        await showJsConsent(jsConsentOpen.id, jsConsentOpen.consent, jsConsentOpen.warning, false);
    }
    await showUpgrade(current.pluginupgrade);
    if (generation) {
        await renderGeneration(generation);
    }
    showErrors();
    await showAnswering();
    thread.scrollTop = thread.scrollHeight;
    followCloud(current);
    await scheduleAutoRetry(current);
};

/**
 * Brandable was unavailable when the last prompt was sent (contract 7): counts down to its Retry-After, then sends the
 * prompt again, once. "Try again" stays available meanwhile.
 *
 * @param {Object} current Editor state.
 */
const scheduleAutoRetry = async(current) => {
    clearInterval(autoRetryTimer);
    autoRetryTimer = null;
    const newest = (current.generations || []).reduce((last, g) => (!last || g.id > last.id ? g : last), null);
    if (!newest || newest.status !== 'failed' || !newest.retryat || autoRetried.has(newest.id)) {
        return;
    }
    const node = region('thread').querySelector(`[data-region="autoretry"][data-generation="${newest.id}"]`);
    if (!node) {
        return;
    }
    const tick = async() => {
        const left = newest.retryat - Math.floor(Date.now() / 1000);
        if (left > 0) {
            node.textContent = await getString('generation:autoretry', 'theme_brandable', left);
            return;
        }
        clearInterval(autoRetryTimer);
        autoRetryTimer = null;
        if (busy || autoRetried.has(newest.id)) {
            return;
        }
        autoRetried.add(newest.id);
        node.hidden = true;
        await retry(newest.id);
    };
    autoRetryTimer = setInterval(tick, 1000);
    await tick();
};

/**
 * Shows or hides the screen of the update required by Brandable (426, spec U2), in place of the thread and the
 * composer.
 *
 * @param {Object|undefined} screen Context of theme_brandable/editor_pluginupgrade, missing when none.
 */
const showUpgrade = async(screen) => {
    const node = region('pluginupgrade');
    if (screen) {
        const {html, js} = await Templates.renderForPromise('theme_brandable/editor_pluginupgrade', screen);
        Templates.replaceNodeContents(node, html, js);
    }
    node.hidden = !screen;
    root.classList.toggle('tbe-upgrading', !!screen);
};

/**
 * While a version waits for its backup in the cloud (sent after the response of the publication, spec C3), reads the
 * state again every 5 seconds, for 5 minutes at most: "Waiting for the cloud backup" becomes "Saved in the cloud".
 *
 * @param {Object} current Editor state.
 */
const followCloud = (current) => {
    const pending = (current.versions || []).some((version) => version.cloud && version.cloud.pending);
    if (!pending) {
        if (cloudRefreshes > 0 && history) {
            // Saved in the cloud: a proposal of this version is accepted now.
            history.refreshProposal();
        }
        cloudRefreshes = 0;
        return;
    }
    if (cloudTimer || cloudRefreshes >= 60) {
        return;
    }
    cloudTimer = setTimeout(async() => {
        cloudTimer = null;
        cloudRefreshes++;
        if (busy || document.visibilityState !== 'visible') {
            followCloud(state);
            return;
        }
        try {
            const next = await Repository.getRevisions();
            if (!busy) {
                await render(next);
            }
        } catch (error) {
            // Read again at the next action.
        }
    }, 5000);
};

/**
 * Builds what is offered under a generation that failed because of the account.
 *
 * @param {Object} failed Generation ({action, errorurl}).
 * @returns {Promise<Object>} Context of theme_brandable/editor_accountaction.
 */
const buildAccountAction = async(failed) => {
    const context = {
        canmanage: root.dataset.canmanage === '1',
        connecturl: root.dataset.connecturl,
        sesskey: M.cfg.sesskey,
        isconnect: failed.action === 'connect',
        isrelink: failed.action === 'relink',
        isoffers: failed.action === 'credits' || failed.action === 'plan',
        // A newer plugin needed (426, or capabilities it lacks): the installation page of Brandable.
        isupgrade: failed.action === 'upgrade',
        upgradeurl: failed.action === 'upgrade' ? failed.errorurl : '',
    };
    if (context.isoffers) {
        const credits = failed.action === 'credits';
        // Direct payment: the packs for credits, the plans otherwise; the Brandable page when the offers cannot be read.
        const current = account && account.available ? account : {};
        addOffers(context, current, !credits, credits);
        context.hasoffers = context.showplans || context.showpacks;
        context.fallbackurl = failed.errorurl;
        context.taxnote = account?.taxnote || '';
        context.countrysupported = account?.countrysupported ?? true;
        context.billingcontacturl = account?.billingcontacturl || '';
        addCountry(context);
        context.label = await getString(credits ? 'action:credits' : 'action:plan', 'theme_brandable');
    }
    return context;
};

/**
 * Adds the offers to show to a context of theme_brandable/editor_offers.
 *
 * @param {Object} context Context.
 * @param {Object} current Account (plans, multisites, creditpacks, hasyearly, pricingurl).
 * @param {Boolean} plans Whether the plans are offered.
 * @param {Boolean} packs Whether the credit packs are offered.
 * @returns {Object} The context.
 */
const addOffers = (context, current, plans, packs) => {
    context.plans = current.plans || [];
    context.multisites = current.multisites || [];
    context.creditpacks = current.creditpacks || [];
    context.hasmultisites = context.multisites.length > 0;
    context.showplans = plans && (context.plans.length > 0 || context.hasmultisites);
    context.showpacks = packs && context.creditpacks.length > 0;
    context.hasyearly = !!current.hasyearly;
    context.yearly = yearly && context.hasyearly;
    context.pricingurl = current.pricingurl || '';
    // Tax mention with any price.
    context.showprices = context.showplans || context.showpacks;
    return context;
};

/**
 * Adds the billing country selector to the context of the offers, when Brandable needs it before a purchase.
 *
 * @param {Object} context Context of theme_brandable/editor_pricing.
 * @returns {Object} The context.
 */
const addCountry = (context) => {
    // Country fixed at the first payment: shown, no longer asked.
    context.countrylocked = !!account?.countrylocked;
    context.billingcountry = account?.billingcountry || '';
    if (context.countrylocked) {
        countryRequired = false;
    }
    context.countryrequired = !context.countrylocked && !!(account?.countryrequired || countryRequired);
    if (context.countryrequired) {
        const selected = chosenCountry || root.dataset.defaultcountry;
        context.countries = JSON.parse(root.dataset.countries || '[]')
            .map((country) => ({...country, selected: country.code === selected}));
    }
    return context;
};

/**
 * Reads the account of the site again, then updates the low credits alert and the account panel.
 *
 * @param {Boolean} refresh Whether Brandable is asked again (not the copy kept for a minute).
 */
const refreshAccount = async(refresh = false) => {
    try {
        account = await Repository.getAccount(refresh);
    } catch (error) {
        account = null;
    }
    const low = !!(account && account.available && account.lowcredits);
    region('lowcredits').hidden = !low;
    region('lowcreditslabel').textContent = low ? await getString('account:lowcredits', 'theme_brandable', account.credits) : '';
    root.querySelector('[data-action="recharge"]').hidden = !(account && account.canmanage && account.creditpacks?.length);
    if (!region('account').hidden) {
        await renderAccount();
    }
};

/**
 * Renders the account panel.
 */
const renderAccount = async() => {
    const current = account || {available: false, canmanage: root.dataset.canmanage === '1', errormessage: ''};
    const context = {...current, disconnecterror: disconnectError, updating: !!(purchase && current.available)};
    if (current.available) {
        const lang = document.documentElement.lang || undefined;
        context.usage = await getString('account:usage', 'theme_brandable',
            {generations: current.generations, credits: current.creditsconsumed});
        context.monthly = await getString('account:monthly', 'theme_brandable',
            {remaining: current.monthlyremaining, allocation: current.monthlyallocation});
        context.purchased = current.purchasedremaining > 0
            ? await getString('account:purchased', 'theme_brandable', current.purchasedremaining) : '';
        context.periodend = current.periodend > 0 ? await getString('account:periodend', 'theme_brandable',
            new Date(current.periodend * 1000).toLocaleDateString(lang)) : '';
        // The plans, the current one marked (a subscribed organisation changes its plan there, contract 6); the
        // credit packs apart.
        addOffers(context, current, true, true);
        context.packstitle = await getString('account:morecredits', 'theme_brandable');
        addCountry(context);
    }
    const {html, js} = await Templates.renderForPromise('theme_brandable/editor_account', context);
    Templates.replaceNodeContents(region('account'), html, js);
};

/**
 * Opens or closes the account panel (read again from Brandable when opened).
 *
 * @param {Boolean} open Whether to open it.
 */
const toggleAccount = async(open) => {
    region('account').hidden = !open;
    root.querySelector('[data-action="account"]').setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) {
        await renderAccount();
        await refreshAccount(true);
    }
};

/**
 * Shows the monthly or yearly prices of the plans, in every list of offers.
 *
 * @param {HTMLElement} button Interval button clicked (data-interval month or year).
 */
const showInterval = (button) => {
    yearly = button.dataset.interval === 'year';
    root.querySelectorAll('[data-region="offers"]').forEach((offers) => {
        offers.dataset.interval = yearly ? 'year' : 'month';
        offers.querySelectorAll('[data-action="interval"]').forEach((b) => {
            const pressed = b.dataset.interval === offers.dataset.interval;
            b.setAttribute('aria-pressed', pressed ? 'true' : 'false');
            b.classList.toggle('btn-primary', pressed);
            b.classList.toggle('btn-outline-primary', !pressed);
        });
    });
};

/**
 * Starts a purchase: the payment page opens in a new tab.
 *
 * @param {String} kind plan or credits.
 * @param {String} item Offer id.
 * @param {HTMLElement} button Button clicked (the billing country is read next to it).
 */
const checkout = async(kind, item, button) => {
    // Billing country asked next to the offer clicked.
    const select = button?.closest('.tbe-accountaction, [data-region="account"], .tbe-error')
        ?.querySelector('[data-region="billingcountry"]');
    const country = select ? select.value : '';
    if (select && !country) {
        select.focus();
        errors.push({message: await getString('account:countryrequired', 'theme_brandable'), details: ''});
        showErrors();
        return;
    }
    // Opened during the click, or the browser would block it once the answer is there.
    const tab = window.open('', '_blank');
    let result = null;
    try {
        result = await Repository.billingCheckout(kind, item, country);
    } catch (error) {
        tab?.close();
        reportError(error);
        return;
    }
    if (!result.url) {
        tab?.close();
        if (result.errorcode === 'country_required') {
            // The organisation has no billing country: asked here, then the same purchase is started again.
            countryRequired = true;
            const [label, choose, action] = await getStrings(['account:billingcountry', 'account:choosecountry',
                'account:continuecheckout'].map((key) => ({key, component: 'theme_brandable'})));
            errors.push({message: result.errormessage, details: '', country: {kind, item, label, choose, action}});
            showErrors();
            if (!region('account').hidden) {
                await renderAccount();
            }
            return;
        }
        // Country not supported (the offers are no longer shown) or fixed at the first payment: the billing contact.
        const contact = ['country_not_supported', 'country_locked'].includes(result.errorcode) && result.errorurl
            ? {url: result.errorurl, label: await getString('account:contactbilling', 'theme_brandable')} : {};
        errors.push({message: result.errormessage, details: '', ...contact});
        showErrors();
        if (contact.url) {
            await refreshAccount(true);
        }
        return;
    }
    if (tab) {
        tab.opener = null;
        tab.location.href = result.url;
    } else {
        window.open(result.url, '_blank', 'noopener');
    }
    // The plan shown stays the one of /account (with "update in progress") until the webhook changes it.
    purchase = {state: account?.billingstate || '', started: Date.now()};
    if (!region('account').hidden) {
        await renderAccount();
    }
    addToast(await getString(result.flow === 'subscription_update' ? 'account:planchangeopened' : 'account:checkoutopened',
        'theme_brandable'));
};

/**
 * Reads /account again after a payment or a change of plan (back from Stripe): at once, then every 2.5 s for about
 * 30 s, until the change shows (webhook of Stripe). The previous plan stays shown meanwhile.
 */
const pollPurchase = async() => {
    if (!purchase || pollingAccount) {
        return;
    }
    pollingAccount = true;
    try {
        for (let i = 0; i < POLL_ACCOUNT_TIMES && purchase; i++) {
            if (i > 0) {
                await new Promise((resolve) => setTimeout(resolve, POLL_ACCOUNT));
            }
            await refreshAccount(true);
            if (purchase && account?.available && account.billingstate !== purchase.state) {
                purchase = null;
                if (!region('account').hidden) {
                    await renderAccount();
                }
                addToast(await getString('account:updated', 'theme_brandable'));
            }
        }
        // Not shown yet: read again at the next return to the editor, for 10 minutes at most.
        if (purchase && Date.now() - purchase.started > 600000) {
            purchase = null;
            await renderAccount();
        }
    } finally {
        pollingAccount = false;
    }
};

/**
 * Disconnects the site, then shows the connection screen.
 *
 * @param {Boolean} force Whether to forget the key even if Brandable cannot revoke it.
 */
const disconnectSite = async(force) => {
    let result = null;
    try {
        result = await Repository.disconnect(force);
    } catch (error) {
        reportError(error);
        return;
    }
    if (result.disconnected) {
        window.location.reload();
        return;
    }
    disconnectError = result.errormessage;
    await toggleAccount(true);
};

/**
 * Replaces the chat with the connection screen (the key was refused).
 */
const showConnectScreen = () => {
    region('connect').hidden = false;
    region('thread').hidden = true;
    region('composer').hidden = true;
};

/**
 * Returns the question waiting for an answer: the last generation, when it asked one.
 *
 * @param {Object} current Editor state.
 * @returns {Object|null} Generation.
 */
const openQuestion = (current) => {
    const last = current.generations[0];
    return last && last.status === 'question' ? last : null;
};

/**
 * Shows above the composer the question the next prompt answers (or hides it).
 */
const showAnswering = async() => {
    const question = state ? openQuestion(state) : null;
    const current = question && question.id !== setAside ? question : null;
    // Without a new element targeted, the answer keeps the one of the question.
    let kept = '';
    if (current && !selection && current.selection) {
        try {
            kept = JSON.parse(current.selection).label || '';
        } catch (e) {
            kept = '';
        }
    }
    const keys = current ? ['question_placeholder', 'question_answer'] : ['promptplaceholder', 'send'];
    const [placeholder, send, keptlabel] = await getStrings(keys.map((key) => ({key, component: 'theme_brandable'}))
        .concat(kept ? [{key: 'question_selection', component: 'theme_brandable', param: kept}] : []));
    // Strings first, then the whole composer at once.
    answering = current;
    region('answering').hidden = !answering;
    region('composer').classList.toggle('is-answering', !!answering);
    region('answeringquestion').textContent = answering ? answering.summary : '';
    region('answeringselection').hidden = !kept;
    region('answeringselection').textContent = kept ? keptlabel : '';
    region('prompt').placeholder = placeholder;
    root.querySelector('[data-action="send"]').textContent = send;
};

/**
 * Groups the events of a generation by attempt (run 0: generation, 1..n: repairs).
 *
 * @param {Array} events Events ({type, message, run}).
 * @returns {Promise<Array>} Runs: [{label, events: [{message, isrejected}]}].
 */
const buildRuns = async(events) => {
    const runs = new Map();
    events.forEach((event) => {
        if (!runs.has(event.run)) {
            runs.set(event.run, []);
        }
        runs.get(event.run).push({
            message: event.message,
            isrejected: ['rejected', 'verification_failed', 'verification_warning', 'js_runtime_error'].includes(event.type),
        });
    });
    const numbers = [...runs.keys()].sort((a, b) => a - b);
    const labels = await Promise.all(numbers.map((run) => run === 0
        ? getString('run_initial', 'theme_brandable')
        : getString('run_repair', 'theme_brandable', run)));
    return numbers.map((run, index) => ({label: labels[index], events: runs.get(run)}));
};

/**
 * Renders the generation being followed at the end of the thread.
 *
 * @param {Object} current Generation (get_generation result).
 */
const renderGeneration = async(current) => {
    // Brandable unavailable while it works (contract 7): the request is kept, its progress read again later.
    const waiting = current.status === 'running' && current.waitinguntil > 0;
    const {html} = await Templates.renderForPromise('theme_brandable/editor_generation', {
        prompt: current.prompt,
        running: current.status === 'running' && !waiting,
        waiting,
        waitingmessage: waiting ? await getString('generation:waiting', 'theme_brandable', current.waitinguntil) : '',
        failed: current.status === 'failed',
        errormessage: current.errormessage,
        runs: await buildRuns(current.events),
    });
    const thread = region('thread');
    const existing = thread.querySelector('[data-region="generation"]');
    if (existing) {
        existing.outerHTML = html;
    } else {
        thread.querySelector('.tbe-empty')?.remove();
        thread.insertAdjacentHTML('beforeend', html);
    }
    thread.scrollTop = thread.scrollHeight;
};

/**
 * Enables or disables the composer.
 *
 * @param {Boolean} enabled Whether prompts can be sent.
 */
const setComposerEnabled = (enabled) => {
    region('prompt').disabled = !enabled;
    root.querySelector('[data-action="send"]').disabled = !enabled;
};

/**
 * Follows a generation until it completes or fails.
 *
 * @param {Object} current Generation returned by submit_prompt.
 */
const follow = async(current) => {
    generation = current;
    await renderGeneration(current);
    while (generation.status === 'running') {
        // Brandable unavailable: not asked again before its Retry-After.
        const delay = generation.waitinguntil > 0 ? generation.waitinguntil * 1000 : POLL_INTERVAL;
        await new Promise((resolve) => setTimeout(resolve, delay));
        generation = await Repository.getGeneration(generation.id);
        await renderGeneration(generation);
    }
    if (generation.status === 'completed') {
        // The revision is now the draft: preview it, then check its selectors on the page.
        const completed = generation;
        generation = null;
        if (completed.state) {
            await render(completed.state);
        }
        const verified = await verifyGeneration(completed);
        if (verified && verified.status === 'running') {
            // Abandoned for a repair: follow it.
            await follow(verified);
            return;
        }
        await render(verified && verified.state ? verified.state : await Repository.getRevisions());
    }
    if (generation && generation.status === 'failed' && generation.action === 'connect') {
        showConnectScreen();
    }
    if (generation && ['failed', 'question', 'jsactivation'].includes(generation.status)) {
        // From now on, the failed attempt or the question is part of the thread (with its steps).
        generation = null;
        await render(await Repository.getRevisions());
    }
};

/**
 * Loads a page in the preview (or reloads it), then waits for it to settle.
 *
 * @param {String} url Page URL, empty for the current one.
 * @returns {Promise<Document|null>} Document of the page, null if it cannot be read.
 */
const loadPreview = (url) => new Promise((resolve) => {
    const frame = region('frame');
    // The errors of the page loaded now only.
    jsErrors.clear();
    const loaded = () => {
        frame.removeEventListener('load', loaded);
        setTimeout(() => {
            try {
                resolve(frame.contentDocument);
            } catch (e) {
                resolve(null);
            }
        }, SETTLE);
    };
    frame.addEventListener('load', loaded);
    let current = '';
    try {
        current = frame.contentWindow.location.href;
    } catch (e) {
        current = '';
    }
    const target = url ? toSiteUrl(url) : null;
    if (target && target.href !== current) {
        frame.src = target.href;
    } else {
        reloadPreview();
    }
});

/**
 * Checks the selectors of a completed generation in the preview, and reports the result.
 *
 * @param {Object} completed Generation (completed).
 * @returns {Promise<Object|null>} Generation after the report (running again when a repair was asked), null if
 *     there was nothing to check.
 */
const verifyGeneration = async(completed) => {
    const doc = await loadPreview(completed.pageurl);
    const revision = (completed.state || state).revisions.find((r) => r.id === completed.revisionid);
    if (!doc || !doc.body || !revision) {
        return null;
    }
    // Not the draft: the preview would not show it (the page fell back to the published theme).
    if (!doc.querySelector('[data-region="theme-brandable-preview-banner"]')) {
        return null;
    }
    let targeted = null;
    try {
        targeted = completed.selection ? JSON.parse(completed.selection) : null;
    } catch (e) {
        targeted = null;
    }
    const base = (completed.state || state).revisions.find((r) => r.id === revision.parentid);
    const result = await verify(doc, revision.changes, targeted, base ? base.changes : []);
    // Modules of the revision that failed in the page (the runtime of the preview told the editor).
    const modules = new Set(revision.changes.filter((change) => change.type === 'js' && !change.deleted)
        .map((change) => change.target));
    const failed = [...jsErrors.values()].filter((error) => modules.has(error.target));
    if (!result.checked && !modules.size) {
        return null;
    }
    await renderGeneration({...completed, status: 'running', events: completed.events.concat([{
        type: 'verifying', message: await getString('verification:running', 'theme_brandable'), run: completed.repairs,
    }])});
    // Colours of the colour modes (Moodle 5.3): warnings only, in both modes when they are on.
    const colours = colourFindings(doc, revision.changes, base ? base.changes : [], colourModes());
    return Repository.reportVerification(completed.id, result.failures, result.warnings, result.checked, failed, colours);
};

/**
 * Shows the targeted element above the composer (or hides the badge).
 */
const showSelection = async() => {
    region('selection').hidden = !selection;
    region('selectionlabel').textContent = selection
        ? await getString('targetedelement', 'theme_brandable', selection.label) : '';
    await showAnswering();
};

/**
 * Targets an element of the preview.
 *
 * @param {Element} element Picked element.
 */
const pick = (element) => {
    const markers = !root.querySelector('[data-action="togglemarkers"]').checked;
    let user = {};
    try {
        user = JSON.parse(root.dataset.user || '{}');
    } catch (e) {
        user = {};
    }
    // Markers disabled: template null and no templates.
    selection = describe(element, user, markers);
    try {
        selectionPage = region('frame').contentWindow.location.href;
    } catch (e) {
        selectionPage = '';
    }
    showSelection();
    region('prompt').focus();
};

/**
 * Sends the prompt of the composer.
 */
const sendPrompt = () => {
    const prompt = region('prompt').value.trim();
    if (prompt) {
        submit(prompt, region('url').value, selection, true, answering ? answering.id : 0,
            Assets.getAttachments().map((asset) => asset.id));
    }
};

/**
 * Sends a failed prompt again, with its targeted element, from the page it was sent on.
 *
 * @param {Number} id Failed generation id.
 */
const retry = async(id) => {
    if (busy) {
        return;
    }
    let failed;
    try {
        failed = await Repository.getGeneration(id);
    } catch (error) {
        reportError(error);
        return;
    }
    let targeted = null;
    try {
        targeted = failed.selection ? JSON.parse(failed.selection) : null;
    } catch (e) {
        targeted = null;
    }
    if (failed.resumable) {
        // Fonts not downloaded: the same result is read again, nothing is generated again.
        await submit(failed.prompt, '', null, false, 0, [], id);
        return;
    }
    await submit(failed.prompt, failed.pageurl || region('url').value, targeted, false, failed.questionid,
        failed.attachments || []);
};

/**
 * Sends a prompt and follows the generation.
 *
 * @param {String} prompt Prompt.
 * @param {String} pageurl Page the prompt is about.
 * @param {Object|null} targeted Targeted element.
 * @param {Boolean} fromComposer Whether the composer (prompt and selection badge) must be cleared once sent.
 * @param {Number} questionid Generation whose question the prompt answers, 0 if none.
 * @param {Number[]} attachments Images of the library attached to the prompt.
 * @param {Number} resumeid Generation resumed (fonts not downloaded) instead of a new prompt, 0 if none.
 */
const submit = (prompt, pageurl, targeted, fromComposer, questionid = 0, attachments = [], resumeid = 0) => start(
    () => (resumeid ? Repository.resumeGeneration(resumeid)
        : Repository.submitPrompt(prompt, pageurl, targeted, questionid, attachments)),
    fromComposer
);

/**
 * Starts a generation and follows it.
 *
 * @param {Function} call Returns a Promise of the generation started, or of null when none was.
 * @param {Boolean} fromComposer Whether the composer (prompt and selection badge) must be cleared once sent.
 */
const start = async(call, fromComposer = false) => {
    if (busy) {
        return;
    }
    busy = true;
    errors = [];
    showErrors();
    setComposerEnabled(false);
    try {
        const current = await call();
        if (!current) {
            return;
        }
        if (fromComposer) {
            region('prompt').value = '';
            selection = null;
            showSelection();
            await Assets.clearAttachments();
        }
        await follow(current);
    } catch (error) {
        reportError(error);
    } finally {
        busy = false;
        setComposerEnabled(true);
        // Credits changed (or the offers are needed under a failure).
        await refreshAccount(true);
        if (state) {
            await render(state);
        }
        region('prompt').focus();
    }
};

/**
 * Reloads the preview iframe.
 */
const reloadPreview = () => {
    const frame = region('frame');
    try {
        frame.contentWindow.location.reload();
    } catch (e) {
        frame.setAttribute('src', frame.getAttribute('src'));
    }
};

/**
 * Runs an editor action, then renders the new state and reloads the preview.
 *
 * @param {Function} action Function returning a promise of the new state.
 * @param {Boolean} reload Whether the preview must be reloaded.
 */
const run = async(action, reload = true) => {
    if (busy) {
        return;
    }
    busy = true;
    errors = [];
    showErrors();
    try {
        const current = await action();
        busy = false;
        await render(current);
        if (reload) {
            reloadPreview();
        }
    } catch (error) {
        busy = false;
        reportError(error);
        if (state) {
            await render(state);
        }
    }
};

/**
 * Publishes the draft with its version note: proposed from the session, edited by the administrator.
 */
const publish = async() => {
    if (busy) {
        return;
    }
    // Every module changed since the published version is reviewed first (refused server side otherwise).
    let review;
    try {
        review = await Repository.getJsReview();
    } catch (error) {
        reportError(error);
        return;
    }
    if (review.pending > 0) {
        await jsReview.open(review);
        return;
    }
    await publishWithNote();
};

/**
 * Publishes the draft once its JavaScript is reviewed: the version note, proposed from the session, edited by the
 * administrator.
 */
const publishWithNote = async() => {
    if (busy) {
        return;
    }
    let proposal = {note: '', maxlength: 1000};
    try {
        proposal = await Repository.proposeVersionNote();
    } catch (error) {
        // The note is written by hand.
        proposal.note = '';
    }
    const [title, intro, label, save] = await getStrings(['publish:title', 'publish:intro', 'publish:note', 'publish']
        .map((key) => ({key, component: 'theme_brandable'})));
    const body = document.createElement('div');
    const text = document.createElement('p');
    text.textContent = intro;
    const field = document.createElement('label');
    field.className = 'd-block';
    field.textContent = label;
    const note = document.createElement('textarea');
    note.className = 'form-control mt-1';
    note.rows = 5;
    note.maxLength = proposal.maxlength;
    note.dataset.region = 'versionnote';
    field.append(note);
    body.append(text, field);
    const modal = await ModalSaveCancel.create({title, body: body.outerHTML, buttons: {save}, removeOnClose: true});
    modal.getRoot().on(ModalEvents.save, () => {
        const value = modal.getRoot()[0].querySelector('[data-region="versionnote"]').value;
        run(async() => {
            const published = await Repository.publish(value);
            // Accepted when this version was the one proposed (contract 13.7): the banner follows.
            history.refreshProposal();
            return published;
        }, false);
    });
    modal.show();
    const shown = modal.getRoot()[0].querySelector('[data-region="versionnote"]');
    shown.value = proposal.note;
    shown.focus();
};

/**
 * Asks for a confirmation.
 *
 * @param {String} key String identifier of the question (theme_brandable).
 * @param {String} buttonkey String identifier of the confirm button (theme_brandable).
 * @param {*} param String parameter.
 * @returns {Promise}
 */
const confirm = async(key, buttonkey, param) => Notification.saveCancelPromise(
    await getString('confirm', 'core'),
    await getString(key, 'theme_brandable', param),
    await getString(buttonkey, 'theme_brandable'),
);

/**
 * Returns the place of the consent panel of a request needing the JavaScript.
 *
 * @param {Number} id Generation.
 * @returns {Element|null}
 */
const jsConsentRegion = (id) => region('thread').querySelector(`[data-generation="${id}"] [data-region="jsconsent"]`);

/**
 * Shows the text of the consent under a request needing the JavaScript (contract 12.7): read again from Brandable.
 *
 * @param {Number} id Generation.
 * @param {Object|null} consent Text and state already read (get_js_consent), null to read them.
 * @param {String} warning Shown above the text (the text changed), empty if none.
 * @param {Boolean} focus Focus its button (not when the thread is only rendered again).
 */
const showJsConsent = async(id, consent = null, warning = '', focus = true) => {
    let current = consent;
    if (!current) {
        try {
            current = await Repository.getJsConsent();
        } catch (error) {
            reportError(error);
            return;
        }
    }
    const place = jsConsentRegion(id);
    if (!place) {
        jsConsentOpen = null;
        return;
    }
    jsConsentOpen = {id, consent: current, warning};
    // Plain text: paragraphs separated by an empty line, escaped by the template.
    const paragraphs = current.text.split(/\r?\n\s*\r?\n/).map((p) => p.trim()).filter((p) => p !== '');
    const {html, js} = await Templates.renderForPromise('theme_brandable/editor_jsconsent', {
        generationid: id,
        version: current.version,
        paragraphs,
        current: current.current,
        warning,
        errormessage: current.errormessage,
    });
    Templates.replaceNodeContents(place, html, js);
    place.hidden = false;
    const activate = place.closest('[data-generation]').querySelector('[data-action="jsactivate"]');
    if (activate) {
        activate.hidden = true;
    }
    if (focus) {
        place.querySelector('[data-action="jsaccept"], [data-action="jsresend"]')?.focus();
    }
};

/**
 * Hides the text of the consent of a request.
 *
 * @param {Number} id Generation.
 */
const hideJsConsent = (id) => {
    jsConsentOpen = null;
    const place = jsConsentRegion(id);
    if (!place) {
        return;
    }
    place.hidden = true;
    place.replaceChildren();
    const activate = place.closest('[data-generation]').querySelector('[data-action="jsactivate"]');
    if (activate) {
        activate.hidden = false;
    }
};

/**
 * Turns the JavaScript on with the text read, then sends the request again (spec B3). When the text changed in the
 * meantime, the current one is shown instead.
 *
 * @param {Number} id Generation needing the JavaScript.
 * @param {String} version Version of the text read.
 */
const acceptJs = async(id, version) => {
    if (busy) {
        return;
    }
    busy = true;
    let result;
    try {
        result = await Repository.setJsConsent(true, version, id);
    } catch (error) {
        reportError(error);
        return;
    } finally {
        busy = false;
    }
    if (result.status === 'conflict') {
        await showJsConsent(id, result.consent, result.warning);
        return;
    }
    jsConsentOpen = null;
    await start(async() => result.generation || Repository.getRevisions().then((current) => {
        render(current);
        return null;
    }));
};

/**
 * Actions of the editor buttons (data-action), by name: called with the button, may throw when a confirmation is
 * cancelled.
 *
 * @type {Object<String, Function>}
 */
const actions = {
    undo: async() => {
        await run(Repository.undo);
    },
    redo: async() => {
        await run(Repository.redo);
    },
    restore: async(button) => {
        await run(() => Repository.restore(Number(button.dataset.revision || 0)));
    },
    publish: async() => {
        await publish();
    },
    discard: async() => {
        await confirm('confirmdiscard', 'discard');
        await run(Repository.discard);
    },
    revert: async() => {
        await confirm(state && state.unpublished ? 'confirmrevertdraft' : 'confirmrevert', 'version:revert');
        await run(Repository.revertVersion);
    },
    reload: () => {
        reloadPreview();
    },
    rescope: async(button) => {
        await run(() => Repository.changeScope(Number(button.dataset.change), button.dataset.level,
            button.dataset.value));
    },
    pick: () => {
        picker.setActive(!picker.active);
    },
    retry: async(button) => {
        await retry(Number(button.dataset.generation));
    },
    account: async() => {
        await toggleAccount(region('account').hidden);
    },
    recharge: async() => {
        await toggleAccount(true);
        region('account').querySelector('[data-region="accountrecharge"]')?.scrollIntoView({block: 'nearest'});
    },
    interval: (button) => {
        showInterval(button);
    },
    checkout: async(button) => {
        await checkout(button.dataset.kind, button.dataset.item, button);
    },
    disconnect: async() => {
        await confirm('account:confirmdisconnect', 'account:disconnect');
        await disconnectSite(false);
    },
    forget: async() => {
        await disconnectSite(true);
    },
    answer: async() => {
        setAside = 0;
        await showAnswering();
        region('prompt').focus();
    },
    newrequest: async() => {
        setAside = answering ? answering.id : 0;
        await showAnswering();
        region('prompt').focus();
    },
    history: async(button) => {
        if (history.isOpen()) {
            history.close();
        } else {
            await history.open();
        }
        button.closest('.tbe').querySelector('[data-action="history"]').setAttribute('aria-expanded',
            history.isOpen() ? 'true' : 'false');
    },
    cloudretry: async() => {
        cloudRefreshes = 0;
        await run(Repository.cloudRetry, false);
    },
    jsactivate: async(button) => {
        await showJsConsent(Number(button.dataset.generation));
    },
    jsaccept: async(button) => {
        await acceptJs(Number(button.dataset.generation), button.dataset.version);
    },
    jscancel: (button) => {
        hideJsConsent(Number(button.dataset.generation));
    },
    jsresend: async(button) => {
        await start(() => Repository.resendGeneration(Number(button.dataset.generation)));
    },
    removeselection: () => {
        selection = null;
        showSelection();
    },
    dismisserrors: () => {
        errors = [];
        showErrors();
    },
    dismissupdate: async(button) => {
        // Banner of a new version of the plugin, closed for this version (spec U3).
        await Repository.dismissPluginUpdate(Number(button.dataset.version));
        region('pluginupdate')?.remove();
    },
    reactivate: async() => {
        // Any editor call turns the preview back on (server side); then the iframe is reloaded.
        await run(Repository.getRevisions);
    },
};

/**
 * Handles clicks on the actions of the editor.
 *
 * @param {MouseEvent} e Click event.
 */
const handleClick = async(e) => {
    const button = e.target.closest('[data-action], [data-device]');
    if (!button || !root.contains(button)) {
        return;
    }
    if (button.dataset.device) {
        setDevice(button.dataset.device);
        return;
    }
    if (button.dataset.action === 'previewmode') {
        togglePreviewMode();
        return;
    }
    if (!Object.prototype.hasOwnProperty.call(actions, button.dataset.action)) {
        return;
    }
    try {
        await actions[button.dataset.action](button);
    } catch (error) {
        // Confirmation cancelled.
        return;
    }
};

/**
 * Switches the width of the preview.
 *
 * @param {String} device desktop, tablet or mobile.
 */
const setDevice = (device) => {
    region('stage').dataset.device = device;
    root.querySelectorAll('[data-device]').forEach((button) => {
        const active = button.dataset.device === device;
        button.classList.toggle('active', active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
};

/**
 * Colour modes of the site (Moodle 5.3): {available, enabled, default}.
 *
 * @returns {Object}
 */
const colourModes = () => {
    try {
        return JSON.parse(root.dataset.colourmodes || '{}');
    } catch (e) {
        return {};
    }
};

/**
 * Shows the preview in the chosen colour mode: attributes of its html tag only, neither the preference of the user
 * nor the cookie of Boost change (spec CM5). Applied again on every page of the preview.
 */
const applyPreviewMode = () => {
    const button = root.querySelector('[data-action="previewmode"]');
    let doc = null;
    try {
        doc = region('frame').contentDocument;
    } catch (e) {
        doc = null;
    }
    if (doc && doc.documentElement && previewMode) {
        doc.documentElement.setAttribute('data-bs-theme', previewMode);
        doc.documentElement.setAttribute('data-colourmode', previewMode);
    }
    if (button) {
        const dark = (previewMode || (doc && doc.documentElement && doc.documentElement.getAttribute('data-bs-theme')))
            === 'dark';
        button.setAttribute('aria-pressed', dark ? 'true' : 'false');
        button.classList.toggle('active', dark);
    }
};

/**
 * Switches the preview between the light and the dark mode.
 */
const togglePreviewMode = () => {
    let shown = 'light';
    try {
        shown = region('frame').contentDocument.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
    } catch (e) {
        shown = previewMode || 'light';
    }
    previewMode = shown === 'dark' ? 'light' : 'dark';
    applyPreviewMode();
};

/**
 * Turns what the user typed in the URL bar into a same-origin URL, or null.
 *
 * @param {String} value Typed value (absolute URL or path).
 * @returns {URL|null}
 */
const toSiteUrl = (value) => {
    const wwwroot = new URL(root.dataset.wwwroot);
    let url;
    try {
        url = new URL(value.trim(), wwwroot.href.replace(/\/?$/, '/'));
    } catch (e) {
        return null;
    }
    return url.origin === wwwroot.origin ? url : null;
};

/**
 * Keeps the URL bar in sync with the iframe, and keeps the editor out of its own preview.
 */
const handleFrameLoad = () => {
    const frame = region('frame');
    let location;
    try {
        location = frame.contentWindow.location;
    } catch (e) {
        return;
    }
    if (location.pathname.endsWith('/theme/brandable/editor.php')) {
        frame.src = root.dataset.wwwroot + '/';
        return;
    }
    region('url').value = location.href;
    applyPreviewMode();
    if (selection && selectionPage && location.href !== selectionPage) {
        // The targeted element belongs to the page left.
        selection = null;
        showSelection();
    }
    // Every page rendered in preview carries the banner: without it, the iframe does not show the draft.
    const banner = frame.contentDocument.querySelector('[data-region="theme-brandable-preview-banner"]');
    region('previewnotice').hidden = !!banner;
    refreshPageScopes(location.href);
};

/**
 * Fetches the scopes available for the page shown in the preview, and re-renders the thread.
 *
 * @param {String} url Page URL.
 */
const refreshPageScopes = async(url) => {
    try {
        const page = await Repository.getPageTemplates(url);
        pageScopes = page.found ? page.scopes : [];
    } catch (error) {
        pageScopes = [];
        reportError(error);
    }
    if (state && !busy) {
        await render(state);
    }
};

/**
 * Initialises the editor.
 *
 * @param {String} selector Selector of the editor root.
 */
export const init = async(selector) => {
    root = document.querySelector(selector);
    if (!root) {
        return;
    }
    if (window.top !== window.self) {
        // The editor never runs inside its own preview.
        window.top.location.href = window.location.href;
        return;
    }

    try {
        const keys = ['error:ajax_title', 'error:ajax_network', 'error:ajax_timeout', 'dismiss'];
        const strings = await getStrings(keys.map((key) => ({key, component: 'theme_brandable'})));
        [errorStrings.title, errorStrings.network, errorStrings.timeout, errorStrings.dismiss] = strings;
    } catch (error) {
        // English fallbacks.
    }
    // The library (panel, attachments) handles its own actions first.
    const assetsClick = Assets.init(root);
    root.addEventListener('click', (e) => {
        if (!assetsClick(e)) {
            handleClick(e);
        }
    });
    Assets.refresh();
    root.addEventListener('change', (e) => {
        if (e.target.matches('[data-region="billingcountry"]')) {
            chosenCountry = e.target.value;
        }
    });
    // Back from Stripe: the return page tells the editor (same origin), or the editor is shown again.
    window.addEventListener('storage', (e) => {
        if (e.key !== 'theme_brandable_checkout' || !purchase) {
            return;
        }
        let back = null;
        try {
            back = JSON.parse(e.newValue || 'null');
        } catch (error) {
            back = null;
        }
        if (back && back.result === 'cancel') {
            purchase = null;
            renderAccount();
        } else {
            pollPurchase();
        }
    });
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            pollPurchase();
        }
    });
    window.addEventListener('pageshow', (e) => {
        if (e.persisted) {
            // Editor restored from the browser cache (back button): the preview may have been left meanwhile.
            run(Repository.getRevisions);
        }
    });
    region('frame').addEventListener('load', handleFrameLoad);
    window.addEventListener('message', (e) => {
        // From the page of the preview only (same origin): {source, type: 'jserror', target, message}.
        let frame = null;
        try {
            frame = region('frame').contentWindow;
        } catch (error) {
            frame = null;
        }
        const data = e.data;
        if (e.source !== frame || e.origin !== window.location.origin || !data || data.source !== 'theme_brandable'
            || data.type !== 'jserror' || typeof data.target !== 'string' || jsErrors.has(data.target)) {
            return;
        }
        jsErrors.set(data.target, {target: data.target, message: String(data.message || '').slice(0, 500)});
    });
    jsReview = new JsReview(region('jsreview'), publishWithNote, reportError);
    history = new History(region('history'), region('cloudproposal'), {
        busy: () => busy,
        error: reportError,
        confirm,
        opened: () => root.querySelector('[data-action="history"]').setAttribute('aria-expanded', 'true'),
        // The draft of a new session: shown, previewed.
        restored: async(result) => {
            await render(result.state);
            reloadPreview();
        },
    });
    // The proposal waiting for this environment, read at the opening (spec C16).
    history.refreshProposal();
    picker = new Picker(region('frame'), pick, (active) => {
        root.querySelector('[data-action="pick"]').setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    root.querySelector('[data-action="jssuspend"]').addEventListener('change', (e) => {
        run(async() => {
            await Repository.setJsSuspended(e.target.checked);
            return Repository.getRevisions();
        });
    });
    root.querySelector('[data-action="togglemarkers"]').addEventListener('change', (e) => {
        selection = null;
        showSelection();
        run(async() => {
            await Repository.setTemplateMarkers(!e.target.checked);
            return Repository.getRevisions();
        });
    });
    region('urlbar').addEventListener('submit', (e) => {
        e.preventDefault();
        const url = toSiteUrl(region('url').value);
        if (url) {
            region('frame').src = url.href;
        } else {
            region('url').value = region('frame').src;
        }
    });
    region('composer').addEventListener('submit', (e) => {
        e.preventDefault();
        sendPrompt();
    });
    region('prompt').addEventListener('keydown', (e) => {
        // Enter sends, Shift+Enter adds a new line.
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
            e.preventDefault();
            sendPrompt();
        }
    });

    try {
        // First: offers shown under the failures, low credits alert.
        await refreshAccount();
        await render(await Repository.getRevisions());
        // Resume a generation still running (editor reloaded meanwhile).
        const running = state.generations.find((g) => g.status === 'running');
        if (running) {
            busy = true;
            setComposerEnabled(false);
            try {
                await follow({...running, summary: '', errorcode: '', repairs: 0});
            } finally {
                busy = false;
                setComposerEnabled(true);
                await render(state);
            }
        }
    } catch (error) {
        reportError(error);
    }
};
