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
    // `data-submit-on-change` applies a setting the moment it changes, with two refinements for
    // keyboard users (issue #1011):
    //  - a <select> changed from the keyboard (arrow keys step through the options and fire
    //    `change` on every step) is applied on Enter or when it loses focus, not per keypress;
    //    a choice made with the mouse is still applied at once;
    //  - a radio/checkbox is applied at once, but its name/value are parked in sessionStorage so
    //    the page that loads after the form POST can put the focus back on the same control.
    const FOCUS_STORAGE_KEY = 'submit-on-change-focus';
    const keyboardDriven = new WeakSet();
    const pendingSelects = new WeakSet();

    function rememberFocus(target) {
        try {
            window.sessionStorage.setItem(FOCUS_STORAGE_KEY, JSON.stringify({
                name: target.name,
                value: target.value,
                action: target.form.getAttribute('action'),
            }));
        } catch {
            // Storage can be unavailable; losing the focus restore is harmless.
        }
    }

    function applyChange(target) {
        pendingSelects.delete(target);
        if (target.type === 'radio' || target.type === 'checkbox') {
            rememberFocus(target);
        }
        target.form.submit();
    }

    function restoreFocus() {
        let stored = null;
        try {
            stored = window.sessionStorage.getItem(FOCUS_STORAGE_KEY);
            window.sessionStorage.removeItem(FOCUS_STORAGE_KEY);
        } catch {
            return;
        }
        if (stored === null) {
            return;
        }

        let wanted;
        try {
            wanted = JSON.parse(stored);
        } catch {
            return;
        }
        if (wanted === null || typeof wanted !== 'object') {
            return;
        }

        const match = Array.from(document.querySelectorAll('[data-submit-on-change]')).find((control) => (
            control.name === wanted.name
            && control.value === wanted.value
            && (wanted.action === undefined || !control.form || control.form.getAttribute('action') === wanted.action)
        ));
        if (match) {
            match.focus();
        }
    }

    document.addEventListener('keydown', (event) => {
        const target = event.target.closest ? event.target.closest('[data-submit-on-change]') : null;
        if (!target || target.tagName !== 'SELECT') {
            return;
        }

        if (event.key === 'Enter') {
            if (pendingSelects.has(target) && target.form) {
                event.preventDefault();
                applyChange(target);
            }

            return;
        }

        if (event.key !== 'Tab' && event.key !== 'Escape') {
            keyboardDriven.add(target);
        }
    });

    ['pointerdown', 'mousedown'].forEach((eventName) => {
        document.addEventListener(eventName, (event) => {
            const target = event.target.closest ? event.target.closest('[data-submit-on-change]') : null;
            if (target) {
                keyboardDriven.delete(target);
            }
        });
    });

    document.addEventListener('focusout', (event) => {
        const target = event.target;
        if (target instanceof HTMLSelectElement && pendingSelects.has(target) && target.form) {
            applyChange(target);
        }
    });

    document.addEventListener('change', (event) => {
        const target = event.target.closest('[data-submit-on-change]');
        if (!target || !target.form) {
            return;
        }

        if (target.tagName === 'SELECT' && keyboardDriven.has(target)) {
            pendingSelects.add(target);

            return;
        }

        applyChange(target);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', restoreFocus);
    } else {
        restoreFocus();
    }

    document.addEventListener('submit', (event) => {
        const message = event.target.dataset.confirm;
        if (message !== undefined && !window.confirm(message)) {
            event.preventDefault();
        }
    });

    // A cover image whose file is missing on disk (record restored from a backup without media,
    // catalog folder removed by hand, ...) fires an `error` event once app-media:// answers 404.
    // The image is swapped for the same placeholder tile used for a record without a cover at
    // all (issue #633). `error` on an <img> does not bubble, so this only sees it during the
    // capture phase (third argument `true`), unlike every other listener in this file.
    const COVER_PLACEHOLDER_CLASSES = {
        'anime-card__thumb': 'anime-card__thumb--placeholder',
        'anime-detail__cover': 'anime-detail__cover--placeholder',
    };

    document.addEventListener('error', (event) => {
        const image = event.target;
        if (!(image instanceof HTMLImageElement)) {
            return;
        }

        // A source favicon fetched straight from the source's own domain (issue #830) fails for
        // reasons unrelated to the local catalog (site unreachable, blocked by the host, no
        // favicon.ico at all), so unlike the cover placeholder below, the <img> itself is kept —
        // only hidden — and swapped for the neutral `globe` icon already sitting next to it in
        // the markup as `.anime-detail__source-fallback`, rather than being replaced outright.
        // The <img> is only hidden once that fallback is actually found: without it, hiding the
        // image would leave the source link with no visible content at all.
        if (image.classList.contains('anime-detail__source-icon')) {
            const fallback = image.nextElementSibling;
            if (fallback && fallback.classList.contains('anime-detail__source-fallback')) {
                image.hidden = true;
                fallback.hidden = false;
            }

            return;
        }

        const coverClass = Object.keys(COVER_PLACEHOLDER_CLASSES).find((className) => image.classList.contains(className));
        if (!coverClass) {
            return;
        }
        const placeholderClass = COVER_PLACEHOLDER_CLASSES[coverClass];

        const placeholder = document.createElement('div');
        placeholder.className = `${image.className} ${placeholderClass}`;
        image.replaceWith(placeholder);
    }, true);
})();
