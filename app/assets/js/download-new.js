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

// "Add download" page (issue #855): drag-and-drop onto the file input, plus a type-ahead search
// over the user's own catalog (GET /anime?name=..., the same endpoint the catalog list page
// itself uses — see anime-list-query.js) that fills the form's hidden "anime" id field.
(function () {
    const SEARCH_URL = '/anime';
    const SEARCH_DEBOUNCE_MS = 250;
    const SEARCH_RESULT_LIMIT = 8;

    function mountAnimeSearch(root) {
        const searchInput = root.querySelector('#download-new-anime-search');
        const idInput = root.querySelector('#download-new-anime-id');
        const results = root.querySelector('#download-new-anime-results');

        searchInput.setAttribute('role', 'combobox');
        searchInput.setAttribute('aria-autocomplete', 'list');
        searchInput.setAttribute('aria-controls', results.id);
        searchInput.setAttribute('aria-expanded', 'false');
        results.setAttribute('role', 'listbox');

        let debounceTimer = null;
        // Bumped on every search() call, including the query === '' short-circuit, so a response
        // to an older query can never overwrite the list a newer one already rendered — fetch()
        // calls settle in whatever order the network returns them, not the order they were sent.
        let latestRequestId = 0;

        let activeIndex = -1;

        function setActive(index) {
            const options = results.querySelectorAll('[role="option"]');
            activeIndex = index;
            options.forEach((option, i) => {
                const active = i === index;
                option.classList.toggle('active', active);
                option.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            if (index >= 0 && options[index]) {
                searchInput.setAttribute('aria-activedescendant', options[index].id);
            } else {
                searchInput.removeAttribute('aria-activedescendant');
            }
        }

        function hideResults() {
            results.hidden = true;
            results.innerHTML = '';
            activeIndex = -1;
            searchInput.setAttribute('aria-expanded', 'false');
            searchInput.removeAttribute('aria-activedescendant');
        }

        function selectAnime(id, title) {
            idInput.value = String(id);
            searchInput.value = title;
            hideResults();
        }

        function renderResults(items) {
            results.innerHTML = '';
            activeIndex = -1;
            searchInput.removeAttribute('aria-activedescendant');
            items.forEach((item, index) => {
                const entry = document.createElement('li');
                entry.id = `${results.id}-option-${index}`;
                entry.setAttribute('role', 'option');
                entry.setAttribute('aria-selected', 'false');
                entry.className = 'list-group-item list-group-item-action';
                entry.textContent = item.title;
                // mousedown (not click) with preventDefault: a click fires only after mouseup, and
                // the field's blur handler already hides this list by then on a slow press. Firing
                // on mousedown and blocking its default focus-shifting behavior selects the entry
                // before blur ever runs, regardless of how long the button is held.
                entry.addEventListener('mousedown', (event) => {
                    event.preventDefault();
                    selectAnime(item.id, item.title);
                });
                results.appendChild(entry);
            });
            results.hidden = items.length === 0;
            searchInput.setAttribute('aria-expanded', items.length === 0 ? 'false' : 'true');
        }

        async function search(query) {
            const requestId = ++latestRequestId;

            if (query === '') {
                hideResults();

                return;
            }

            const url = `${SEARCH_URL}?name=${encodeURIComponent(query)}&limit=${SEARCH_RESULT_LIMIT}`;
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            if (requestId !== latestRequestId || !response.ok) {
                return;
            }

            const data = await response.json();
            if (requestId !== latestRequestId) {
                return;
            }
            renderResults(data.items ?? []);
        }

        searchInput.addEventListener('input', () => {
            idInput.value = '';

            if (debounceTimer !== null) {
                clearTimeout(debounceTimer);
            }
            debounceTimer = setTimeout(() => search(searchInput.value.trim()), SEARCH_DEBOUNCE_MS);
        });

        searchInput.addEventListener('keydown', (event) => {
            const options = results.querySelectorAll('[role="option"]');
            if (results.hidden || options.length === 0) {
                return;
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                setActive((activeIndex + 1) % options.length);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                setActive(activeIndex <= 0 ? options.length - 1 : activeIndex - 1);
            } else if (event.key === 'Enter' && activeIndex >= 0) {
                // The form must not be submitted by the Enter that picks an entry.
                event.preventDefault();
                options[activeIndex].dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));
            } else if (event.key === 'Escape') {
                event.preventDefault();
                hideResults();
            }
        });

        searchInput.addEventListener('blur', () => {
            setTimeout(hideResults, 100);
        });
    }

    function mountFileDropZone(root) {
        const dropZone = root.querySelector('#download-new-drop-zone');
        const fileInput = root.querySelector('#download-new-file');

        // The "link to entry" page reuses this control for the catalog search only.
        if (dropZone === null || fileInput === null) {
            return;
        }

        ['dragenter', 'dragover'].forEach((eventName) => {
            dropZone.addEventListener(eventName, (event) => {
                event.preventDefault();
                dropZone.classList.add('download-new__drop-zone--active');
            });
        });

        ['dragleave', 'drop'].forEach((eventName) => {
            dropZone.addEventListener(eventName, (event) => {
                event.preventDefault();
                dropZone.classList.remove('download-new__drop-zone--active');
            });
        });

        dropZone.addEventListener('drop', (event) => {
            const files = event.dataTransfer ? event.dataTransfer.files : null;
            if (files && files.length > 0) {
                fileInput.files = files;
            }
        });
    }

    function mountDownloadNew(root) {
        mountAnimeSearch(root);
        mountFileDropZone(root);
    }

    window.Controller.registerControl('download-new', mountDownloadNew);
})();
