/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

'use strict';

const fs   = require('fs');
const path = require('path');
const paths = require('../paths');
const { mapOsLocaleToAppLocale } = require('../config');

/** @type {Map<string, Record<string, string> | null>} */
const catalogCache = new Map();

/**
 * Reads and parses native/translations/<locale>.json, caching the result (including a cached
 * `null` for a locale that has no catalog file, e.g. a plugin-supplied window locale such as
 * "kk" that the native layer does not ship its own strings for).
 *
 * @param {string} locale
 * @returns {Record<string, string> | null}
 */
function loadCatalog(locale) {
    if (catalogCache.has(locale)) {
        return catalogCache.get(locale);
    }

    let catalog = null;
    try {
        catalog = JSON.parse(fs.readFileSync(path.join(paths.getNativeTranslationsDir(), `${locale}.json`), 'utf8'));
    } catch {
        catalog = null;
    }

    catalogCache.set(locale, catalog);

    return catalog;
}

/** @type {Map<string, Record<string, string> | null>} */
const overlayCache = new Map();

/**
 * Reads and parses <nativeTranslationsOverlayDir>/<locale>.json — the per-key overlay written by
 * the PHP core (a separate task builds it there). Non-string and empty-string values are dropped:
 * the overlay is data written by another process, every downstream consumer of a resolved catalog
 * must be able to treat its values as non-empty strings without checking again, and an empty
 * value must not shadow the fallback chain with a blank label.
 *
 * Cached exactly like loadCatalog(), including a cached `null` for a missing, unreadable or
 * invalid file — and deliberately never invalidated for the lifetime of the process. An overlay
 * file that appears or changes while the app is already running is picked up on the next launch,
 * not live; this mirrors the tray menu, which is also built once and does not follow a later
 * locale change. Treat this as the intended cache semantics, not an oversight.
 *
 * @param {string} locale
 * @returns {Record<string, string> | null}
 */
function loadOverlay(locale) {
    if (overlayCache.has(locale)) {
        return overlayCache.get(locale);
    }

    const overlayPath = path.join(paths.getNativeTranslationsOverlayDir(), `${locale}.json`);

    let overlay = null;
    try {
        const raw = JSON.parse(fs.readFileSync(overlayPath, 'utf8'));
        overlay = Object.fromEntries(
            Object.entries(raw).filter(([, value]) => typeof value === 'string' && value !== ''),
        );
    } catch {
        overlay = null;
    }

    overlayCache.set(locale, overlay);

    return overlay;
}

/**
 * Resolves the catalog to use for `locale` as a per-key merge, each layer overriding the
 * previous one only on the keys it actually declares, in this order:
 *   1. built-in "en";
 *   2. built-in nearest locale, via mapOsLocaleToAppLocale() (not straight to "en" — the window
 *      locale can come from a translation plugin the native layer has no counterpart for, and for
 *      post-Soviet locales the nearest understood language is Russian, not English — issue #177,
 *      issue #404);
 *   3. the requested locale's overlay;
 *   4. the requested locale's own built-in catalog.
 * The core wins over the overlay only where the core itself ships the requested locale; a locale
 * missing from the built-in catalogs is served entirely from the overlay, with any gaps closed by
 * the fallback chain instead of surfacing raw keys (issue #646).
 *
 * A non-string locale falls straight through to "en" instead of reaching
 * mapOsLocaleToAppLocale(), which would throw on `undefined.split()`. t() is used by the startup
 * failure dialogs and the uncaughtException handler (native/lifecycle/index.js) — a throw from
 * the translation layer there would replace the error message with no message at all, which is
 * exactly when the user needs one.
 *
 * @param {string} locale
 * @returns {Record<string, string>}
 */
function resolveCatalog(locale) {
    if (typeof locale !== 'string' || locale === '') {
        return loadCatalog('en') || {};
    }

    return {
        ...(loadCatalog('en') || {}),
        ...(loadCatalog(mapOsLocaleToAppLocale(locale)) || {}),
        ...(loadOverlay(locale) || {}),
        ...(loadCatalog(locale) || {}),
    };
}

/**
 * Translates `key` for `locale`, substituting Symfony-style %name% placeholders with `params`.
 * Total: a key missing from the resolved catalog, or resolving to a non-string value, is returned
 * as the key itself rather than throwing or leaking a non-string value to the caller.
 *
 * @param {string} key
 * @param {string} locale
 * @param {Record<string, string | number>} [params]
 * @returns {string}
 */
function t(key, locale, params = {}) {
    const catalog = resolveCatalog(locale);
    let text = catalog[key];

    for (const [name, value] of Object.entries(params)) {
        if (typeof text !== 'string') {
            break;
        }
        text = text.split(`%${name}%`).join(String(value));
    }

    return typeof text === 'string' ? text : key;
}

// Writing direction is a property of the language, not of a translation plugin (issue #450): a
// plugin declares locales, not code, so it has no channel to ship a "dir" value, and the set of
// RTL languages changes close to never. Kept as its own static table here rather than a field on
// the plugin manifest — mirrors App\Service\LocaleDirection on the PHP side, which base.html.twig
// uses for the same purpose.
const RTL_LANGUAGES = ['ar', 'he', 'fa', 'ur'];

/**
 * Resolves the writing direction for `locale` from its BCP 47 primary language subtag (e.g.
 * "ar-EG" -> "ar"). A locale with no entry in the table — including one supplied by a
 * translation plugin, or a non-string/empty value — is treated as "ltr".
 *
 * @param {string} locale
 * @returns {'ltr' | 'rtl'}
 */
function textDirection(locale) {
    if (typeof locale !== 'string' || locale === '') {
        return 'ltr';
    }

    const language = locale.split(/[-_]/)[0].toLowerCase();

    return RTL_LANGUAGES.includes(language) ? 'rtl' : 'ltr';
}

module.exports = { t, textDirection };
