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
 * Runtime of the generated JavaScript modules: the library b (contract 12.4, spec B9 and B10).
 *
 * This file is a security boundary. A module receives its own b, frozen, and nothing else:
 * - markup only enters the page through b.render (Mustache, escaped) then b.insert, which refuses any other object;
 * - b.modal takes text (set with textContent) or a result of b.render, never a string of HTML;
 * - b.storage and b.once are confined to the module and to the user (opaque prefix set by the server);
 * - a module that throws is disabled for the page (its listeners removed), and reported in the preview.
 * The server only sends the modules of the page when the JavaScript is active (setting, kill switch, nojs, admin
 * pages, preview): this file runs what it is given.
 *
 * @module     theme_brandable/runtime
 * @copyright  2026 Lucas Aubert
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Modal from 'core/modal';
import Templates from 'core/templates';
import {getString} from 'core/str';

/** @type {Number} Largest value stored by b.storage.set, in characters of JSON. */
export const MAX_STORED = 4096;

/** @type {RegExp} Key of b.storage and b.once. */
const KEY = /^[A-Za-z0-9_.-]{1,64}$/;

/** @type {RegExp} Template name given to b.render. */
const TEMPLATE = /^[a-z][a-z0-9_]*\/[A-Za-z0-9_-]+(\/[A-Za-z0-9_-]+)*$/;

/** @type {String[]} Positions of b.insert. */
const POSITIONS = ['append', 'prepend', 'before', 'after', 'replace'];

/** @type {Object} Events of Moodle a module can listen to (contract 12.4), by name: name of the DOM event. */
const MOODLE_EVENTS = {
    'theme_boost/drawers:shown': 'theme_boost/drawers:shown',
    'theme_boost/drawers:hidden': 'theme_boost/drawers:hidden',
    'core_filters/contentUpdated': 'core_filters/contentUpdated',
};

/** @type {WeakMap} Results of b.render (opaque objects) and their HTML and JS. */
const rendered = new WeakMap();

/**
 * Escapes text for a place where core expects HTML (title of a modal).
 *
 * @param {String} text Text.
 * @returns {String}
 */
const escape = (text) => String(text).replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;',
    "'": '&#39;'})[c]);

/**
 * Builds the node of a text (paragraphs separated by an empty line) or of a result of b.render.
 *
 * @param {*} content Text, or result of b.render.
 * @param {String} what Name of the argument (errors).
 * @returns {HTMLElement}
 */
const contentNode = (content, what) => {
    const node = document.createElement('div');
    if (rendered.has(content)) {
        const {html, js} = rendered.get(content);
        Templates.replaceNodeContents(node, html, js);
        return node;
    }
    if (typeof content !== 'string') {
        throw new TypeError(`b.modal: ${what} must be text or a result of b.render`);
    }
    content.split(/\n\s*\n/).forEach((paragraph) => {
        const p = document.createElement('p');
        p.textContent = paragraph.trim();
        node.append(p);
    });
    return node;
};

/**
 * Returns the elements an event listener targets.
 *
 * @param {*} target Element, selector, 'window' or 'document'.
 * @returns {EventTarget[]}
 */
const listenerTargets = (target) => {
    if (target === 'window') {
        return [window];
    }
    if (target === 'document') {
        return [document];
    }
    if (typeof target === 'string') {
        return Array.from(document.querySelectorAll(target));
    }
    if (target instanceof Element) {
        return [target];
    }
    throw new TypeError('b.on: the target is an element, a selector, "window" or "document"');
};

/**
 * A module of the page: its b, its listeners, its state.
 */
