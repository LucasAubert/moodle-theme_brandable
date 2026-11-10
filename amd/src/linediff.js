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
 * Line diff of two sources, shown with textContent only (the JavaScript review, the comparison of versions of the
 * cloud). A module without a default export: Moodle returns only the default export of an AMD module, so the named
 * exports of a module with one cannot be imported.
 *
 * @module     theme_brandable/linediff
 * @copyright  2026 Lucas Aubert
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @var {Number} Largest table of the longest common subsequence (lines of the middle before × after). */
const MAX_TABLE = 4000000;

/** @type {Object<String, String>} Class of a line by its op. */
const LINE_CLASSES = {'+': ' is-added', '-': ' is-removed'};

/**
 * Returns the line diff of two sources (longest common subsequence of the lines between the common start and end;
 * when the middle is too large, it is shown removed then added).
 *
 * @param {String} before Source before ('' when none).
 * @param {String} after Source after ('' when none).
 * @returns {Array<{op: String, text: String}>} Lines: op is ' ', '+' or '-'.
 */
export const diff = (before, after) => {
    const a = before === '' ? [] : before.split('\n');
    const b = after === '' ? [] : after.split('\n');
    const [start, end] = commonEnds(a, b);
    const lines = a.slice(0, start).map((text) => ({op: ' ', text}));
    lines.push(...diffMiddle(a.slice(start, a.length - end), b.slice(start, b.length - end)));
    lines.push(...a.slice(a.length - end).map((text) => ({op: ' ', text})));
    return lines;
};

/**
 * Returns the number of lines common to the start, and to the end, of two sources.
 *
 * @param {String[]} a Lines before.
 * @param {String[]} b Lines after.
 * @returns {Number[]} [start, end].
 */
const commonEnds = (a, b) => {
    let start = 0;
    while (start < a.length && start < b.length && a[start] === b[start]) {
        start++;
    }
    let end = 0;
    while (end < a.length - start && end < b.length - start && a[a.length - 1 - end] === b[b.length - 1 - end]) {
        end++;
    }
    return [start, end];
};

/**
 * Returns the line diff of the middles of two sources (between their common start and end).
 *
 * @param {String[]} middleA Lines before.
 * @param {String[]} middleB Lines after.
 * @returns {Array<{op: String, text: String}>}
 */
const diffMiddle = (middleA, middleB) => {
    const lines = [];
    if (middleA.length * middleB.length > MAX_TABLE) {
        lines.push(...middleA.map((text) => ({op: '-', text})), ...middleB.map((text) => ({op: '+', text})));
    } else {
        const lengths = Array.from({length: middleA.length + 1}, () => new Uint32Array(middleB.length + 1));
        for (let i = middleA.length - 1; i >= 0; i--) {
            for (let j = middleB.length - 1; j >= 0; j--) {
                lengths[i][j] = middleA[i] === middleB[j]
                    ? lengths[i + 1][j + 1] + 1 : Math.max(lengths[i + 1][j], lengths[i][j + 1]);
            }
        }
        let i = 0;
        let j = 0;
        while (i < middleA.length || j < middleB.length) {
            if (i < middleA.length && j < middleB.length && middleA[i] === middleB[j]) {
                lines.push({op: ' ', text: middleA[i]});
                i++;
                j++;
            } else if (i < middleA.length && (j >= middleB.length || lengths[i + 1][j] >= lengths[i][j + 1])) {
                // On a tie, the line removed first, as in a usual diff.
                lines.push({op: '-', text: middleA[i]});
                i++;
            } else {
                lines.push({op: '+', text: middleB[j]});
                j++;
            }
        }
    }
    return lines;
};

/**
 * Renders the line diff of two sources (textContent only).
 *
 * @param {String} before Source before ('' when none).
 * @param {String} after Source after ('' when none).
 * @returns {HTMLElement}
 */
export const renderDiff = (before, after) => {
    const pre = document.createElement('pre');
    pre.className = 'tbe-code tbe-diff';
    diff(before, after).forEach(({op, text}) => {
        const line = document.createElement('span');
        line.className = 'tbe-diff-line' + (LINE_CLASSES[op] || '');
        line.textContent = `${op} ${text}`;
        pre.append(line);
    });
    return pre;
};
