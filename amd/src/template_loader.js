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
 * Serves the Brandable template overrides to the templates rendered in JavaScript (core/templates).
 *
 * Plugged in with the public Renderer.setLoader() API: a subclass of core's template Loader answers for the
 * overridden templates (and their partials), everything else goes through core unchanged.
 *
 * The page head lists, for each overridden template, the content hash of the variant (scope) that applies to
 * the page, in the active revision (published, or the draft in preview). Contents of all the variants are
 * fetched on first use, in one call per revision (cached in local storage, and as an HTTP-cacheable GET for
 * the published revision: a revision never changes); the variant is picked by hash.
 *
 * @module     theme_brandable/template_loader
 * @copyright  2026 Lucas Aubert
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import $ from 'jquery';
import Ajax from 'core/ajax';
import config from 'core/config';
import LocalStorage from 'core/localstorage';
import Loader from 'core/local/templates/loader';
import Renderer from 'core/local/templates/renderer';

/** @type {Object|null} Active overrides: {revision, assets, public, hashes: Map<name, contenthash>, markers}. */
let overrides = null;

/** @type {Promise<Object>|null} Override contents by content hash. */
let contents = null;

/** @type {Map<String, Object>} Promises of the overridden templates, by search key. */
const promises = new Map();

/** @type {Set<String>} Search keys whose override is installed in the template cache. */
const installed = new Set();

/**
 * Fetches the template overrides of the active revision (once).
 *
 * @returns {Promise<Object>} Contents by content hash.
 */
const fetchContents = () => {
    if (contents) {
        return contents;
    }
    // The contents carry the URLs of the assets: the revision of the library is part of the key.
    const key = `theme_brandable/overrides/v3/${overrides.revision}-${overrides.assets || 0}`;
    const cached = LocalStorage.get(key);
    if (cached) {
        contents = Promise.resolve(JSON.parse(cached));
        return contents;
    }
    const request = {methodname: 'theme_brandable_get_template_overrides',
        args: {revisionid: overrides.revision, assets: overrides.assets || 0}};
    // The published revision is public and immutable: no login, HTTP cache keyed by the revision.
    const call = overrides.public
        ? Ajax.call([request], true, false, false, 0, overrides.revision)[0]
        : Ajax.call([request])[0];
    contents = Promise.resolve(call).then((response) => {
        const result = {};
        response.templates.forEach((template) => {
            result[template.contenthash] = template.content;
        });
        LocalStorage.set(key, JSON.stringify(result));
        return result;
    });
    return contents;
};

/**
 * Wraps a template source in the template markers (preview), like the PHP partials loader: only sources with
 * HTML tags, never twice. Mustache comments are not output and are ignored.
 *
 * @param {String} name Template name.
 * @param {String} source Template source.
 * @returns {String}
 */
const wrap = (name, source) => {
    if (!overrides || !overrides.markers || typeof source !== 'string') {
        return source;
    }
    const markup = source.replace(/\{\{!.*?\}\}/gs, '').trim();
    if (!/<[a-zA-Z]/.test(markup) || markup.startsWith('<!-- bt:start ')) {
        return source;
    }
    const safe = name.replace(/--/g, '-');
    return `<!-- bt:start ${safe} -->${source}<!-- bt:end ${safe} -->`;
};

/**
 * Name of a template from its cache search key (theme/component/name).
 *
 * @param {String} searchKey Search key.
 * @returns {String}
 */
const nameFromKey = (searchKey) => searchKey.split('/').slice(1).join('/');

/**
 * Template loader answering for the overridden templates (and adding the template markers in preview).
 */
class BrandableLoader extends Loader {
    /**
     * Whether a template is overridden by the active revision.
     *
     * @param {String} templateName Template name (component/name).
     * @returns {Boolean}
     */
    static isOverridden(templateName) {
        return overrides !== null && overrides.hashes.has(templateName);
    }

    /**
     * Loads a template: the override when there is one, core's template otherwise.
     *
     * @param {String} templateName Template name (component/name).
     * @param {String} [themeName=config.theme] Theme name.
     * @returns {Promise} jQuery promise of the template source.
     */
    static getTemplate(templateName, themeName = config.theme) {
        if (!this.isOverridden(templateName)) {
            return super.getTemplate(templateName, themeName).then((source) => wrap(templateName, source));
        }
        const searchKey = this.getSearchKey(themeName, templateName);
        if (!promises.has(searchKey)) {
            const deferred = $.Deferred();
            promises.set(searchKey, deferred.promise());
            fetchContents()
                .then((result) => {
                    const source = result[overrides.hashes.get(templateName)];
                    if (source === undefined) {
                        throw new Error(`No override for ${templateName}`);
                    }
                    installed.add(searchKey);
                    // Directly into the cache: partialHelper() reads it synchronously.
                    this.templateCache.set(searchKey, wrap(templateName, source));
                    deferred.resolve(wrap(templateName, source));
                    return source;
                })
                .catch(() => {
                    // Never break the page: fall back on core's template. Our pending promise must be forgotten
                    // first, or core would hand it back to us.
                    promises.delete(searchKey);
                    if (this.templatePromises[searchKey] === deferred.promise()) {
                        delete this.templatePromises[searchKey];
                    }
                    super.getTemplate(templateName, themeName).then(deferred.resolve, deferred.reject);
                });
        }
        // Core may have cached a promise of the original (prefetch): ours takes precedence.
        this.templatePromises[searchKey] = promises.get(searchKey);
        return promises.get(searchKey);
    }

    /**
     * Prefetches a template: overridden templates are fetched as overrides.
     *
     * @param {String} templateName Template name.
     * @param {String} themeName Theme name.
     */
    static prefetchTemplate(templateName, themeName) {
        if (this.isOverridden(templateName)) {
            this.getTemplate(templateName, themeName);
            return;
        }
        super.prefetchTemplate(templateName, themeName);
    }

    /**
     * Stores a template in the cache, unless it would replace an installed override.
     *
     * The server sends the dependencies of the templates it returns: their original version must not
     * replace an override used as a partial.
     *
     * @param {String} searchKey Search key.
     * @param {String} templateSource Template source.
     */
    static setTemplateInCache(searchKey, templateSource) {
        if (installed.has(searchKey)) {
            return;
        }
        // The cache feeds partialHelper(): partials get their markers here.
        super.setTemplateInCache(searchKey, wrap(nameFromKey(searchKey), templateSource));
    }
}

/**
 * Plugs the loader into core/templates when the active revision overrides templates.
 */
export const init = () => {
    const data = window.theme_brandable_overrides;
    const templates = data && data.templates ? Object.entries(data.templates) : [];
    if (!data || (!templates.length && !data.markers) || overrides !== null) {
        return;
    }
    overrides = {revision: Number(data.revision), assets: Number(data.assets || 0), public: !!data.public,
        hashes: new Map(templates), markers: !!data.markers};
    Renderer.setLoader(BrandableLoader);
};

/**
 * Returns the loader class (tests).
 *
 * @returns {Function}
 */
export const getLoader = () => BrandableLoader;
