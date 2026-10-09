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

// Keeps keyboard focus across a re-render (issue #1010). A part of the page rebuilt wholesale
// (replaceChildren(), outerHTML) destroys the focused element and focus falls back to <body>, so
// the next Tab starts from the page header. run() remembers which element of the container had
// focus — by its data-focus-key — rebuilds, then focuses the element carrying the same key in the
// new markup, or the fallback when that element no longer exists. When focus was not inside the
// container nothing is touched, so a background re-render (e.g. a poll) never steals focus.
(function () {
    function findByKey(container, key) {
        return Array.from(container.querySelectorAll('[data-focus-key]'))
            .find((element) => element.dataset.focusKey === key) ?? null;
    }

    /**
     * @param {Element} container Root of the part of the page that is rebuilt.
     * @param {Function} render Rebuilds the container's content.
     * @param {Element|Function|null} [fallback] Element (or a function returning it, called after
     *        render) that gets focus when the previously focused element has no counterpart.
     */
    function run(container, render, fallback = null) {
        const active = document.activeElement;
        const hadFocus = active !== null && active !== container && container.contains(active);
        const key = hadFocus ? (active.dataset.focusKey ?? null) : null;

        render();

        if (!hadFocus) {
            return;
        }

        let target = key === null ? null : findByKey(container, key);
        if (target === null) {
            target = typeof fallback === 'function' ? fallback() : fallback;
        }
        if (target) {
            target.focus();
        }
    }

    window.FocusRestore = { run };
})();
