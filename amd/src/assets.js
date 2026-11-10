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
 * Brandable editor: library of images and fonts (panel, upload by drag and drop, paste or file picker, attachments of
 * the prompt). Spec docs/SPEC-assets.md, A2 and A7.
 *
 * Images are prepared here before the upload (spec A2): orientation applied, reduced beyond 2560 px per side,
 * re-encoded (metadata dropped), WebP version and thumbnail (512 px, 400 KB). GIF files are neither reduced nor
 * converted (animations). The server checks every file again.
 *
 * @module     theme_brandable/assets
 * @copyright  2026 Lucas Aubert
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import * as Repository from 'theme_brandable/repository';
import Templates from 'core/templates';
import Notification from 'core/notification';
import {getString} from 'core/str';

/** @type {Number} Largest file (bytes). */
const MAX_FILE = 10 * 1024 * 1024;
/** @type {Number} Largest side of an image served. */
const MAX_SIDE = 2560;
/** @type {Number} Largest side of a thumbnail. */
const THUMBNAIL_SIDE = 512;
/** @type {Number} Largest thumbnail (bytes). */
const THUMBNAIL_BYTES = 400 * 1024;
/** @type {Number} Most images attached to a prompt (contract 11.2: 4 thumbnails per request). */
export const MAX_ATTACHMENTS = 4;

/** @type {Object} Types the browser re-encodes, by MIME type. */
const REENCODED = {'image/png': true, 'image/jpeg': true, 'image/webp': true};

/** @type {HTMLElement} Editor root. */
let root;
/** @type {Object|null} Library (get_assets result). */
let library = null;
/** @type {String} Kind shown in the panel: image or font. */
let filter = 'image';
/** @type {Number} Asset being edited in the panel, 0 for none. */
let editing = 0;
/** @type {Object[]} Images attached to the next prompt ({id, slug, thumbnailurl}). */
let attachments = [];
/** @type {Object} Fonts already loaded for their preview, by asset id. */
const loadedFonts = {};

/**
 * Returns a region of the editor.
 *
 * @param {String} name Region name.
 * @returns {HTMLElement|null}
 */
const region = (name) => root.querySelector(`[data-region="${name}"]`);

/**
 * Encodes a canvas.
 *
 * @param {HTMLCanvasElement} canvas Canvas.
 * @param {String} type MIME type.
 * @param {Number} quality Quality (JPEG, WebP).
 * @returns {Promise<Blob|null>} Null when the browser cannot write this type (it falls back to PNG).
 */
const encode = (canvas, type, quality = 0.85) => new Promise((resolve) => {
    canvas.toBlob((blob) => resolve(blob && blob.type === type ? blob : null), type, quality);
});

/**
 * Draws an image on a canvas, scaled down to a largest side.
 *
 * @param {CanvasImageSource} source Image.
 * @param {Number} width Width of the image.
 * @param {Number} height Height.
 * @param {Number} side Largest side.
 * @param {Boolean} opaque Whether to draw on white (JPEG).
 * @returns {HTMLCanvasElement}
 */
const draw = (source, width, height, side, opaque = false) => {
    const scale = Math.min(1, side / Math.max(width, height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(width * scale));
    canvas.height = Math.max(1, Math.round(height * scale));
    const context = canvas.getContext('2d');
    if (opaque) {
        context.fillStyle = '#fff';
        context.fillRect(0, 0, canvas.width, canvas.height);
    }
    context.imageSmoothingQuality = 'high';
    context.drawImage(source, 0, 0, canvas.width, canvas.height);
    return canvas;
};

/**
 * Loads a file in an image element (SVG, icons: what createImageBitmap may not decode).
 *
 * @param {Blob} file File.
 * @returns {Promise<HTMLImageElement>}
 */
const loadImage = (file) => new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file);
    const image = new Image();
    image.onload = () => {
        URL.revokeObjectURL(url);
        resolve(image);
    };
    image.onerror = () => {
        URL.revokeObjectURL(url);
        reject(new Error('decode'));
    };
    image.src = url;
});

/**
 * Makes the thumbnail of an image (WebP, else JPEG; within 400 KB).
 *
 * @param {CanvasImageSource} source Image.
 * @param {Number} width Width.
 * @param {Number} height Height.
 * @returns {Promise<Blob|null>}
 */
