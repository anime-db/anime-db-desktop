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

// Fetches the messages catalogue from GET /translations/{locale}.json (issue #87) instead of
// keeping a JS copy of the dictionary — that duplication already caused a ru/en drift once
// (anime-list.js, issue #82 context). The locale comes from <html lang>, which the server sets
// from the same Accept-Language negotiation as page requests (LocaleSubscriber, issue #84), so
// the JS never re-implements that negotiation itself.
(function () {
    let cataloguePromise = null;

    function fetchCatalogue() {
        const locale = document.documentElement.lang;

        return fetch(`/translations/${locale}.json`).then((response) => {
            if (!response.ok) {
                throw new Error(`Translations request failed with status ${response.status}`);
            }

            return response.json();
        });
    }

    function getCatalogue() {
        if (!cataloguePromise) {
            cataloguePromise = fetchCatalogue().catch((error) => {
                cataloguePromise = null;
                throw error;
            });
        }

        return cataloguePromise;
    }

    // FIRST STRONG ISOLATE / POP DIRECTIONAL ISOLATE: every substituted value can originate from
    // arbitrary user- or filesystem-sourced text (anime titles, storage paths, backup archive
    // paths). Rendered as plain text (.textContent, not HTML — a <bdi> element is not available
    // everywhere a caller wants this), an unisolated value inside an RTL message can reorder
    // adjacent characters, most visibly brackets and colons (issue #450). Wrapping every value
    // here rather than per-caller keeps that protection from depending on each call site
    // remembering to opt in.
    const BIDI_ISOLATE_START = '⁨';
    const BIDI_ISOLATE_END   = '⁩';

    // `params` mirrors the Symfony-style %name% placeholders used server-side (see
    // .claude-docs/conventions.md §Локализация) — e.g. resolveKey(messages, 'x', { count: 3 })
    // replaces every "%count%" in the resolved string with "3".
    function resolveKey(messages, key, params) {
        const text = Object.prototype.hasOwnProperty.call(messages, key) ? messages[key] : key;
        if (!params) {
            return text;
        }

        return Object.keys(params).reduce(
            (result, paramName) => result.split(`%${paramName}%`).join(
                `${BIDI_ISOLATE_START}${String(params[paramName])}${BIDI_ISOLATE_END}`,
            ),
            text,
        );
    }

    // Never rejects: a catalogue fetch failure (missing locale, network error) falls back to the
    // key itself, the same fallback already used for a key missing from an otherwise loaded
    // catalogue, so a translation lookup never blocks unrelated rendering (issue #516 follow-up).
    async function trans(key, params) {
        let messages;
        try {
            messages = await getCatalogue();
        } catch {
            return key;
        }

        return resolveKey(messages, key, params);
    }

    window.AppTranslations = { getCatalogue, trans, resolveKey };
})();
