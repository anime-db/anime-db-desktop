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

// Jira-style Labels field: chips typed/removed in an <input>, comma-separated, with
// autocomplete over the existing label catalogue (fetched once and cached, since a personal
// collection's label set is small enough that server-side search would be overkill). A typed
// name with no matching suggestion is still accepted as a chip — AnimeLabelController creates
// it on save. Registered as the "labels-widget" control (issue #734) — this is the control
// window.Controller's own registry pattern was written to replace: a bespoke
// document.querySelectorAll('[data-labels-widget]').forEach(initWidget) that only ever ran once,
// on the initial page load.
(function () {
    function mountLabelsWidget(widget) {
        const updateUrl = widget.dataset.updateUrl;
        const searchUrl = widget.dataset.searchUrl;
        const csrfToken = widget.dataset.csrfToken;

        const view = widget.querySelector('[data-labels-view]');
        const editor = widget.querySelector('[data-labels-editor]');
        const chipsList = widget.querySelector('[data-labels-chips]');
        const input = widget.querySelector('[data-labels-input]');
        const suggestions = widget.querySelector('[data-labels-suggestions]');
        const errorMessage = widget.querySelector('[data-labels-error]');
        const editButton = widget.querySelector('[data-labels-edit]');
        const saveButton = widget.querySelector('[data-labels-save]');
        const cancelButton = widget.querySelector('[data-labels-cancel]');

        let currentLabels = JSON.parse(widget.dataset.labels || '[]');
        let chips = [];
        let allLabelsPromise = null;

        function fetchAllLabels() {
            if (!allLabelsPromise) {
                allLabelsPromise = fetch(searchUrl)
                    .then((response) => {
                        if (!response.ok) {
                            throw new Error(`Labels request failed with status ${response.status}`);
                        }

                        return response.json();
                    })
                    .then((data) => (Array.isArray(data.labels) ? data.labels : []))
                    .catch(() => []);
            }

            return allLabelsPromise;
        }

        function renderView() {
            view.replaceChildren();

            if (currentLabels.length === 0) {
                const empty = document.createElement('li');
                empty.className = 'anime-detail__labels-empty';
                empty.textContent = '—';
                view.appendChild(empty);

                return;
            }

            currentLabels.forEach((label) => {
                const item = document.createElement('li');
                const link = document.createElement('a');
                link.className = 'anime-detail__label-link';
                link.href = `/?labels=${encodeURIComponent(label.id)}`;
                link.textContent = label.name;
                item.appendChild(link);
                view.appendChild(item);
            });
        }

        function renderChips() {
            chipsList.replaceChildren();

            chips.forEach((name, index) => {
                const chip = document.createElement('li');
                chip.className = 'anime-detail__labels-chip';

                const text = document.createElement('span');
                text.textContent = name;
                chip.appendChild(text);

                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'anime-detail__labels-chip-remove';
                remove.textContent = '×';
                remove.addEventListener('click', () => {
                    chips.splice(index, 1);
                    renderChips();
                });
                chip.appendChild(remove);

                chipsList.appendChild(chip);
            });
        }

        function hideSuggestions() {
            suggestions.hidden = true;
            suggestions.replaceChildren();
        }

        function addChip(name) {
            const trimmed = name.trim();
            if (trimmed === '') {
                return;
            }

            const alreadyPresent = chips.some((chip) => chip.toLowerCase() === trimmed.toLowerCase());
            if (!alreadyPresent) {
                chips.push(trimmed);
                renderChips();
            }

            input.value = '';
            hideSuggestions();
        }

        function showSuggestions(query) {
            fetchAllLabels().then((labels) => {
                const lowerQuery = query.trim().toLowerCase();
                const matches = labels.filter((label) => {
                    const alreadyChipped = chips.some((chip) => chip.toLowerCase() === label.name.toLowerCase());

                    return !alreadyChipped && (lowerQuery === '' || label.name.toLowerCase().includes(lowerQuery));
                });

                suggestions.replaceChildren();

                if (lowerQuery === '' || matches.length === 0) {
                    hideSuggestions();

                    return;
                }

                matches.slice(0, 8).forEach((label) => {
                    const item = document.createElement('li');
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.textContent = label.name;
                    button.addEventListener('click', () => addChip(label.name));
                    item.appendChild(button);
                    suggestions.appendChild(item);
                });

                suggestions.hidden = false;
            });
        }

        function openEditor() {
            chips = currentLabels.map((label) => label.name);
            errorMessage.hidden = true;
            renderChips();
            hideSuggestions();
            input.value = '';
            view.hidden = true;
            editor.hidden = false;
            input.focus();
        }

        function closeEditor() {
            editor.hidden = true;
            view.hidden = false;
            hideSuggestions();
        }

        function saveLabels() {
            addChip(input.value);

            errorMessage.hidden = true;
            saveButton.disabled = true;

            fetch(updateUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ token: csrfToken, names: chips }),
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error(`Labels update failed with status ${response.status}`);
                    }

                    return response.json();
                })
                .then((data) => {
                    currentLabels = Array.isArray(data.labels) ? data.labels : [];
                    renderView();
                    closeEditor();
                })
                .catch(() => {
                    errorMessage.hidden = false;
                })
                .finally(() => {
                    saveButton.disabled = false;
                });
        }

        editButton.addEventListener('click', openEditor);
        cancelButton.addEventListener('click', closeEditor);
        saveButton.addEventListener('click', saveLabels);

        input.addEventListener('input', () => showSuggestions(input.value));

        input.addEventListener('keydown', (event) => {
            if (event.key === ',' || event.key === 'Enter') {
                event.preventDefault();
                addChip(input.value);

                return;
            }

            if (event.key === 'Backspace' && input.value === '' && chips.length > 0) {
                chips.pop();
                renderChips();
            }
        });

        renderView();
    }

    window.Controller.registerControl('labels-widget', mountLabelsWidget);
})();

