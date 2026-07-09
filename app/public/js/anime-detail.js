/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

'use strict';

// Jira-style Labels field: chips typed/removed in an <input>, comma-separated, with
// autocomplete over the existing label catalogue (fetched once and cached, since a personal
// collection's label set is small enough that server-side search would be overkill). A typed
// name with no matching suggestion is still accepted as a chip — AnimeLabelController creates
// it on save.
(function () {
    function initWidget(widget) {
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

    document.querySelectorAll('[data-labels-widget]').forEach(initWidget);
})();

(function () {
    document.querySelectorAll('[data-open-folder-path]').forEach((button) => {
        button.addEventListener('click', () => {
            if (button.disabled || !window.animeDb) {
                return;
            }

            window.animeDb.openPath(button.dataset.openFolderPath);
        });
    });
})();
