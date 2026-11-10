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
 * Review of the generated JavaScript before publishing (spec B13): for each module of the draft changed since the
 * published version, its code with syntax highlighting, the line diff with the published version, the analysis of
 * Brandable, its security review when the offer includes it (an aid, its notice always shown), and "I reviewed this
 * code". Every text of a module is shown with textContent: nothing of it is ever read as HTML.
 *
 * @module     theme_brandable/jsreview
 * @copyright  2026 Lucas Aubert
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import * as Repository from 'theme_brandable/repository';
import Templates from 'core/templates';
import {getString} from 'core/str';
import {renderDiff} from 'theme_brandable/linediff';

/** @type {Set<String>} Keywords of JavaScript highlighted. */
const KEYWORDS = new Set(['async', 'await', 'break', 'case', 'catch', 'class', 'const', 'continue', 'default', 'delete',
    'do', 'else', 'export', 'extends', 'false', 'finally', 'for', 'function', 'if', 'import', 'in', 'instanceof', 'let',
    'new', 'null', 'of', 'return', 'static', 'super', 'switch', 'this', 'throw', 'true', 'try', 'typeof', 'undefined',
    'var', 'void', 'while', 'yield']);

/** @type {RegExp} Tokens: comments, strings, template literals, numbers, words, anything else one character. */
const TOKEN = new RegExp([
    '(\\/\\/[^\\n]*|\\/\\*[\\s\\S]*?(?:\\*\\/|$))',
    '("(?:[^"\\\\\\n]|\\\\.)*"?|\'(?:[^\'\\\\\\n]|\\\\.)*\'?|`(?:[^`\\\\]|\\\\[\\s\\S])*`?)',
    '(\\b\\d[\\d_]*(?:\\.\\d+)?(?:e[+-]?\\d+)?\\b)',
    '([A-Za-z_$][\\w$]*)',
    '(\\s+|[\\s\\S])',
].join('|'), 'g');

/**
 * Splits a source into lines of highlighted tokens.
 *
 * @param {String} code Source.
 * @returns {Array<Array<{text: String, kind: String}>>} Lines.
 */
const tokenize = (code) => {
    const lines = [[]];
    let match;
    TOKEN.lastIndex = 0;
    while ((match = TOKEN.exec(code)) !== null && match[0] !== '') {
        let kind = '';
        if (match[1] !== undefined) {
            kind = 'comment';
        } else if (match[2] !== undefined) {
            kind = 'string';
        } else if (match[3] !== undefined) {
            kind = 'number';
        } else if (match[4] !== undefined) {
            if (KEYWORDS.has(match[4])) {
                kind = 'keyword';
            } else if (match[4] === 'b') {
                kind = 'api';
            }
        }
        // A token over several lines (comment, template literal) is cut at each line.
        match[0].split('\n').forEach((text, index) => {
            if (index > 0) {
                lines.push([]);
            }
            if (text !== '') {
                lines[lines.length - 1].push({text, kind});
            }
        });
    }
    return lines;
};

/**
 * Renders a source with line numbers and highlighting (DOM nodes and textContent only).
 *
 * @param {String} code Source.
 * @returns {HTMLElement}
 */
const renderCode = (code) => {
    const pre = document.createElement('pre');
    pre.className = 'tbe-code';
    tokenize(code).forEach((tokens) => {
        const line = document.createElement('span');
        line.className = 'tbe-code-line';
        tokens.forEach(({text, kind}) => {
            if (!kind) {
                line.append(document.createTextNode(text));
                return;
            }
            const span = document.createElement('span');
            span.className = `tbe-code-${kind}`;
            span.textContent = text;
            line.append(span);
        });
        pre.append(line);
    });
    return pre;
};

/**
 * Renders the security review of Brandable under a module: what it does, the points to check, the current analysis,
 * and always its notice.
 *
 * @param {HTMLElement} place Region of the review.
 * @param {Object} result ask_js_review result.
 */
const renderBrandableReview = async(place, result) => {
    place.replaceChildren();
    place.hidden = false;
    const add = (tag, text, className = '') => {
        const node = document.createElement(tag);
        node.textContent = text;
        if (className) {
            node.className = className;
        }
        place.append(node);
        return node;
    };
    if (result.errormessage) {
        add('div', result.errormessage, 'tbe-error');
        if (result.errorurl && /^https?:\/\//i.test(result.errorurl)) {
            const link = add('a', await getString('jsreview:seeplan', 'theme_brandable'), 'tbe-link');
            link.href = result.errorurl;
            link.target = '_blank';
            link.rel = 'noopener';
        }
        return;
    }
    // The notice first, as it frames the rest: an aid, never a validation.
    add('div', result.notice, 'tbe-jsreview-notice');
    if (result.explanation) {
        add('p', result.explanation);
    }
    if (result.concerns.length) {
        add('div', await getString('jsreview:concerns', 'theme_brandable'), 'tbe-hint');
        const list = add('ul', '');
        result.concerns.forEach((concern) => {
            const item = document.createElement('li');
            item.textContent = concern;
            list.append(item);
        });
    }
    if (result.errors.length) {
        add('div', await getString('jsreview:currenterrors', 'theme_brandable'), 'tbe-error');
        result.errors.forEach((error) => add('div', (error.line ? `${error.line}: ` : '') + error.message, 'tbe-hint'));
    }
};

