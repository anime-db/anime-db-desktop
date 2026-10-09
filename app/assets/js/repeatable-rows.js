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

// Add/remove rows of a repeatable list in a plain form (issue #914, anime/edit.html.twig): the
// root holds a [data-rows-list] with the current rows, a <template data-rows-template> whose
// markup uses the __INDEX__ placeholder in field names, and a [data-rows-add] button. Every row
// carries a [data-rows-remove] button. Indexes only need to be unique within the posted form, so
// a counter that never reuses a number is enough; the server reads rows in submitted order.
(function () {
    function mountRepeatableRows(root) {
        const list = root.querySelector('[data-rows-list]');
        const template = root.querySelector('template[data-rows-template]');
        const addButton = root.querySelector('[data-rows-add]');

        if (!list || !template || !addButton) {
            return undefined;
        }

        let nextIndex = list.children.length;

        function onAdd() {
            const html = template.innerHTML.replace(/__INDEX__/g, String(nextIndex));
            nextIndex += 1;
            list.insertAdjacentHTML('beforeend', html);
            const field = list.lastElementChild && list.lastElementChild.querySelector('input, textarea, select');
            if (field) {
                field.focus();
            }
        }

        function onRemove(event) {
            const button = event.target.closest('[data-rows-remove]');
            const row = button && button.closest('[data-rows-item]');
            if (row && list.contains(row)) {
                const neighbour = row.nextElementSibling || row.previousElementSibling;
                row.remove();
                // The focused remove button went away with its row: continue in the neighbouring
                // row's field, or on "Add" once the list is empty.
                const field = neighbour && neighbour.querySelector('input, textarea, select');
                (field || addButton).focus();
            }
        }

        addButton.addEventListener('click', onAdd);
        list.addEventListener('click', onRemove);

        return function unmountRepeatableRows() {
            addButton.removeEventListener('click', onAdd);
            list.removeEventListener('click', onRemove);
        };
    }

    window.Controller.registerControl('repeatable-rows', mountRepeatableRows);
})();