class Module {
    /**
     * Constructor.
     *
     * @param {String} target Target (js/<slug>).
     * @param {Object} page Page (b.page).
     * @param {String} user Opaque identifier of the user.
     * @param {Function} onError Called with (target, error) when the module fails.
     */
    constructor(target, page, user, onError) {
        this.target = target;
        this.slug = target.replace(/^js\//, '');
        this.prefix = `brandable:${this.slug}:${user}:`;
        this.listeners = [];
        this.disabled = false;
        this.onError = onError;
        this.b = this.library(Object.freeze({...page}));
    }

    /**
     * Disables the module after an error: listeners removed, the error reported (once).
     *
     * @param {*} error Error.
     */
    fail(error) {
        if (this.disabled) {
            return;
        }
        this.disabled = true;
        this.listeners.forEach(([element, type, listener, options]) => element.removeEventListener(type, listener, options));
        this.listeners = [];
        this.onError(this.target, error);
    }

    /**
     * Wraps a function of the module: not run once disabled, its exceptions disable the module.
     *
     * @param {Function} fn Function of the module.
     * @returns {Function}
     */
    guard(fn) {
        if (typeof fn !== 'function') {
            throw new TypeError('a function is expected');
        }
        return (...args) => {
            if (this.disabled) {
                return undefined;
            }
            try {
                const result = fn(...args);
                if (result && typeof result.then === 'function') {
                    Promise.resolve(result).catch((error) => this.fail(error));
                }
                return result;
            } catch (error) {
                this.fail(error);
                return undefined;
            }
        };
    }

    /**
     * Key of the local storage of the module and the user.
     *
     * @param {String} key Key given by the module.
     * @returns {String}
     */
    storageKey(key) {
        if (typeof key !== 'string' || !KEY.test(key)) {
            throw new TypeError('b.storage: the key is 1 to 64 letters, digits, "_", "." or "-"');
        }
        return this.prefix + key;
    }

    /**
     * Builds the library b of the module (frozen).
     *
     * @param {Object} page Page.
     * @returns {Object}
     */
    library(page) {
        const storage = Object.freeze({
            get: (key) => {
                try {
                    const raw = window.localStorage.getItem(this.storageKey(key));
                    return raw === null ? null : JSON.parse(raw);
                } catch (error) {
                    if (error instanceof TypeError) {
                        throw error;
                    }
                    return null;
                }
            },
            set: (key, value) => {
                const raw = JSON.stringify(value);
                if (raw === undefined || raw.length > MAX_STORED) {
                    throw new RangeError(`b.storage: a value is JSON of ${MAX_STORED} characters at most`);
                }
                try {
                    window.localStorage.setItem(this.storageKey(key), raw);
                } catch (error) {
                    if (error instanceof TypeError) {
                        throw error;
                    }
                    // Storage full or blocked: nothing kept.
                }
            },
            remove: (key) => {
                try {
                    window.localStorage.removeItem(this.storageKey(key));
                } catch (error) {
                    if (error instanceof TypeError) {
                        throw error;
                    }
                }
            },
        });
        return Object.freeze({
            root: document.body,
            page,
            storage,
            find: (selector) => document.querySelector(String(selector)),
            findAll: (selector) => Array.from(document.querySelectorAll(String(selector))),
            on: (target, type, handler, options = {}) => {
                const listener = this.guard(handler);
                const settings = {passive: !(options && options.passive === false),
                    capture: !!(options && options.capture), once: !!(options && options.once)};
                listenerTargets(target).forEach((element) => {
                    element.addEventListener(String(type), listener, settings);
                    this.listeners.push([element, String(type), listener, settings]);
                });
            },
            onMoodle: (name, handler) => {
                if (!Object.prototype.hasOwnProperty.call(MOODLE_EVENTS, name)) {
                    throw new TypeError(`b.onMoodle: unknown event ${name}`);
                }
                const listener = this.guard(() => handler());
                document.addEventListener(MOODLE_EVENTS[name], listener);
                this.listeners.push([document, MOODLE_EVENTS[name], listener, undefined]);
            },
            render: (template, context = {}) => {
                if (typeof template !== 'string' || !TEMPLATE.test(template)) {
                    return Promise.reject(new TypeError('b.render: a template name (component/name) is expected'));
                }
                // A copy of plain data only (no getter, no function of the page).
                const data = JSON.parse(JSON.stringify(context || {}));
                return Templates.renderForPromise(template, data).then(({html, js}) => {
                    const result = Object.freeze(Object.create(null));
                    rendered.set(result, {html, js});
                    return result;
                });
            },
            insert: (element, result, position = 'append') => {
                if (!(element instanceof Element)) {
                    throw new TypeError('b.insert: an element is expected');
                }
                if (!rendered.has(result)) {
                    throw new TypeError('b.insert only inserts a result of b.render');
                }
                if (!POSITIONS.includes(position)) {
                    throw new TypeError(`b.insert: the position is one of ${POSITIONS.join(', ')}`);
                }
                const {html, js} = rendered.get(result);
                if (position === 'append') {
                    Templates.appendNodeContents(element, html, js);
                } else if (position === 'prepend') {
                    Templates.prependNodeContents(element, html, js);
                } else if (position === 'replace') {
                    Templates.replaceNode(element, html, js);
                } else {
                    const placeholder = document.createElement('span');
                    element[position](placeholder);
                    Templates.replaceNode(placeholder, html, js);
                }
            },
            modal: ({title = '', body = '', footer = null, large = false} = {}) => {
                const nodes = {body: contentNode(body, 'body'), footer: footer === null ? null : contentNode(footer, 'footer')};
                return Modal.create({
                    title: escape(title),
                    body: '',
                    footer: '',
                    large: !!large,
                    show: false,
                }).then((modal) => {
                    modal.getBody()[0].replaceChildren(nodes.body);
                    if (nodes.footer) {
                        modal.getFooter()[0].replaceChildren(nodes.footer);
                    }
                    return Object.freeze({
                        show: () => modal.show(),
                        hide: () => modal.hide(),
                        onHidden: (fn) => {
                            const listener = this.guard(() => fn());
                            modal.getRoot().on('modal:hidden', listener);
                        },
                    });
                });
            },
            str: (identifier, component = 'core') => getString(String(identifier), String(component)),
            once: (key) => {
                if (storage.get(`once.${key}`) !== null) {
                    return false;
                }
                storage.set(`once.${key}`, 1);
                return true;
            },
            viewport: () => ({scrollX: window.scrollX, scrollY: window.scrollY, width: window.innerWidth,
                height: window.innerHeight}),
        });
    }

    /**
     * Runs the module.
     *
     * @param {Function} fn Module (compiled function expression).
     */
    run(fn) {
        if (typeof fn !== 'function') {
            this.fail(new TypeError(`${this.target}: not a function`));
            return;
        }
        this.guard(fn)(this.b);
    }
}

/** @type {Module[]} Modules of the page. */
const modules = [];

/**
 * Runs the modules of the page (theme_brandable_page_init, when the JavaScript is active).
 *
 * @param {Object} config {url: file of the modules of the revision, modules: [{key, target}] to run in this order (key:
 *     variant of the target for this page, in the file), page, user}.
 * @param {Function|null} report Called with (target, message) on an error (preview), null otherwise.
 * @returns {Promise<Module[]>}
 */
export const run = (config, report = null) => new Promise((resolve) => {
    const start = () => window.require([config.url], (compiled) => {
        (config.modules || []).forEach(({key, target}) => {
            const module = new Module(target, config.page || {}, String(config.user || ''), (failed, error) => {
                const message = String(error && error.message ? error.message : error).slice(0, 500);
                window.console.error(`theme_brandable ${failed}: ${message}`);
                if (report) {
                    report(failed, message);
                }
            });
            modules.push(module);
            module.run(compiled && Object.prototype.hasOwnProperty.call(compiled, key) ? compiled[key] : null);
        });
        resolve(modules);
    }, () => resolve([]));
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, {once: true});
    } else {
        start();
    }
});

/**
 * Tells the editor (the page holding the preview, same origin only) that a module failed (spec B11): the target and
 * the message of the error as thrown, 500 characters at most. Nothing else of the page is sent.
 *
 * @param {String} target Target of the module.
 * @param {String} message Message of the error.
 */
const reportToEditor = (target, message) => {
    if (window.parent === window) {
        return;
    }
    window.parent.postMessage({source: 'theme_brandable', type: 'jserror', target, message: String(message).slice(0, 500)},
        window.location.origin);
};

/**
 * Entry point (js_call_amd): runs the modules described by the page data.
 *
 * @param {Object} config See run().
 */
export const init = (config) => {
    run(config, config.preview ? reportToEditor : null);
};
