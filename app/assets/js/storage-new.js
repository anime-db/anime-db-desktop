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
    function mountStorageNew(form) {
        const typeSelect = form.querySelector('#storage-new-type');
        const pathInput = form.querySelector('#storage-new-path');
        const pickButton = form.querySelector('#storage-new-pick-folder');

        if (!typeSelect || !pathInput || !pickButton) {
            return;
        }

        const writableTypes = (typeSelect.dataset.writableTypes || '').split(',').filter(Boolean);

        const pathOptionalTypes = (typeSelect.dataset.pathOptionalTypes || '').split(',').filter(Boolean);

        const pathNotApplicableTypes = (typeSelect.dataset.pathNotApplicableTypes || '').split(',').filter(Boolean);
        const notApplicableHint = form.querySelector('#storage-new-path-not-applicable');
        let savedPath = '';

        function updatePickButtonVisibility() {
            pickButton.hidden = !window.animeDb || !writableTypes.includes(typeSelect.value);
        }

        function updatePathApplicable() {
            const applicable = !pathNotApplicableTypes.includes(typeSelect.value);
            if (!applicable && !pathInput.disabled) {
                // Remember the typed path so switching the type back does not lose it.
                savedPath = pathInput.value;
                pathInput.value = '';
            } else if (applicable && pathInput.disabled) {
                pathInput.value = savedPath;
            }
            pathInput.disabled = !applicable;
            if (notApplicableHint) {
                notApplicableHint.hidden = applicable;
            }
        }

        function updatePathRequired() {
            pathInput.required = !pathOptionalTypes.includes(typeSelect.value);
        }

        // Type shown as text. A type is detected for the path the form was opened with (the prefill) only;
        // the select stays in the DOM (it carries the data-* and is posted), only its wrapper is hidden.
        const typeText = form.querySelector('#storage-new-type-text');
        const typeName = form.querySelector('#storage-new-type-name');
        const typeChoice = form.querySelector('#storage-new-type-choice');
        const typeDetecting = form.querySelector('#storage-new-type-detecting');
        const typeSource = form.querySelector('#storage-new-type-source');
        const prefillPath = pathInput.value.trim();
        // The type known for the prefill path: rendered by the server (UNC) or taken from the endpoint's answer.
        let autoType = typeSource && typeSource.value === 'auto' ? typeSelect.value : null;
        let detectionPending = Boolean(typeDetecting) && !typeDetecting.hidden;

        function setHidden(element, hidden) {
            if (element) {
                element.hidden = hidden;
            }
        }

        function showChoice() {
            setHidden(typeText, true);
            setHidden(typeDetecting, true);
            setHidden(typeChoice, false);
            if (typeSource) {
                typeSource.value = '';
            }
        }

        function showAutoType(type) {
            typeSelect.value = type;
            if (typeName) {
                typeName.textContent = typeSelect.selectedOptions[0] ? typeSelect.selectedOptions[0].textContent : type;
            }
            setHidden(typeText, false);
            setHidden(typeDetecting, true);
            setHidden(typeChoice, true);
            if (typeSource) {
                typeSource.value = 'auto';
            }
            updatePickButtonVisibility();
            updatePathRequired();
            updatePathApplicable();
        }

        // A path other than the prefill is chosen by a person, so the type is chosen by a person too.
        function syncTypeWithPath() {
            if (autoType === null) {
                if (pathInput.value.trim() !== prefillPath && detectionPending) {
                    setHidden(typeDetecting, true);
                    setHidden(typeChoice, false);
                }
                return;
            }
            if (pathInput.value.trim() === prefillPath) {
                showAutoType(autoType);
            } else {
                showChoice();
            }
        }

        function finishDetection(type) {
            if (!detectionPending) {
                return;
            }
            detectionPending = false;
            setHidden(typeDetecting, true);
            if (type && pathInput.value.trim() === prefillPath && typeSelect.querySelector('option[value="' + type + '"]')) {
                autoType = type;
                showAutoType(type);
            } else {
                setHidden(typeChoice, false);
            }
        }

        // Name hint (the endpoint reads a drive label, so it answers only a request with the shell header).
        const nameInput = form.querySelector('#storage-new-name');
        const hintUrl = '/storage/new/name-hint';
        // The hint last put into the name field (rendered by the server on a prefilled form);
        // a name that differs from it was typed by a person and is never touched.
        let lastHint = nameInput ? nameInput.dataset.nameHint || null : null;
        let hintRequestId = 0;

        function applyHint(name) {
            if (nameInput.value !== '' && nameInput.value !== lastHint) {
                return;
            }
            nameInput.value = name || '';
            lastHint = name || null;
        }

        // forPrefill: this request is the one that also asks for the type of the form's initial path.
        async function requestNameHint(forPrefill = false) {
            if (!nameInput || typeof fetch !== 'function') {
                if (forPrefill) {
                    finishDetection(null);
                }
                return;
            }

            // Only the answer to the latest request is applied: a slow reply to an earlier path must not win.
            hintRequestId += 1;
            const requestId = hintRequestId;
            const path = pathInput.value.trim();
            let name = null;
            let type = null;
            if (path !== '') {
                try {
                    const response = await fetch(hintUrl + '?path=' + encodeURIComponent(path), {
                        headers: { 'X-AnimeDB-Shell': '1' },
                    });
                    if (!response.ok) {
                        if (forPrefill) {
                            finishDetection(null);
                        }
                        return;
                    }
                    const data = await response.json();
                    name = data.name || null;
                    type = data.type || null;
                } catch {
                    if (forPrefill) {
                        finishDetection(null);
                    }
                    return;
                }
            }
            // The type belongs to the prefill path, not to whichever request is the latest.
            if (forPrefill) {
                finishDetection(type);
            }
            if (requestId === hintRequestId) {
                applyHint(name);
            }
        }

        pathInput.addEventListener('change', () => {
            syncTypeWithPath();
            requestNameHint();
        });
        pathInput.addEventListener('input', syncTypeWithPath);

        // A prefilled path with a drive letter shows "detecting" and asks the guarded endpoint for the type
        // (and the name of a drive root, which the server cannot look up on an unverified request).
        if (detectionPending) {
            requestNameHint(true);
        } else if (nameInput && pathInput.value.trim() !== '' && nameInput.value === '') {
            requestNameHint();
        }

        typeSelect.addEventListener('change', updatePickButtonVisibility);
        typeSelect.addEventListener('change', updatePathRequired);
        typeSelect.addEventListener('change', updatePathApplicable);
        updatePickButtonVisibility();
        updatePathRequired();
        updatePathApplicable();

        pickButton.addEventListener('click', async () => {
            if (!window.animeDb) {
                return;
            }

            const folder = await window.animeDb.pickFolder();
            if (folder) {
                pathInput.value = folder;
                // Assigning the value does not fire `change`, so the type and the hint are updated explicitly.
                syncTypeWithPath();
                requestNameHint();
            }
        });
    }

    window.Controller.registerControl('storage-new', mountStorageNew);
})();
