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
 * Verification of a generated revision in the preview: the selectors of its SCSS chunks (as compiled, scope
 * wrapper included) are run against the real DOM of the page.
 *
 * - Pseudo-elements and state pseudo-classes are removed before matching (::before, :hover...).
 * - Selectors still unmatched are checked again while the page changes, for a few seconds (elements created
 *   after load).
 * - Only the selectors added by the revision are checked, and not those meant for other pages (page context).
 * - A selector matching nothing is a failure only when the check is conclusive: an element was targeted on
 *   this page, or the chunk is scoped to this page; otherwise a site-wide chunk may target another page.
 * - With a targeted element, selectors also matching elements neither in it nor around it are reported as warnings.
 *
 * @module     theme_brandable/verifier
 * @copyright  2026 Lucas Aubert
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @type {RegExp} Pseudo-elements (not matchable). */
const PSEUDO_ELEMENT = new RegExp('::?(before|after|placeholder|selection|marker|first-line|first-letter|backdrop|'
    + 'file-selector-button|cue|-webkit-[a-z-]+|-moz-[a-z-]+)\\b', 'gi');

/** @type {RegExp} State pseudo-classes (depend on interaction). */
const STATE_PSEUDO_CLASS = new RegExp(':(hover|focus-within|focus-visible|focus|active|visited|link|any-link|checked|disabled|'
    + 'enabled|target|indeterminate|placeholder-shown|valid|invalid|required|optional|read-only|read-write|default|'
    + 'autofill|-webkit-autofill)(?![\\w-(])', 'gi');

