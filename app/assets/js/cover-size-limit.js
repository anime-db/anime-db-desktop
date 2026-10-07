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

// Blocks the submit of the edit form when the chosen cover is over the limit (issue #949): the
// server would never see such a request (PHP drops a body over post_max_size, CSRF token included).
// The root holds <input type="file" data-max-bytes data-too-large-message> and a [data-cover-error]
// element for the message; the limit and the text come from the server.
(function () {
    function mountCoverSizeLimit(root) {
        const input = root.querySelector('input[type="file"][data-max-bytes]');
        const error = root.querySelector('[data-cover-error]');
        const form = input && input.form;
        const max = input ? Number(input.dataset.maxBytes) : 0;

        if (!input || !error || !form || !(max > 0)) {
            return undefined;
        }

        const serverMessage = error.textContent;
        let blocked = false;

        function onChange() {
            const file = input.files && input.files[0];
            blocked = Boolean(file) && file.size > max;
            input.classList.toggle('is-invalid', blocked);
            if (blocked) {
                error.textContent = input.dataset.tooLargeMessage || '';
                error.hidden = false;
            } else {
                error.textContent = serverMessage;
                error.hidden = serverMessage === '';
            }
        }

        function onSubmit(event) {
            if (blocked) {
                event.preventDefault();
                input.focus();
            }
        }

        input.addEventListener('change', onChange);
        form.addEventListener('submit', onSubmit);

        return function unmountCoverSizeLimit() {
            input.removeEventListener('change', onChange);
            form.removeEventListener('submit', onSubmit);
        };
    }

    window.Controller.registerControl('cover-size-limit', mountCoverSizeLimit);
})();
