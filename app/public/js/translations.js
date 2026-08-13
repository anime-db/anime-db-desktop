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

    async function trans(key) {
        const messages = await getCatalogue();

        return Object.prototype.hasOwnProperty.call(messages, key) ? messages[key] : key;
    }

    window.AppTranslations = { getCatalogue, trans };
})();
