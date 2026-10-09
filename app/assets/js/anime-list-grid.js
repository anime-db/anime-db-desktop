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

// The catalog grid/pagination concern (issue #712, split out of the list core): card rendering,
// the column-driven request limit, classic/infinite pagination and the ResizeObserver that keeps
// them in sync with the grid's actual layout. Owns sentinelObserver, resizeObserver,
// resizeDebounceTimer, paginationMode, loadedCount, currentOffset and lastColumnCount — state the
// list core (anime-list.js) never touches directly. The one thing it cannot do on its own is
// start a new page request, so init() takes a `requestPage` callback (the core's loadPage) that
// every pagination control and the resize handler call through instead.
(function () {
    // Rows-per-page is the only knob left in code (issue #665) — the page size itself is derived
    // from the grid's actual column count at request time, not a user-facing setting. Kept in
    // sync by hand with AnimeListRequestParser::MAX_LIMIT (PHP) since the server is the one that
    // enforces it; the client only needs it to avoid asking for more than the server will give.
    const ROWS = 6;
    const MAX_LIMIT = 100;
    // Column count changes without a `resize` event too — a scrollbar appearing/disappearing or
    // the filter panel collapsing both resize the grid without resizing the window — so this
    // watches the grid itself. Debounced because width can wobble across several frames during a
    // drag-resize while the column count itself only changes once.
    const RESIZE_DEBOUNCE_MS = 150;

    // Assigned in init(), not queried here at module-load time (issue #734): the control this
    // module is part of mounts on htmx:load, potentially long after this script tag itself ran.
    let grid = null;
    let emptyMessage = null;
    let pagination = null;
    let sentinel = null;
    // The catalog area is the scroll container (issue #995), not the window.
    let scroller = null;

    let sentinelObserver = null;
    let resizeObserver = null;
    let resizeDebounceTimer = null;
    // Tracked so a grid resize can tell what mode it is reacting to without waiting on another
    // network round trip (issue #665): infinite scroll tops up the last row, classic re-pages.
    let paginationMode = null;
    // Total cards rendered so far in infinite-scroll mode — not a page number, since the limit
    // (and therefore what a "page" even means) can change mid-session on resize.
    let loadedCount = 0;
    // Offset of the first record on the currently displayed page in classic mode — the anchor a
    // resize re-pages around, so the record the user was looking at stays on screen.
    let currentOffset = 0;
    let lastColumnCount = null;

    // Provided by the list core at init() — every pagination control (a page button, the
    // infinite-scroll sentinel) and a column-count change both need to start a new page request,
    // but only the core owns the request/abort machinery that does that (issue #712).
    let requestPage = null;

    function buildCard(anime, catalogue) {
        const card = document.createElement('a');
        card.className = 'anime-card';
        card.href = `/anime/${anime.id}`;

        if (anime.cover) {
            const thumb = document.createElement('img');
            thumb.className = 'anime-card__thumb';
            thumb.loading = 'lazy';
            thumb.alt = '';
            thumb.src = `app-media://anime/${anime.id}/${anime.cover}`;
            card.appendChild(thumb);
        } else {
            const placeholder = document.createElement('div');
            placeholder.className = 'anime-card__thumb anime-card__thumb--placeholder';
            card.appendChild(placeholder);
        }

        const body = document.createElement('div');
        body.className = 'anime-card__body';

        const title = document.createElement('h3');
        title.className = 'anime-card__title';
        title.textContent = anime.title;
        title.title = anime.title;
        body.appendChild(title);

        const badge = document.createElement('span');
        badge.className = `anime-card__badge anime-card__badge--${anime.watch_status}`;
        badge.textContent = window.AppTranslations.resolveKey(catalogue, `watch_status.${anime.watch_status}`);
        body.appendChild(badge);

        const meta = document.createElement('p');
        meta.className = 'anime-card__meta';
        const year = anime.date_premiere ? anime.date_premiere.slice(0, 4) : '—';
        const animeType = window.AppTranslations.resolveKey(catalogue, `anime_type.${anime.type}`);
        meta.textContent = `${animeType} · ${year}`;
        body.appendChild(meta);

        if (Array.isArray(anime.labels) && anime.labels.length > 0) {
            const tags = document.createElement('div');
            tags.className = 'anime-card__tags';
            anime.labels.forEach((label) => {
                const tag = document.createElement('span');
                tag.className = 'anime-card__tag';
                tag.textContent = label;
                tags.appendChild(tag);
            });
            body.appendChild(tags);
        }

        card.appendChild(body);

        return card;
    }

    function renderCards(items, replace, isNewQuery, catalogue) {
        if (replace) {
            grid.replaceChildren();
        }
        if (replace && isNewQuery) {
            // The grid collapsing to a shorter height would otherwise leave the catalog area's scroll
            // position wherever the browser clamps it, not at the top of the new list. Gated on
            // an explicit isNewQuery flag from the caller (issue #687), not on offset === 0: a
            // column-count requery of the same page can land back on offset 0 too, and that is
            // not a "the result set changed" event the way a search/sort/filter change is.
            if (scroller) {
                scroller.scrollTo({ top: 0 });
            }
        }
        for (const anime of items) {
            grid.appendChild(buildCard(anime, catalogue));
        }
        emptyMessage.hidden = grid.children.length > 0;
    }

    // The number of columns the grid actually laid out, read from the resolved track list
    // getComputedStyle() reports (e.g. "182.4px 182.4px 182.4px") — never recomputed from the
    // `minmax()`/gap/padding values in _anime-list.scss, which would drift from the real CSS the
    // moment either one is edited without the other. Chromium is the only rendering engine this
    // app ships on, so there is no cross-browser fallback to account for.
    function getColumnCount() {
        const value = getComputedStyle(grid).gridTemplateColumns;
        const tracks = value ? value.trim().split(/\s+/).filter(Boolean) : [];

        return tracks.length > 0 ? tracks.length : 1;
    }

    // Keeps the limit a multiple of the column count so a short last row can only mean "the list
    // ended", never "the page ended" (issue #665) — rows are capped, not the limit directly, so a
    // narrow window (few columns) still gets ROWS full rows instead of being clipped mid-row.
    function computeLimit(columns) {
        const rows = Math.max(1, Math.min(ROWS, Math.floor(MAX_LIMIT / columns)));

        return columns * rows;
    }

    function disconnectSentinel() {
        if (sentinelObserver) {
            sentinelObserver.disconnect();
            sentinelObserver = null;
        }
    }

    function setupClassicPagination(total, limit, offset) {
        sentinel.hidden = true;
        disconnectSentinel();
        currentOffset = offset;

        const pageCount = Math.max(1, Math.ceil(total / limit));
        const currentPage = Math.floor(offset / limit) + 1;

        pagination.replaceChildren();
        pagination.hidden = pageCount <= 1;

        for (let page = 1; page <= pageCount; page += 1) {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = String(page);
            if (page === currentPage) {
                button.setAttribute('aria-current', 'true');
            }
            // Jumping to page 1 is treated the same as a fresh search (isNewQuery = true) —
            // it puts the viewport back where a reset expects it. Any other page is just
            // browsing the same result set at a different offset, so the scroll stays put.
            button.addEventListener('click', () => requestPage((page - 1) * limit, true, page === 1));
            pagination.appendChild(button);
        }
    }

    function setupInfiniteScroll(total, limit, offset) {
        pagination.hidden = true;
        disconnectSentinel();

        const hasMore = offset + limit < total;
        sentinel.hidden = !hasMore;
        if (!hasMore) {
            return;
        }

        sentinelObserver = new IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                requestPage(offset + limit, false, false);
            }
        }, { root: scroller });
        sentinelObserver.observe(sentinel);
    }

    // Re-pages (classic) or tops up the last row (infinite scroll) so the multiple-of-columns
    // invariant holds under the grid's current column count. Shared by handleGridResize() and by
    // reconcileColumnCount()'s post-response check below (issue #665) — a resize that happens
    // while a request is still in flight cannot be handled by handleGridResize() alone, since
    // paginationMode is only known once a response has landed.
    function applyColumnCountChange(columns) {
        if (paginationMode === 'classic') {
            const newLimit = computeLimit(columns);
            const newPage = Math.floor(currentOffset / newLimit) + 1;
            // isNewQuery is always false here even when the anchor lands back on offset 0
            // (e.g. widening the window enough that the current record now fits on page 1):
            // this is a requery of the same result set, not a new one (issue #687).
            requestPage((newPage - 1) * newLimit, true, false);
        } else if (paginationMode === 'infinite_scroll') {
            const deficit = (columns - (loadedCount % columns)) % columns;
            if (deficit > 0) {
                requestPage(loadedCount, false, false, deficit);
            }
        }
    }

    // Reacts to the grid's column count changing (window resize, scrollbar appearing, filter
    // panel collapsing, ...) — never to `window.resize` directly, since none of those besides a
    // literal window resize fire it (issue #665).
    function handleGridResize() {
        const columns = getColumnCount();
        if (columns === lastColumnCount) {
            return;
        }
        lastColumnCount = columns;
        applyColumnCountChange(columns);
    }

    // Renders a page response's cards and updates the pagination-mode bookkeeping this module
    // owns. Called by the list core right after rendering, before it touches its own state
    // (issue #712) — mirrors the original loadPage()'s ordering exactly.
    function renderPage(data, replace, isNewQuery, catalogue) {
        renderCards(data.items, replace, isNewQuery, catalogue);
        loadedCount = replace ? data.items.length : loadedCount + data.items.length;
        paginationMode = data.pagination_mode;
    }

    function setupPagination(data) {
        if (paginationMode === 'classic') {
            setupClassicPagination(data.total, data.limit, data.offset);
        } else {
            setupInfiniteScroll(data.total, data.limit, data.offset);
        }
    }

    // The column count can change while a request was in flight (paginationMode is not known
    // until renderPage() runs, so a resize that happened mid-request could not act on it via
    // handleGridResize() alone). Compare against the column count the just-sent limit was
    // computed from, not against lastColumnCount, since a resize's own debounced callback may
    // already have updated lastColumnCount without being able to correct anything (issue #665).
    function reconcileColumnCount(requestColumns) {
        const columns = getColumnCount();
        lastColumnCount = columns;
        if (columns !== requestColumns) {
            applyColumnCountChange(columns);
        }
    }

    function seedColumnCount() {
        // ResizeObserver delivers a synthetic initial callback right after observe() (spec
        // behaviour, not a real resize) — seed lastColumnCount now so handleGridResize() treats
        // it as a no-op instead of re-requesting the page it is about to load anyway.
        lastColumnCount = getColumnCount();
    }

    function init(root, { requestPage: requestPageCallback }) {
        grid = root.querySelector('#anime-list-grid');
        emptyMessage = root.querySelector('#anime-list-empty');
        pagination = root.querySelector('#anime-list-pagination');
        sentinel = root.querySelector('#anime-list-sentinel');
        scroller = root.querySelector('#anime-list-catalog');
        requestPage = requestPageCallback;

        resizeObserver = new ResizeObserver(() => {
            clearTimeout(resizeDebounceTimer);
            resizeDebounceTimer = setTimeout(handleGridResize, RESIZE_DEBOUNCE_MS);
        });
        resizeObserver.observe(grid);
    }

    // Symmetric with init() (issue #734): disconnects the ResizeObserver init() created and the
    // IntersectionObserver a pagination call may have created, and resets every piece of state
    // this module owns so a later init() on a fresh mount starts clean rather than inheriting a
    // stale paginationMode/loadedCount/currentOffset from whatever the previous mount last saw.
    function destroy() {
        clearTimeout(resizeDebounceTimer);
        resizeDebounceTimer = null;
        if (resizeObserver) {
            resizeObserver.disconnect();
            resizeObserver = null;
        }
        disconnectSentinel();

        paginationMode = null;
        loadedCount = 0;
        currentOffset = 0;
        lastColumnCount = null;
        requestPage = null;
        grid = null;
        emptyMessage = null;
        pagination = null;
        scroller = null;
        sentinel = null;
    }

    window.AnimeListGrid = {
        init,
        destroy,
        seedColumnCount,
        getColumnCount,
        computeLimit,
        disconnectSentinel,
        renderPage,
        setupPagination,
        reconcileColumnCount,
    };
})();
