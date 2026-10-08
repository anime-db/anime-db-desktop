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

// Toggles the `required` attribute of storage/edit.html.twig's path field when the type changes:
// a type whose path is optional (data-path-optional-types) may be saved with an empty path.
(function () {
    function mountStorageEdit(form) {
        const typeSelect = form.querySelector('#storage-edit-type');
        const pathInput = form.querySelector('#storage-edit-path');

        if (!typeSelect || !pathInput) {
            return;
        }

        const pathOptionalTypes = (typeSelect.dataset.pathOptionalTypes || '').split(',').filter(Boolean);

        const pathNotApplicableTypes = (typeSelect.dataset.pathNotApplicableTypes || '').split(',').filter(Boolean);
        const notApplicableHint = form.querySelector('#storage-edit-path-not-applicable');

        function updatePathApplicable() {
            const applicable = !pathNotApplicableTypes.includes(typeSelect.value);
            if (!applicable) {
                pathInput.value = '';
            }
            pathInput.disabled = !applicable;
            if (notApplicableHint) {
                notApplicableHint.hidden = applicable;
            }
        }

        function updatePathRequired() {
            pathInput.required = !pathOptionalTypes.includes(typeSelect.value);
        }

        typeSelect.addEventListener('change', updatePathRequired);
        typeSelect.addEventListener('change', updatePathApplicable);
        updatePathRequired();
        updatePathApplicable();
    }

    window.Controller.registerControl('storage-edit', mountStorageEdit);
})();
