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

// Gates settings/plugins/index.html.twig's install form (issue #251): the submit button starts
// disabled, a ZIP picked in the file input reveals the "third-party source" warning, and only
// clicking its own confirm button re-enables submit. Picking a different file resets the gate,
// so a user cannot swap the archive after confirming without acknowledging the warning again.
//
// The file is also validated client-side (selected + .zip extension) before the warning gate is
// shown at all: the server's own no_file check (PluginController::install()) is only a fallback
// for a POST without a valid file and cannot tell "nothing picked" apart from an upload that was
// rejected for exceeding upload_max_filesize, so it is not a reliable source of a precise message.
(function () {
    const form = document.getElementById('plugin-install-form');
    if (!form) {
        return;
    }

    const fileInput = document.getElementById('plugin-install-file');
    const clientError = document.getElementById('plugin-install-client-error');
    const warning = document.getElementById('plugin-install-warning');
    const confirmButton = document.getElementById('plugin-install-confirm-button');
    const submitButton = document.getElementById('plugin-install-submit-button');

    function isZipFile(file) {
        return /\.zip$/i.test(file.name);
    }

    function hideGate() {
        submitButton.disabled = true;
        clientError.hidden = true;
        warning.hidden = true;
    }

    async function validateFile() {
        hideGate();

        const file = fileInput.files[0] ?? null;
        if (!file) {
            clientError.hidden = false;
            clientError.textContent = await window.AppTranslations.trans('settings_plugins.install_error_no_file');

            return;
        }

        if (!isZipFile(file)) {
            clientError.hidden = false;
            clientError.textContent = await window.AppTranslations.trans('settings_plugins.install_error_not_zip');

            return;
        }

        warning.hidden = false;
    }

    fileInput.addEventListener('change', validateFile);

    confirmButton.addEventListener('click', () => {
        submitButton.disabled = false;
    });

    hideGate();
})();
