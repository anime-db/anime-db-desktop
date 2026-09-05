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

// Replaces the last on*-attribute event handlers in the host templates (issue #599) with
// document-level delegated listeners, so the markup stays free of executable code. Delegation on
// `document` (rather than binding at load) is required because settings/plugin/widgets.html.twig
// and storage/list.html.twig render some of this markup through HTMX after the initial page parse.
(function () {
    document.addEventListener('change', (event) => {
        const target = event.target.closest('[data-submit-on-change]');
        if (target && target.form) {
            target.form.submit();
        }
    });

    document.addEventListener('submit', (event) => {
        const message = event.target.dataset.confirm;
        if (message !== undefined && !window.confirm(message)) {
            event.preventDefault();
        }
    });
})();
