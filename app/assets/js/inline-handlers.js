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
        if (image.classList.contains('anime-detail__source-icon')) {
            image.hidden = true;
            const fallback = image.nextElementSibling;
            if (fallback && fallback.classList.contains('anime-detail__source-fallback')) {
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