const thumbnail = async(source, width, height) => {
    const canvas = draw(source, width, height, THUMBNAIL_SIDE);
    for (const quality of [0.8, 0.6, 0.4]) {
        const blob = await encode(canvas, 'image/webp', quality) || await encode(draw(source, width, height,
            THUMBNAIL_SIDE, true), 'image/jpeg', quality);
        if (blob && blob.size <= THUMBNAIL_BYTES) {
            return blob;
        }
    }
    return null;
};

/**
 * Prepares the versions of an image (spec A2). The server checks them; any version left out is made there if it can.
 *
 * @param {File} file Image chosen by the administrator.
 * @returns {Promise<Object>} {optimized, webp, thumbnail}: Blobs or null.
 */
export const prepareImage = async(file) => {
    const versions = {optimized: null, webp: null, thumbnail: null};
    try {
        if (REENCODED[file.type]) {
            // EXIF orientation applied; drawing drops every metadata (GPS of a photo).
            const bitmap = await createImageBitmap(file, {imageOrientation: 'from-image'});
            const canvas = draw(bitmap, bitmap.width, bitmap.height, MAX_SIDE, file.type === 'image/jpeg');
            versions.optimized = await encode(canvas, file.type);
            if (file.type !== 'image/webp') {
                versions.webp = await encode(canvas, 'image/webp');
            }
            versions.thumbnail = await thumbnail(bitmap, bitmap.width, bitmap.height);
            bitmap.close();
        } else {
            // GIF (kept as is: animations), SVG and icons: only the thumbnail.
            const image = await loadImage(file);
            const width = image.naturalWidth || THUMBNAIL_SIDE;
            const height = image.naturalHeight || THUMBNAIL_SIDE;
            versions.thumbnail = await thumbnail(image, width, height);
        }
    } catch (error) {
        // The server makes what it can (and refuses what it cannot read).
    }
    return versions;
};

/**
 * Sends a file to the library (assets.php).
 *
 * @param {File} file File.
 * @param {Object} fields Other fields (kind, slug, alt, family, weight, style).
 * @param {Object} versions Versions (optimized, webp, thumbnail, licence): Blobs or null.
 * @returns {Promise<Object>} Asset added.
 */
const upload = async(file, fields, versions = {}) => {
    const data = new FormData();
    data.append('sesskey', M.cfg.sesskey);
    Object.entries(fields).forEach(([name, value]) => data.append(name, value));
    data.append('file', file, file.name);
    Object.entries(versions).forEach(([name, blob]) => {
        if (blob) {
            data.append(name, blob, `${name}`);
        }
    });
    const response = await fetch(`${M.cfg.wwwroot}/theme/brandable/assets.php`, {method: 'POST', body: data});
    const result = await response.json().catch(() => ({error: response.statusText}));
    if (!response.ok || result.error || !result.asset) {
        throw new Error(result.error || result.message || response.statusText);
    }
    return result.asset;
};

/**
 * Adds images to the library, then attaches them to the prompt (drop, paste, file picker).
 *
 * @param {FileList|File[]} files Files.
 */
export const addImages = async(files) => {
    const list = Array.from(files).filter((file) => file.type !== 'font/woff2' && !/\.woff2$/i.test(file.name));
    for (const file of list) {
        const status = region('assetsstatus');
        status.textContent = await getString('assets:preparing', 'theme_brandable', file.name);
        try {
            if (file.size > MAX_FILE) {
                throw new Error(await getString('asset:error:filesize', 'theme_brandable', '10 MB'));
            }
            const versions = await prepareImage(file);
            status.textContent = await getString('assets:sending', 'theme_brandable', file.name);
            const asset = await upload(file, {kind: 'image'}, versions);
            await attach(asset);
        } catch (error) {
            Notification.addNotification({type: 'error',
                message: await getString('assets:uploadfailed', 'theme_brandable', {name: file.name, error: error.message})});
        }
        status.textContent = '';
    }
    if (list.length) {
        await refresh();
    }
};

/**
 * Attaches an image to the next prompt (4 at most) and names it in the prompt.
 *
 * @param {Object} asset Asset.
 */
export const attach = async(asset) => {
    if (attachments.some((item) => item.id === asset.id)) {
        return;
    }
    if (attachments.length >= MAX_ATTACHMENTS) {
        Notification.addNotification({type: 'warning',
            message: await getString('assets:toomanyattachments', 'theme_brandable', MAX_ATTACHMENTS)});
        return;
    }
    attachments.push({id: asset.id, slug: asset.slug, thumbnailurl: asset.thumbnailurl, url: asset.url});
    const prompt = region('prompt');
    if (!prompt.value.includes(asset.slug)) {
        prompt.value = `${prompt.value.trimEnd()}${prompt.value.trim() ? ' ' : ''}« ${asset.slug} » `;
    }
    await renderAttachments();
    prompt.focus();
};

