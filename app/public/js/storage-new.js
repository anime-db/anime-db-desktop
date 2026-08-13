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

// Wires storage/new.html.twig's path field to window.animeDb.pickFolder() (issue #165) — the
// button only makes sense for a writable StorageType (issue #164) and only when the page runs
// inside Electron (window.animeDb exposed by native/window/preload.js); otherwise the path
// field stays a plain text input, same as when the page is opened in a plain browser.
(function () {
    const typeSelect = document.getElementById('storage-new-type');
    const pathInput = document.getElementById('storage-new-path');
    const pickButton = document.getElementById('storage-new-pick-folder');

    if (!typeSelect || !pathInput || !pickButton) {
        return;
    }

    const writableTypes = (typeSelect.dataset.writableTypes || '').split(',').filter(Boolean);

    function updatePickButtonVisibility() {
        pickButton.hidden = !window.animeDb || !writableTypes.includes(typeSelect.value);
    }

    typeSelect.addEventListener('change', updatePickButtonVisibility);
    updatePickButtonVisibility();

    pickButton.addEventListener('click', async () => {
        if (!window.animeDb) {
            return;
        }

        const folder = await window.animeDb.pickFolder();
        if (folder) {
            pathInput.value = folder;
        }
    });
})();
