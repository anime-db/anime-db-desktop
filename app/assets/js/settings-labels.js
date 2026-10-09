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

// settings/label/index.html.twig's rename row (issue #823): without JS, the row is just the
// plain working form it always was (name input + Save button). This control is a progressive
// enhancement on top of that same form — it never replaces the POST settings_labels_rename
// submission, it only decides when the input is shown. Read mode (a plain clickable name) is the
// default only once this control actually mounts; until then the `hidden` attribute set on
// [data-settings-label-name-button] in the template keeps the original form visible.
(function () {
    function mountSettingsLabelRename(form) {
        const nameButton = form.querySelector('[data-settings-label-name-button]');
        const inputGroup = form.querySelector('[data-settings-label-input-group]');
        const input = form.querySelector('[data-settings-label-name-input]');
        const errorBox = form.querySelector('[data-settings-label-name-error]');
        if (!nameButton || !inputGroup || !input || !errorBox) {
            return;
        }

        const originalValue = input.value;
        // Set by the Escape handler so the focusout triggered by moving focus to the name
        // button is not read as a save — Escape must cancel even though losing focus otherwise saves.
        let cancelled = false;

        function showReadMode() {
            inputGroup.hidden = true;
            nameButton.hidden = false;
            errorBox.hidden = true;
        }

        function showEditMode() {
            nameButton.hidden = true;
            inputGroup.hidden = false;
            input.focus();
            input.select();
        }

        function trySave() {
            if (input.value.trim() === '') {
                errorBox.hidden = false;

                return;
            }

            errorBox.hidden = true;
            if (input.value === originalValue) {
                showReadMode();

                return;
            }

            form.requestSubmit();
        }

        showReadMode();

        nameButton.addEventListener('click', showEditMode);

        input.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                cancelled = true;
                input.value = originalValue;
                errorBox.hidden = true;
                showReadMode();
                nameButton.focus();
            } else if (event.key === 'Enter') {
                event.preventDefault();
                trySave();
            }
        });

        input.addEventListener('focusout', (event) => {
            if (cancelled) {
                cancelled = false;

                return;
            }
            // Losing focus to another element inside this same form (e.g. a mousedown on the
            // Save button) is left to that element's own click/submit handling, so blur-triggered
            // requestSubmit() never races a native form submission into a duplicate POST.
            if (form.contains(event.relatedTarget)) {
                return;
            }
            trySave();
        });
    }

    window.Controller.registerControl('settings-label-rename', mountSettingsLabelRename);
})();
