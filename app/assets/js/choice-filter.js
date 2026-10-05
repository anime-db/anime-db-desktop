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

// Client-side name filter for a long list of checkboxes (issue #914, anime/edit.html.twig): the
// root holds an <input data-filter-input> and [data-filter-item] rows carrying the searchable
// text in data-filter-text. Rows that do not match get the hidden attribute; their checkboxes
// stay in the form, so a filtered-out selected row is still submitted.
(function () {
    function mountChoiceFilter(root) {
        const input = root.querySelector('[data-filter-input]');
        if (!input) {
            return undefined;
        }

        function onInput() {
            const needle = input.value.trim().toLowerCase();
            root.querySelectorAll('[data-filter-item]').forEach((item) => {
                const text = (item.getAttribute('data-filter-text') || '').toLowerCase();
                item.hidden = needle !== '' && !text.includes(needle);
            });
        }

        input.addEventListener('input', onInput);

        return function unmountChoiceFilter() {
            input.removeEventListener('input', onInput);
        };
    }

    window.Controller.registerControl('choice-filter', mountChoiceFilter);
})();
