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
 * Element picker of the editor: highlights the element under the mouse in the preview iframe, captures it.
 *
 * The picking mode is turned on by the editor button or Alt+Shift+C (so that a menu can be opened first), and an
 * Alt+click captures an element directly. The capture describes the element for the generator: pruned HTML
 * (≤ 4 KB), ancestors, templates (from the template markers of the preview), label and unstable ids, without
 * personal data.
 *
 * @module     theme_brandable/picker
 * @copyright  2026 Lucas Aubert
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @type {Number} Maximum size of the captured HTML, in bytes. */
const MAX_HTML = 4096;

/** @type {RegExp} Ids generated at each rendering (uniqid(), YUI, numbered or hexadecimal suffixes). */
const UNSTABLE_ID = /^yui_|[0-9a-f]{10,}|\d{3,}|_\d+$|-\d+$/i;

/** @type {RegExp} Attributes holding ids, or references to ids. */
const REFERENCE = new RegExp('^(id|for|href|aria-(controls|labelledby|describedby|owns|activedescendant)'
    + '|data-([\\w-]*-)?(id|target|target-id|bs-target))$');

/** @type {String[]} Elements removed from the captured HTML. */
const REMOVED = ['script', 'style', 'noscript', 'template', 'iframe'];

/**
 * Whether an id looks generated (not usable in a selector).
 *
 * @param {String} id Id.
 * @returns {Boolean}
 */
export const isUnstableId = (id) => !!id && UNSTABLE_ID.test(id);

/**
 * Removes the personal data from a text.
 *
 * @param {String} text Text.
 * @param {Object} user Current user: {fullname, firstname, lastname, username, email}.
 * @returns {String}
 */
const scrubText = (text, user) => {
    let result = text.replace(/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/g, '[email]');
    ['fullname', 'email', 'username', 'firstname', 'lastname'].forEach((key) => {
        const value = (user[key] || '').trim();
        if (value.length >= 2) {
            result = result.split(value).join('[user]');
        }
    });
    return result;
};

/**
 * Builds the pruned, scrubbed copy of an element.
 *
 * @param {Element} element Element.
 * @param {Object} user Current user.
 * @param {Number} depth Children depth kept.
 * @returns {String} HTML.
 */
const pruneHtml = (element, user, depth) => {
    const copy = element.cloneNode(true);
    const prune = (node, level) => {
        [...node.childNodes].forEach((child) => {
            if (child.nodeType === Node.COMMENT_NODE) {
                child.remove();
            } else if (child.nodeType === Node.TEXT_NODE) {
                const text = child.textContent.replace(/\s+/g, ' ');
                child.textContent = text.length > 80 ? text.slice(0, 80) + '…' : text;
            } else if (child.nodeType === Node.ELEMENT_NODE) {
                const tag = child.tagName.toLowerCase();
                if (REMOVED.includes(tag)) {
                    child.remove();
                } else if (tag === 'svg') {
                    child.replaceChildren();
                } else if (level >= depth) {
                    child.replaceChildren(document.createTextNode('…'));
                } else {
                    prune(child, level + 1);
                }
            }
        });
    };
    prune(copy, 0);
    [copy, ...copy.querySelectorAll('*')].forEach((node) => {
        // Profile links of any user: their text is a name.
        const href = node.getAttribute('href') || '';
        if (/\/user\/(profile|view)\.php/.test(href)) {
            node.replaceChildren(document.createTextNode('[user]'));
        }
        // Form values.
        if (node.tagName === 'INPUT' || node.tagName === 'TEXTAREA') {
            node.removeAttribute('value');
            node.textContent = '';
        }
        if (node.tagName === 'OPTION') {
            node.removeAttribute('selected');
        }
        // User pictures.
        if (node.tagName === 'IMG' && (/\/user\/icon\//.test(node.getAttribute('src') || '')
                || node.classList.contains('userpicture'))) {
            node.setAttribute('alt', '[user]');
        }
        [...node.attributes].forEach((attribute) => {
            const name = attribute.name;
            if (['src', 'srcset', 'style', 'value'].includes(name) || name.startsWith('on')) {
                node.removeAttribute(name);
                return;
            }
            let value = attribute.value.replace(/([?&])sesskey=[^&]*/g, '$1sesskey=[…]');
            value = scrubText(value, user);
            node.setAttribute(name, value.length > 120 ? value.slice(0, 120) + '…' : value);
        });
    });
    return scrubText(copy.outerHTML, user);
};

/**
 * Size of a string in bytes (UTF-8).
 *
 * @param {String} text Text.
 * @returns {Number}
 */
const bytes = (text) => new TextEncoder().encode(text).length;

/**
 * Whether a node comes before another one in document order.
 *
 * @param {Node} node Node.
 * @param {Node} other Other node.
 * @returns {Boolean}
 */