/**
 * Returns the images attached to the next prompt.
 *
 * @returns {Object[]}
 */
export const getAttachments = () => attachments.slice();

/**
 * Empties the attachments (prompt sent).
 */
export const clearAttachments = async() => {
    attachments = [];
    await renderAttachments();
};

/**
 * Renders the attachments above the prompt.
 */
const renderAttachments = async() => {
    const {html, js} = await Templates.renderForPromise('theme_brandable/editor_attachments', {attachments});
    Templates.replaceNodeContents(region('attachments'), html, js);
    region('attachments').hidden = attachments.length === 0;
};

/**
 * Reads the library again and renders the panel when it is open.
 */
export const refresh = async() => {
    try {
        library = await Repository.getAssets();
    } catch (failure) {
        library = null;
    }
    if (!region('assets').hidden) {
        await renderPanel();
    }
};

/**
 * Loads the fonts of the library, for their preview (FontFace, family tbe-font-<id>).
 */
const loadFonts = () => {
    (library?.assets || []).filter((asset) => asset.kind === 'font' && !loadedFonts[asset.id]).forEach((asset) => {
        loadedFonts[asset.id] = true;
        const face = new FontFace(`tbe-font-${asset.id}`, `url("${asset.url}") format("woff2")`,
            {weight: String(asset.weight), style: asset.style});
        face.load().then((loaded) => document.fonts.add(loaded)).catch(() => {
            // The preview falls back to the default font.
        });
    });
};

/**
 * Renders the panel.
 */
const renderPanel = async() => {
    const assets = library?.assets || [];
    const usageLabels = {
        published: await getString('asset:usage:published', 'theme_brandable'),
        session: await getString('asset:usage:session', 'theme_brandable'),
        previous: await getString('asset:usage:previous', 'theme_brandable'),
    };
    const context = {
        loaded: !!library,
        // Images, plus font families (a family counts once).
        count: library?.count ?? assets.length,
        max: library?.max || 200,
        images: filter === 'image',
        fonts: filter === 'font',
        weights: [100, 200, 300, 400, 500, 600, 700, 800, 900].map((value) => ({value, selected: value === 400})),
        assets: assets.filter((asset) => asset.kind === filter).map((asset) => ({
            ...asset,
            isfont: asset.kind === 'font',
            italic: asset.style === 'italic',
            used: asset.usage.length > 0,
            usagelabel: asset.usage.map((place) => usageLabels[place]).join(', '),
            editing: asset.id === editing,
            attached: attachments.some((item) => item.id === asset.id),
            weights: [100, 200, 300, 400, 500, 600, 700, 800, 900].map((value) => ({value, selected: value === asset.weight})),
        })),
    };
    const {html, js} = await Templates.renderForPromise('theme_brandable/editor_assets', context);
    Templates.replaceNodeContents(region('assets'), html, js);
    loadFonts();
};

/**
 * Opens or closes the panel.
 *
 * @param {Boolean} open Whether to open it.
 */
export const togglePanel = async(open) => {
    region('assets').hidden = !open;
    root.querySelector('[data-action="assets"]').setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) {
        // Rendered once, after reading the library again (a second rendering would empty a form being filled).
        await refresh();
    }
};

/**
 * Finds an asset of the library.
 *
 * @param {Number} id Asset id.
 * @returns {Object|undefined}
 */
const findAsset = (id) => (library?.assets || []).find((asset) => asset.id === id);

/**
 * Runs a call that answers the library, then renders it; failures are shown.
 *
 * @param {Function} action Call.
 */
const change = async(action) => {
    try {
        library = await action();
        editing = 0;
    } catch (failure) {
        const message = failure?.error?.message || failure?.message || String(failure);
        Notification.addNotification({type: 'error', message});
    }
    await renderPanel();
};

/**
 * Sends the font form of the panel.
 *
 * @param {HTMLFormElement} form Form.
 */
