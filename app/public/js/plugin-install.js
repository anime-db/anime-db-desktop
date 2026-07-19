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

// Gates settings/plugins/index.html.twig's install form (issue #251): the submit button starts
// disabled, a ZIP picked in the file input reveals the "third-party source" warning, and only
// clicking its own confirm button re-enables submit. Picking a different file resets the gate,
// so a user cannot swap the archive after confirming without acknowledging the warning again.
(function () {
    const form = document.getElementById('plugin-install-form');
    if (!form) {
        return;
    }

    const fileInput = document.getElementById('plugin-install-file');
    const warning = document.getElementById('plugin-install-warning');
    const confirmButton = document.getElementById('plugin-install-confirm-button');
    const submitButton = document.getElementById('plugin-install-submit-button');

    function resetGate() {
        submitButton.disabled = true;
        warning.hidden = fileInput.files.length === 0;
    }

    fileInput.addEventListener('change', resetGate);

    confirmButton.addEventListener('click', () => {
        submitButton.disabled = false;
    });

    resetGate();
})();
