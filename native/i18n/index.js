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
const { mapOsLocaleToAppLocale } = require('../config');

const TRANSLATIONS_DIR = path.join(__dirname, '..', 'translations');

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
        catalog = JSON.parse(fs.readFileSync(path.join(TRANSLATIONS_DIR, `${locale}.json`), 'utf8'));
    } catch {
        catalog = null;
    }

    catalogCache.set(locale, catalog);

    return catalog;
}

/**
 * Resolves the catalog to use for `locale`: the locale's own file, falling back to the nearest
 * built-in locale via mapOsLocaleToAppLocale() (not straight to "en") — the window locale can
 * come from a translation plugin the native layer has no counterpart for, and for post-Soviet
 * locales the nearest understood language is Russian, not English (issue #177, issue #404).
 *
 * @param {string} locale
 * @returns {Record<string, string>}
 */
function resolveCatalog(locale) {
    return loadCatalog(locale) || loadCatalog(mapOsLocaleToAppLocale(locale)) || loadCatalog('en') || {};
}

/**
 * Translates `key` for `locale`, substituting Symfony-style %name% placeholders with `params`.
 * A key missing from the resolved catalog is returned as-is rather than throwing.
 *
 * @param {string} key
 * @param {string} locale
 * @param {Record<string, string | number>} [params]
 * @returns {string}
 */
function t(key, locale, params = {}) {
    const catalog = resolveCatalog(locale);
    let text = catalog[key] || key;

    for (const [name, value] of Object.entries(params)) {
        text = text.split(`%${name}%`).join(String(value));
    }

    return text;
}

module.exports = { t };