const addFont = async(form) => {
    const file = form.querySelector('[name="font"]').files[0];
    if (!file) {
        return;
    }
    const licence = form.querySelector('[name="licence"]').files[0] || null;
    const status = region('assetsstatus');
    status.textContent = await getString('assets:sending', 'theme_brandable', file.name);
    try {
        await upload(file, {
            kind: 'font',
            family: form.querySelector('[name="family"]').value,
            weight: form.querySelector('[name="weight"]').value,
            style: form.querySelector('[name="style"]').value,
        }, {licence});
        filter = 'font';
        form.reset();
    } catch (error) {
        Notification.addNotification({type: 'error',
            message: await getString('assets:uploadfailed', 'theme_brandable', {name: file.name, error: error.message})});
    }
    status.textContent = '';
    await refresh();
};

/**
 * Handles the clicks of the panel and of the attachments.
 *
 * @param {MouseEvent} e Event.
 * @returns {Boolean} Whether the click was handled.
 */
const handleClick = (e) => {
    const button = e.target.closest('[data-action]');
    if (!button || !root.contains(button)) {
        return false;
    }
    const id = Number(button.dataset.id || 0);
    switch (button.dataset.action) {
        case 'assets':
            togglePanel(region('assets').hidden);
            return true;
        case 'closeassets':
            togglePanel(false);
            return true;
        case 'assetsfilter':
            filter = button.dataset.kind;
            editing = 0;
            renderPanel();
            return true;
        case 'addimages':
        case 'attachimages':
            region('imagefiles').click();
            return true;
        case 'attachasset':
            attach(findAsset(id)).then(renderPanel).catch(Notification.exception);
            return true;
        case 'detachasset':
            attachments = attachments.filter((item) => item.id !== id);
            renderAttachments().then(() => !region('assets').hidden && renderPanel()).catch(Notification.exception);
            return true;
        case 'editasset':
            editing = editing === id ? 0 : id;
            renderPanel();
            return true;
        case 'deleteasset':
            Notification.saveCancelPromise(
                getString('assets:deletetitle', 'theme_brandable'),
                getString('assets:deleteconfirm', 'theme_brandable', findAsset(id)?.slug || ''),
                getString('delete')
            ).then(() => change(() => Repository.deleteAsset(id))).catch(() => null);
            return true;
        default:
            return false;
    }
};

/**
 * Handles the forms of the panel (edition of an asset, new font).
 *
 * @param {SubmitEvent} e Event.
 */
const handleSubmit = (e) => {
    const form = e.target.closest('[data-region="assetform"], [data-region="fontform"]');
    if (!form) {
        return;
    }
    e.preventDefault();
    if (form.dataset.region === 'fontform') {
        addFont(form);
        return;
    }
    const value = (name) => form.querySelector(`[name="${name}"]`)?.value ?? '';
    const asset = {id: Number(form.dataset.id), slug: value('slug'), alt: value('alt')};
    if (value('family')) {
        Object.assign(asset, {family: value('family'), weight: Number(value('weight')), style: value('style')});
    }
    change(() => Repository.updateAsset(asset));
};

/**
 * Returns the image files of a data transfer (drop, paste).
 *
 * @param {DataTransfer} transfer Data transfer.
 * @returns {File[]}
 */
const imageFiles = (transfer) => Array.from(transfer?.files || []).filter((file) => /^image\//.test(file.type)
    || /\.(svg|ico)$/i.test(file.name));

/**
 * Sets the library up: panel, drop on the chat, paste in the prompt, file picker.
 *
 * @param {HTMLElement} editor Editor root.
 * @returns {Function} Click handler, called by the editor before its own.
 */
export const init = (editor) => {
    root = editor;
    const sidebar = root.querySelector('.tbe-sidebar');
    sidebar.addEventListener('dragover', (e) => {
        if (Array.from(e.dataTransfer?.types || []).includes('Files')) {
            e.preventDefault();
            sidebar.classList.add('tbe-dropping');
        }
    });
    sidebar.addEventListener('dragleave', (e) => {
        if (!sidebar.contains(e.relatedTarget)) {
            sidebar.classList.remove('tbe-dropping');
        }
    });
    sidebar.addEventListener('drop', (e) => {
        sidebar.classList.remove('tbe-dropping');
        const files = imageFiles(e.dataTransfer);
        if (files.length) {
            e.preventDefault();
            addImages(files);
        }
    });
    region('prompt').addEventListener('paste', (e) => {
        const files = imageFiles(e.clipboardData);
        if (files.length) {
            e.preventDefault();
            addImages(files);
        }
    });
    region('imagefiles').addEventListener('change', (e) => {
        addImages(e.target.files);
        e.target.value = '';
    });
    root.addEventListener('submit', handleSubmit);
    return handleClick;
};