const isBefore = (node, other) => {
    const range = node.ownerDocument.createRange();
    range.setStartBefore(node);
    range.collapse(true);
    return range.comparePoint(other, 0) > 0;
};

/**
 * Templates enclosing an element, from the template markers (innermost first).
 *
 * @param {Element} element Element.
 * @returns {String[]}
 */
export const findTemplates = (element) => {
    const doc = element.ownerDocument;
    const walker = doc.createTreeWalker(doc.body || doc.documentElement, NodeFilter.SHOW_COMMENT);
    const stack = [];
    let node;
    while ((node = walker.nextNode())) {
        // Only the markers before the element (in document order) matter; those inside it are skipped.
        if (element.contains(node)) {
            continue;
        }
        if (!isBefore(node, element)) {
            break;
        }
        const text = node.textContent.trim();
        if (text.startsWith('bt:start ')) {
            stack.push(text.slice(9).trim());
        } else if (text.startsWith('bt:end ')) {
            const name = text.slice(7).trim();
            const index = stack.lastIndexOf(name);
            if (index >= 0) {
                stack.splice(index);
            }
        }
    }
    return stack.reverse();
};

/**
 * Accessible name of an element.
 *
 * @param {Element} element Element.
 * @returns {String}
 */
const labelOf = (element) => {
    const label = element.getAttribute('aria-label') || element.getAttribute('title') || element.getAttribute('alt')
        || (element.innerText || element.textContent || '').trim().split('\n')[0]
        || element.tagName.toLowerCase();
    return label.replace(/\s+/g, ' ').trim().slice(0, 80);
};

/**
 * Locator of an element, to find it again after a reload: path of tag, stable id or classes, and position.
 *
 * @param {Element} element Element.
 * @returns {String} CSS selector.
 */
export const locatorOf = (element) => {
    const parts = [];
    let node = element;
    while (node && node.nodeType === Node.ELEMENT_NODE && node.tagName !== 'BODY') {
        let part = node.tagName.toLowerCase();
        if (node.id && !isUnstableId(node.id)) {
            parts.unshift(`${part}#${CSS.escape(node.id)}`);
            break;
        }
        const classes = [...node.classList].filter((name) => !isUnstableId(name)).slice(0, 2);
        part += classes.map((name) => `.${CSS.escape(name)}`).join('');
        const tag = node.tagName;
        const siblings = node.parentElement ? [...node.parentElement.children].filter((child) => child.tagName === tag) : [];
        if (siblings.length > 1) {
            part += `:nth-of-type(${siblings.indexOf(node) + 1})`;
        }
        parts.unshift(part);
        node = node.parentElement;
    }
    return parts.join(' > ');
};

/**
 * Describes an element for the generator.
 *
 * @param {Element} element Element.
 * @param {Object} user Current user (personal data to remove).
 * @param {Boolean} withTemplates Whether the template markers can be used.
 * @returns {Object} Selection.
 */