/** @type {RegExp} Colour mode of the page (attribute of the html tag, Bootstrap 5.3 and the brandable-dark mixin). */
const COLOUR_MODE = /\[data-bs-theme=(["']?)(?:dark|light)\1\]/g;

/** @type {String[]} Properties holding a colour, checked for a colour fixed in both modes. */
const COLOUR_PROPERTIES = ['color', 'background-color', 'border-top-color', 'border-right-color', 'border-bottom-color',
    'border-left-color', 'outline-color', 'fill', 'stroke'];

/** @type {RegExp} Values that follow the mode, or set no colour of their own. */
const FOLLOWS_MODE = /var\(|inherit|initial|unset|revert|currentcolor|transparent|none/i;

/** @type {Number} Contrast expected of text (WCAG AA), 3 for large text. */
const MIN_CONTRAST = 4.5;

/** @type {Number} Elements measured for the contrast, at most. */
const MAX_MEASURED = 30;

/** @type {Number} How long unmatched selectors are watched for, in milliseconds. */
const WATCH = 5000;

/**
 * Turns a compiled selector into one querySelectorAll() can run, or null when it cannot be checked.
 *
 * @param {String} selector Selector.
 * @returns {String|null}
 */
export const normaliseSelector = (selector) => {
    // The colour mode on the html tag (brandable-dark): the element is the same in both modes, checked in any.
    let result = selector.replace(COLOUR_MODE, ' ').replace(PSEUDO_ELEMENT, '').replace(STATE_PSEUDO_CLASS, '')
        // Functional pseudo-classes left empty (e.g. ":not(:disabled)").
        .replace(/:(not|is|where|has)\(\s*\)/g, '')
        .replace(/\s+/g, ' ').trim();
    // A combinator left without its compound (e.g. "a > :hover") applies to any element.
    result = result.replace(/([>+~])$/, '$1 *').replace(/^([>+~])/, '* $1');
    return result === '' || result === '*' ? null : result;
};

/**
 * What the scopes are matched against, read from the body of the page (Brandable page key, page type,
 * course, activity).
 *
 * @param {Document} doc Page.
 * @returns {Object} {pagekey, pagetype, courseid, cmid}
 */
export const pageOf = (doc) => {
    const classes = [...doc.body.classList];
    const find = (prefix) => {
        const name = classes.find((value) => value.startsWith(prefix) && /^\d+$/.test(value.slice(prefix.length)));
        return name ? name.slice(prefix.length) : null;
    };
    return {
        pagekey: classes.find((name) => name.startsWith('bp-')) || null,
        pagetype: doc.body.id.startsWith('page-') ? doc.body.id.slice(5) : null,
        courseid: find('course-'),
        cmid: find('cmid-'),
    };
};

/**
 * Whether a change is scoped to the page (site-wide changes are not).
 *
 * @param {Object} change Change ({scopelevel, scopevalue}).
 * @param {Object} page Page (pageOf()).
 * @returns {Boolean}
 */
export const scopedToPage = (change, page) => {
    switch (change.scopelevel) {
        case 'pagetype':
            return page.pagetype === change.scopevalue;
        case 'page':
            return page.pagekey === change.scopevalue;
        case 'course':
            return page.courseid === change.scopevalue;
        case 'cm':
            return page.cmid === change.scopevalue;
        default:
            return false;
    }
};

/**
 * Runs a selector.
 *
 * @param {Document} doc Page.
 * @param {String} selector Normalised selector.
 * @returns {Element[]|null} Matches, null when the browser cannot run the selector.
 */
const query = (doc, selector) => {
    try {
        return [...doc.querySelectorAll(selector)];
    } catch (e) {
        return null;
    }
};

/**
 * Waits until every selector matches something, or the watch time is over.
 *
 * @param {Document} doc Page.
 * @param {String[]} selectors Normalised selectors without match.
 * @returns {Promise<Set<String>>} Selectors still without match.
 */
const watch = (doc, selectors) => new Promise((resolve) => {
    const pending = new Set(selectors);
    if (!pending.size) {
        resolve(pending);
        return;
    }
    const check = () => {
        pending.forEach((selector) => {
            if ((query(doc, selector) || []).length) {
                pending.delete(selector);
            }
        });
        if (!pending.size) {
            done();
        }
    };
    const observer = new doc.defaultView.MutationObserver(check);
    const timer = setTimeout(() => done(), WATCH);
    const done = () => {
        observer.disconnect();
        clearTimeout(timer);
        resolve(pending);
    };
    observer.observe(doc.documentElement, {childList: true, subtree: true, attributes: true});
});

/** @type {RegExp} Moodle page context in a selector (body classes and id): the rule is meant for some pages only. */
const PAGE_CONTEXT = /\.(?:path|pagelayout|format|bp)-[\w-]+|\.(?:course|cmid|context|category)-\d+\b|#page-[\w-]+/g;

/**
 * Whether a selector is meant for another page: it requires a page context (body class or id) absent here.
 * Tokens inside :not() are ignored (scope wrappers).
 *
 * @param {Document} doc Page.
 * @param {String} selector Selector.
 * @returns {Boolean}
 */
export const forOtherPage = (doc, selector) => {
    const tokens = selector.replace(/:not\([^()]*\)/g, '').match(PAGE_CONTEXT) || [];
    return tokens.some((token) => !doc.querySelector(token));
};

/**
 * Verifies the SCSS changes of a revision on a page.
 *
 * Only the selectors added by the revision are checked: those already in the draft it started from were checked by
 * their own generation, and reporting them would have the repair drop earlier work. Selectors meant for other pages
 * (page context absent here) are not checked either.
 *
 * @param {Document} doc Page shown in the preview.
 * @param {Array} changes Changes of the revision ({type, target, deleted, scopelevel, scopevalue, selectors}).
 * @param {Object|null} selection Targeted element (with its locator), if any.
 * @param {Array} baseChanges Changes of the draft the revision started from (same shape), if any.
 * @returns {Promise<Object>} {failures: [{target, selector}], warnings: [{target, selector, count}], checked, unverifiable,
 *     otherpage}
 */
export const verify = async(doc, changes, selection, baseChanges = []) => {
    const page = pageOf(doc);
    const selected = selection && selection.locator ? (query(doc, selection.locator) || [])[0] || null : null;
    const key = (change) => `${change.target}@${change.scopelevel || 'site'}:${change.scopevalue || ''}`;
    const known = new Map(baseChanges.filter((change) => change.type === 'scss' && !change.deleted)
        .map((change) => [key(change), new Set(change.selectors || [])]));
    const checks = [];
    let unverifiable = 0;
    let otherpage = 0;
    changes.filter((change) => change.type === 'scss' && !change.deleted).forEach((change) => {
        const previous = known.get(key(change)) || new Set();
        (change.selectors || []).filter((selector) => !previous.has(selector)).forEach((selector) => {
            const normalised = normaliseSelector(selector);
            if (normalised === null || query(doc, normalised) === null) {
                unverifiable++;
                return;
            }
            if (forOtherPage(doc, normalised)) {
                otherpage++;
                return;
            }
            checks.push({change, selector, normalised});
        });
    });

    const missing = await watch(doc, checks.filter((check) => !query(doc, check.normalised).length)
        .map((check) => check.normalised));

    const failures = [];
    const warnings = [];
    checks.forEach(({change, selector, normalised}) => {
        if (missing.has(normalised)) {
            // Conclusive only for this page: an element was targeted here, or the chunk is scoped to it.
            if (selection || scopedToPage(change, page)) {
                failures.push({target: change.target, selector});
            }
            return;
        }
        if (selected) {
            // Ancestors of the targeted element are fine (a rule on its container), unrelated elements are not.
            const outside = query(doc, normalised).filter((element) => !selected.contains(element) && !element.contains(selected));
            if (outside.length) {
                warnings.push({target: change.target, selector, count: outside.length});
            }
        }
    });
    return {failures, warnings, checked: checks.length, unverifiable, otherpage};
};

/**
 * Selector as the browser and scssphp both write it: whitespace and quotes of the attribute selectors normalised.
 *
 * @param {String} selector Selector.
 * @returns {String}
 */
const canonical = (selector) => selector.replace(/\s+/g, ' ').replace(/\[([\w-]+)=["']?([^"'\]]*)["']?\]/g, '[$1="$2"]')
    .trim();

/**
 * Style rules of the page, by selector (each selector of a list on its own), media rules included.
 *
 * @param {Document} doc Page.
 * @returns {Map<String, CSSStyleDeclaration[]>}
 */
const styleRules = (doc) => {
    const rules = new Map();
    const walk = (list) => {
        [...list].forEach((rule) => {
            if (rule.selectorText !== undefined) {
                rule.selectorText.split(',').forEach((selector) => {
                    const key = canonical(selector);
                    rules.set(key, (rules.get(key) || []).concat([rule.style]));
                });
            } else if (rule.cssRules) {
                walk(rule.cssRules);
            }
        });
    };
    [...doc.styleSheets].forEach((sheet) => {
        try {
            walk(sheet.cssRules);
        } catch (e) {
            // Style sheet of another origin: not readable, not Moodle's.
        }
    });
    return rules;
};

/**
 * Relative luminance of a computed colour (rgb() or rgba()), with its alpha.
 *
 * @param {String} colour Computed colour.
 * @returns {{l: Number, a: Number, rgb: Number[]}|null}
 */
const parseColour = (colour) => {
    const match = /rgba?\(([\d.]+),\s*([\d.]+),\s*([\d.]+)(?:,\s*([\d.]+))?\)/.exec(colour || '');
    if (!match) {
        return null;
    }
    const rgb = [match[1], match[2], match[3]].map(Number);
    const [r, g, b] = rgb.map((value) => {
        const channel = value / 255;
        return channel <= 0.04045 ? channel / 12.92 : Math.pow((channel + 0.055) / 1.055, 2.4);
    });
    return {l: 0.2126 * r + 0.7152 * g + 0.0722 * b, a: match[4] === undefined ? 1 : Number(match[4]), rgb};
};

/**
 * Background under an element: the first ancestor background that is not transparent (white at the top).
 *
 * @param {Element} element Element.
 * @returns {Object} Parsed colour.
 */
const backgroundOf = (element) => {
    const view = element.ownerDocument.defaultView;
    for (let node = element; node && node.nodeType === 1; node = node.parentElement) {
        const colour = parseColour(view.getComputedStyle(node).backgroundColor);
        if (colour && colour.a >= 0.5) {
            return colour;
        }
    }
    return parseColour('rgb(255, 255, 255)');
};

/**
 * Contrast ratio of the text of an element on its background, null when it has no text of its own or is hidden.
 *
 * @param {Element} element Element.
 * @returns {{ratio: Number, large: Boolean}|null}
 */
const contrastOf = (element) => {
    const hastext = [...element.childNodes].some((node) => node.nodeType === 3 && node.textContent.trim() !== '');
    if (!hastext || !element.getClientRects().length) {
        return null;
    }
    const style = element.ownerDocument.defaultView.getComputedStyle(element);
    const text = parseColour(style.color);
    if (!text || text.a < 0.5 || style.visibility === 'hidden') {
        return null;
    }
    const back = backgroundOf(element);
    const [light, dark] = text.l > back.l ? [text.l, back.l] : [back.l, text.l];
    const size = parseFloat(style.fontSize);
    const bold = parseInt(style.fontWeight, 10) >= 700;
    return {ratio: (light + 0.05) / (dark + 0.05), large: size >= 24 || (bold && size >= 18.66)};
};

/**
 * Colour checks of the SCSS changes of a revision (colour modes of Moodle 5.3, spec CM4 and CM6): colours fixed in
 * both modes without a dark variant, and text below the AA contrast, in the mode shown and, when the modes are on,
 * in the other one (the attribute of the html tag switched for the time of the measure, transitions off). Warnings
 * only.
 *
 * @param {Document} doc Page shown in the preview.
 * @param {Array} changes Changes of the revision (see verify()).
 * @param {Array} baseChanges Changes of the draft the revision started from.
 * @param {Object} modes Colour modes of the site ({available, enabled}).
 * @returns {Array} [{target, selector, kind: 'fixed'|'contrast', mode, ratio}]
 */
export const colourFindings = (doc, changes, baseChanges, modes) => {
    if (!modes || !modes.available) {
        return [];
    }
    const key = (change) => `${change.target}@${change.scopelevel || 'site'}:${change.scopevalue || ''}`;
    const known = new Map(baseChanges.filter((change) => change.type === 'scss' && !change.deleted)
        .map((change) => [key(change), new Set(change.selectors || [])]));
    const added = [];
    changes.filter((change) => change.type === 'scss' && !change.deleted).forEach((change) => {
        const previous = known.get(key(change)) || new Set();
        (change.selectors || []).filter((selector) => !previous.has(selector))
            .forEach((selector) => added.push({change, selector}));
    });
    const rules = styleRules(doc);
    const findings = [];
    // Selectors of the dark variants of the revision, without their prefix (search() ignores the g flag).
    const dark = new Set(added.filter(({selector}) => selector.search(COLOUR_MODE) !== -1)
        .map(({selector}) => canonical(selector.replace(COLOUR_MODE, ' '))));

    // Colours fixed in both modes: a literal colour on a selector with no dark variant.
    added.forEach(({change, selector}) => {
        if (selector.search(COLOUR_MODE) !== -1) {
            return;
        }
        const name = canonical(selector);
        const fixed = (rules.get(name) || []).some((style) => COLOUR_PROPERTIES.some((property) => {
            const value = style.getPropertyValue(property);
            return value !== '' && !FOLLOWS_MODE.test(value);
        }));
        if (fixed && !dark.has(name) && !rules.has(canonical(`[data-bs-theme="dark"] ${selector}`))) {
            findings.push({target: change.target, selector, kind: 'fixed', mode: '', ratio: 0});
        }
    });

    // Contrast of the text of the elements the revision styles, in each mode.
    const elements = [];
    added.forEach(({change, selector}) => {
        const normalised = normaliseSelector(selector);
        if (normalised === null || forOtherPage(doc, normalised)) {
            return;
        }
        (query(doc, normalised) || []).slice(0, 3).forEach((element) => {
            if (elements.length < MAX_MEASURED && !elements.some((item) => item.element === element)) {
                elements.push({element, change, selector});
            }
        });
    });
    const root = doc.documentElement;
    const shown = root.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
    const measure = (mode) => elements.forEach(({element, change, selector}) => {
        const contrast = contrastOf(element);
        if (contrast && contrast.ratio < (contrast.large ? 3 : MIN_CONTRAST)) {
            findings.push({target: change.target, selector, kind: 'contrast', mode,
                ratio: Math.floor(contrast.ratio * 10) / 10});
        }
    });
    measure(shown);
    if (modes.enabled && elements.length) {
        const other = shown === 'dark' ? 'light' : 'dark';
        const notransition = doc.createElement('style');
        notransition.textContent = '*, *::before, *::after { transition: none !important; animation: none !important; }';
        doc.head.append(notransition);
        root.setAttribute('data-bs-theme', other);
        try {
            measure(other);
        } finally {
            root.setAttribute('data-bs-theme', shown);
            notransition.remove();
        }
    }
    // One finding per selector, mode and kind.
    const seen = new Set();
    return findings.filter((finding) => {
        const id = `${finding.kind}|${finding.mode}|${finding.target}|${finding.selector}`;
        return !seen.has(id) && seen.add(id);
    });
};