// The "Files" block's "open storage folder" button (issue #734). Mounted through the control
// registry, so it also works for any fragment that is swapped in later.
(function () {
    function mountOpenFolderButton(button) {
        button.addEventListener('click', () => {
            if (button.disabled || !window.animeDb) {
                return;
            }

            window.animeDb.openPath(button.dataset.openFolderPath);
        });
    }

    window.Controller.registerControl('open-folder-button', mountOpenFolderButton);
})();

// The "Files" block's manual link (issue #997). The picker result goes to the server, which lifts
// it to a top-level item of a storage and re-renders the whole block with the outcome; a storage
// that moved answers 409 with {relocate: {...}} and the same request is repeated once the user
// agrees to update its path.
(function () {
    function mountFilesLink(root) {
        const menu = root.querySelector('.anime-detail__files-menu');
        const actions = root.querySelector('[data-files-actions]');

        if (!window.animeDb) {
            // Pickers exist only inside Electron; in a plain browser there is nothing to offer.
            if (actions) {
                actions.hidden = true;
            }

            return;
        }

        async function post(url, fields) {
            const body = new FormData();
            body.append('_token', root.dataset.token);
            Object.entries(fields).forEach(([name, value]) => body.append(name, value));

            return fetch(url, { method: 'POST', body, headers: { 'HX-Request': 'true' } });
        }

        function show(html) {
            window.htmx.swap(root, html, { swapStyle: 'outerHTML' });
        }

        // The block is replaced only by a successful answer; anything else (expired token, server
        // error, lost connection) leaves it in place so the action can be repeated.
        async function showResponse(response) {
            if (!response.ok) {
                window.alert(root.dataset.requestFailed);

                return;
            }

            show(await response.text());
        }

        async function link(path) {
            try {
                let response = await post(root.dataset.linkUrl, { path });

                if (response.status === 409) {
                    const { relocate } = await response.json();
                    const message = root.dataset.relocateConfirm.replace('%name%', relocate.name);
                    if (!window.confirm(message)) {
                        return;
                    }

                    response = await post(root.dataset.linkUrl, { path, relocate_storage_id: relocate.storage_id });
                }

                await showResponse(response);
            } catch {
                window.alert(root.dataset.requestFailed);
            }
        }

        root.querySelectorAll('[data-files-pick]').forEach((button) => {
            button.addEventListener('click', async () => {
                if (menu) {
                    menu.open = false;
                }

                const path = button.dataset.filesPick === 'video'
                    ? await window.animeDb.pickFile([{
                        name: root.dataset.videoFilterName,
                        extensions: root.dataset.videoExtensions.split(','),
                    }])
                    : await window.animeDb.pickFolder();

                if (path) {
                    await link(path);
                }
            });
        });

        const unlinkButton = root.querySelector('[data-files-unlink]');
        if (unlinkButton) {
            unlinkButton.addEventListener('click', async () => {
                if (!window.confirm(root.dataset.unlinkConfirm)) {
                    return;
                }

                try {
                    await showResponse(await post(root.dataset.unlinkUrl, {}));
                } catch {
                    window.alert(root.dataset.requestFailed);
                }
            });
        }
    }

    window.Controller.registerControl('files-link', mountFilesLink);
})();

// The "← Catalog" link (issue #719). The catalog itself writes its filters/sort/search into its
// own URL and reads them back from that same URL on load (issue #713), so a real back navigation
// through browser history reopens it exactly as it was left - no state needs to be carried here.
// The previous history entry is only guaranteed to be the catalog when this page was reached by
// navigating from it - an anime page can just as well be reached from settings or the "add anime"
// form, where a plain back navigation would land somewhere other than the catalog. document.referrer
// is the only signal available for that: the link is intercepted only when the referrer is
// same-origin and its path is the catalog root ('/'), and window.history.length > 1 confirms a
// previous entry actually exists to go back to. Any other referrer (including an empty one, e.g. a
// deep link) falls through to the plain href to the catalog root.
(function () {
    function mountCatalogBackLink(link) {
        if (window.history.length <= 1) {
            return;
        }

        let referrerUrl;
        try {
            referrerUrl = new URL(document.referrer);
        } catch {
            return;
        }

        if (referrerUrl.origin !== window.location.origin || referrerUrl.pathname !== '/') {
            return;
        }

        link.addEventListener('click', (event) => {
            event.preventDefault();
            window.history.back();
        });
    }

    window.Controller.registerControl('catalog-back-link', mountCatalogBackLink);
})();