export const describe = (element, user, withTemplates) => {
    let html = '';
    for (let depth = 2; depth >= 0; depth--) {
        html = pruneHtml(element, user, depth);
        if (bytes(html) <= MAX_HTML) {
            break;
        }
    }
    if (bytes(html) > MAX_HTML) {
        html = html.slice(0, MAX_HTML - 10) + '…';
        while (bytes(html) > MAX_HTML) {
            html = html.slice(0, -100) + '…';
        }
    }

    const ancestors = [];
    const unstable = new Set();
    const collect = (node) => {
        // Ids, and the attributes referencing ids (for, aria-controls, data-*-id, href="#..."...).
        [...node.attributes].filter((attribute) => REFERENCE.test(attribute.name)).forEach((attribute) => {
            if (attribute.name === 'href' && !attribute.value.startsWith('#')) {
                return;
            }
            // Numbers alone are record ids (data-id="12"), not element ids.
            attribute.value.replace(/^#/, '').split(/\s+/).filter((id) => /[a-z]/i.test(id) && isUnstableId(id))
                .forEach((id) => unstable.add(id));
        });
    };
    [element, ...element.querySelectorAll('*')].forEach(collect);
    for (let node = element.parentElement; node; node = node.parentElement) {
        collect(node);
        const data = {};
        [...node.attributes].filter((attribute) => attribute.name.startsWith('data-')).slice(0, 20).forEach((attribute) => {
            data[attribute.name.slice(5)] = scrubText(attribute.value, user).slice(0, 100);
        });
        ancestors.push({tag: node.tagName.toLowerCase(), id: node.id || null, classes: [...node.classList], data});
        if (node.tagName === 'BODY') {
            break;
        }
    }

    const templates = withTemplates ? findTemplates(element) : [];
    return {
        html,
        ancestors,
        template: templates[0] || null,
        templates,
        label: scrubText(labelOf(element), user),
        unstableIds: [...unstable],
        locator: locatorOf(element),
    };
};

/**
 * Element picker bound to the preview iframe.
 */
export class Picker {
    /**
     * Constructor.
     *
     * @param {HTMLIFrameElement} frame Preview iframe.
     * @param {Function} onPick Called with the picked element.
     * @param {Function} onModeChange Called with the new mode (boolean).
     */
    constructor(frame, onPick, onModeChange) {
        this.frame = frame;
        this.onPick = onPick;
        this.onModeChange = onModeChange;
        this.active = false;
        this.overlay = null;
        this.current = null;
        this.handlers = {
            move: (e) => this.handleMove(e),
            click: (e) => this.handleClick(e),
            key: (e) => this.handleKey(e),
        };
        document.addEventListener('keydown', this.handlers.key, true);
        frame.addEventListener('load', () => this.attach());
        this.attach();
    }

    /**
     * Listens to the document of the iframe (after each navigation).
     */
    attach() {
        let doc;
        try {
            doc = this.frame.contentDocument;
        } catch (e) {
            return;
        }
        if (!doc || doc === this.doc) {
            return;
        }
        this.doc = doc;
        this.overlay = null;
        // On the window, in the capture phase: it runs before any listener of the page, even one in the capture phase
        // on the document (Moodle 5.3 opens its dropdowns from one, which a listener on the document did not stop).
        const win = doc.defaultView || doc;
        win.addEventListener('mousemove', this.handlers.move, true);
        win.addEventListener('click', this.handlers.click, true);
        win.addEventListener('keydown', this.handlers.key, true);
        if (this.active) {
            this.ensureOverlay();
        }
    }

    /**
     * Turns the picking mode on or off.
     *
     * @param {Boolean} active Mode.
     */
    setActive(active) {
        this.active = active;
        if (!active && this.overlay) {
            this.overlay.style.display = 'none';
        }
        this.current = null;
        this.onModeChange(active);
    }

    /**
     * Creates the highlight overlay in the iframe.
     *
     * @returns {HTMLElement}
     */
    ensureOverlay() {
        if (!this.overlay || !this.overlay.isConnected) {
            const overlay = this.doc.createElement('div');
            overlay.setAttribute('data-brandable-picker', '');
            // Highlight of the brand (spec of the brand, E13): chartreuse outline with a dark edge on both sides, readable
            // on light and dark pages; all: initial, so that the CSS of the page shown does not change it.
            overlay.style.cssText = 'all:initial;position:fixed;z-index:2147483647;pointer-events:none;display:none;'
                + 'box-sizing:border-box;border:2px solid #d4f25a;border-radius:6px;background:rgba(212,242,90,.14);'
                + 'box-shadow:0 0 0 1px #15171c,inset 0 0 0 1px #15171c;';
            const label = this.doc.createElement('span');
            label.style.cssText = 'all:initial;position:absolute;left:-3px;top:-24px;padding:1px 6px;border-radius:6px;'
                + 'border:1px solid #15171c;background:#d4f25a;color:#15171c;white-space:nowrap;'
                + 'font:600 11px/18px var(--bs-body-font-family,system-ui,sans-serif);';
            overlay.append(label);
            this.doc.body.append(overlay);
            this.overlay = overlay;
        }
        return this.overlay;
    }

    /**
     * Highlights the element under the mouse.
     *
     * @param {MouseEvent} e Event.
     */
    handleMove(e) {
        const target = e.target;
        if (!this.active || !(target instanceof this.frame.contentWindow.Element) || target.hasAttribute('data-brandable-picker')) {
            return;
        }
        this.current = e.target;
        const overlay = this.ensureOverlay();
        const box = e.target.getBoundingClientRect();
        Object.assign(overlay.style, {display: 'block', left: `${box.left}px`, top: `${box.top}px`,
            width: `${box.width}px`, height: `${box.height}px`});
        const templates = findTemplates(e.target);
        overlay.firstChild.textContent = e.target.tagName.toLowerCase() + (templates[0] ? ` · ${templates[0]}` : '')
            + ` · ${labelOf(e.target)}`;
    }

    /**
     * Captures the clicked element (picking mode, or Alt+click).
     *
     * @param {MouseEvent} e Event.
     */
    handleClick(e) {
        if (!this.active && !e.altKey) {
            return;
        }
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();
        const element = e.target;
        this.setActive(false);
        this.onPick(element);
    }

    /**
     * Alt+Shift+C toggles the picking mode; Escape leaves it.
     *
     * @param {KeyboardEvent} e Event.
     */
    handleKey(e) {
        if (e.altKey && e.shiftKey && e.code === 'KeyC') {
            e.preventDefault();
            this.setActive(!this.active);
        } else if (e.key === 'Escape' && this.active) {
            this.setActive(false);
        }
    }
}