/**
 * The review panel of the editor.
 */
export default class JsReview {
    /**
     * Constructor.
     *
     * @param {HTMLElement} panel Region of the panel.
     * @param {Function} onReady Called when every module is reviewed and the administrator goes on with publishing.
     * @param {Function} onError Called with an error of a call.
     */
    constructor(panel, onReady, onError) {
        this.panel = panel;
        this.onReady = onReady;
        this.onError = onError;
        this.review = null;
        panel.addEventListener('click', (e) => this.handleClick(e));
        panel.addEventListener('change', (e) => this.handleChange(e));
    }

    /**
     * Opens the panel with the modules to review.
     *
     * @param {Object} review get_js_review result.
     */
    async open(review) {
        await this.render(review);
        this.panel.hidden = false;
        this.panel.querySelector('[data-action="jsreviewclose"]')?.focus();
    }

    /**
     * Closes the panel.
     */
    close() {
        this.panel.hidden = true;
        this.panel.replaceChildren();
        this.review = null;
    }

    /**
     * Renders the panel.
     *
     * @param {Object} review get_js_review result.
     */
    async render(review) {
        this.review = review;
        const modules = review.modules.map((module, index) => ({
            ...module,
            index,
            hasapis: module.apis.length > 0,
            hasfindings: module.findings.length > 0,
            haserrors: module.errors.length > 0,
        }));
        const {html, js} = await Templates.renderForPromise('theme_brandable/editor_jsreview', {
            modules,
            pending: review.pending,
            allreviewed: review.pending === 0,
            canaskbrandable: review.canaskbrandable,
            canreview: review.canreview,
            downloadurl: review.downloadurl,
        });
        Templates.replaceNodeContents(this.panel, html, js);
        modules.forEach((module) => {
            const section = this.panel.querySelector(`[data-index="${module.index}"]`);
            section.querySelector('[data-region="jsreviewcode"]').append(renderCode(module.content));
            section.querySelector('[data-region="jsreviewdiff"]').append(renderDiff(module.published, module.content));
        });
    }

    /**
     * Handles the clicks of the panel.
     *
     * @param {MouseEvent} e Click.
     */
    async handleClick(e) {
        const button = e.target.closest('[data-action]');
        if (!button || !this.review) {
            return;
        }
        const section = button.closest('[data-index]');
        const module = section ? this.review.modules[Number(section.dataset.index)] : null;
        switch (button.dataset.action) {
            case 'jsreviewclose':
                this.close();
                break;
            case 'jsreviewview': {
                const code = button.dataset.view === 'code';
                section.querySelector('[data-region="jsreviewcode"]').hidden = !code;
                section.querySelector('[data-region="jsreviewdiff"]').hidden = code;
                section.querySelectorAll('[data-action="jsreviewview"]').forEach((tab) => {
                    tab.setAttribute('aria-pressed', tab === button ? 'true' : 'false');
                });
                break;
            }
            case 'jsaskreview':
                button.disabled = true;
                try {
                    await renderBrandableReview(section.querySelector('[data-region="jsaireview"]'),
                        await Repository.askJsReview(module.key));
                } catch (error) {
                    this.onError(error);
                } finally {
                    button.disabled = false;
                }
                break;
            case 'jsreviewpublish':
                if (this.review.pending === 0) {
                    this.close();
                    this.onReady();
                }
                break;
        }
    }

    /**
     * Handles "I reviewed this code".
     *
     * @param {Event} e Change.
     */
    async handleChange(e) {
        if (!e.target.matches('[data-action="jsreviewed"]') || !e.target.checked || !this.review) {
            return;
        }
        const module = this.review.modules[Number(e.target.closest('[data-index]').dataset.index)];
        e.target.disabled = true;
        try {
            await this.render(await Repository.markJsReviewed(module.key, module.hash));
        } catch (error) {
            e.target.checked = false;
            e.target.disabled = false;
            this.onError(error);
            // The draft may have changed: read again.
            await this.render(await Repository.getJsReview()).catch(() => null);
        }
    }
}
