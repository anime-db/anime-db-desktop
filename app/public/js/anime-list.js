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

(function () {
    const API_URL = '/anime';
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
    // A label click on the anime detail page (issue #104) links here with ?labels=<id> — the
    // only filter this page currently understands from the URL, ahead of the full filter UI.
    const labelFilter = new URLSearchParams(window.location.search).get('labels');
    // Debounce the search box (issue #199) so a full request isn't fired on every keystroke —
    // AnimeListController resolves this as "name" against Meilisearch, falling back to the
    // FTS5 quick-filter server-side when it is unavailable.
    const SEARCH_DEBOUNCE_MS = 300;

    const grid = document.getElementById('anime-list-grid');
    const emptyMessage = document.getElementById('anime-list-empty');
    const errorMessage = document.getElementById('anime-list-error');
    const pagination = document.getElementById('anime-list-pagination');
    const sentinel = document.getElementById('anime-list-sentinel');
    const searchInput = document.getElementById('anime-list-search');
    const sortContainer = document.getElementById('anime-list-sort');
    const sortDirectionButton = document.getElementById('anime-list-sort-direction');

    let sentinelObserver = null;
    let resizeObserver = null;
    let resizeDebounceTimer = null;
    let searchDebounceTimer = null;
    let searchQuery = '';
    let sortField = 'date_update';
    let sortDirection = 'desc';
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
    // Guards against the response race (issue #208): a slow scroll-append response arriving
    // after a faster search response would otherwise splice stale cards into the fresh grid.
    let pendingRequest = null;

    function buildCard(anime, catalogue) {
        const card = document.createElement('article');
        card.className = 'anime-card';

        if (anime.cover) {
            const thumb = document.createElement('img');
            thumb.className = 'anime-card__thumb';
            thumb.loading = 'lazy';
            thumb.alt = anime.title;
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

    function renderCards(items, replace, catalogue) {
        if (replace) {
            grid.replaceChildren();
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

    function buildQuery(offset, limit) {
        const params = new URLSearchParams({
            limit: String(limit),
            offset: String(offset),
            sort: sortField,
            direction: sortDirection,
        });

        if (labelFilter) {
            params.set('labels', labelFilter);
        }

        if (searchQuery) {
            params.set('name', searchQuery);
        }

        return `${API_URL}?${params.toString()}`;
    }

    async function fetchPage(offset, limit, signal) {
        const response = await fetch(buildQuery(offset, limit), { signal });
        if (!response.ok) {
            throw new Error(`Anime list request failed with status ${response.status}`);
        }

        return response.json();
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
            button.addEventListener('click', () => loadPage((page - 1) * limit, true));
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
                loadPage(offset + limit, false);
            }
        });
        sentinelObserver.observe(sentinel);
    }

    // `limitOverride` is used for exactly one caller: the infinite-scroll top-up after a resize,
    // which asks for only the handful of records needed to complete the last row rather than a
    // full page (issue #665). Every other caller lets the limit follow the grid's current column
    // count. The offset for the *next* request always comes back from the response (`data.limit`,
    // via setup{Classic,Infinite}), never from what this call sent — the server is free to clamp.
    async function loadPage(offset, replace, limitOverride) {
        disconnectSentinel();
        errorMessage.hidden = true;

        if (pendingRequest) {
            pendingRequest.abort();
        }
        const controller = new AbortController();
        pendingRequest = controller;

        const limit = limitOverride !== undefined ? limitOverride : computeLimit(getColumnCount());

        let data;
        try {
            data = await fetchPage(offset, limit, controller.signal);
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }
            errorMessage.hidden = false;
            return;
        }

        // A failed catalogue fetch falls back to {} (resolveKey() then returns each raw key)
        // instead of blocking card rendering. The fetch is still a network round-trip and can be
        // outlived by a newer loadPage() call, so re-check the abort signal before touching the
        // grid — otherwise a superseded response could splice its cards in after a fresher one
        // already rendered (issue #208).
        const catalogue = await window.AppTranslations.getCatalogue().catch(() => ({}));
        if (controller.signal.aborted) {
            return;
        }

        renderCards(data.items, replace, catalogue);
        loadedCount = replace ? data.items.length : loadedCount + data.items.length;
        paginationMode = data.pagination_mode;

        if (paginationMode === 'classic') {
            setupClassicPagination(data.total, data.limit, data.offset);
        } else {
            setupInfiniteScroll(data.total, data.limit, data.offset);
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

        if (paginationMode === 'classic') {
            const newLimit = computeLimit(columns);
            const newPage = Math.floor(currentOffset / newLimit) + 1;
            loadPage((newPage - 1) * newLimit, true);
        } else if (paginationMode === 'infinite_scroll') {
            const deficit = (columns - (loadedCount % columns)) % columns;
            if (deficit > 0) {
                loadPage(loadedCount, false, deficit);
            }
        }
    }

    function setupResizeObserver() {
        resizeObserver = new ResizeObserver(() => {
            clearTimeout(resizeDebounceTimer);
            resizeDebounceTimer = setTimeout(handleGridResize, RESIZE_DEBOUNCE_MS);
        });
        resizeObserver.observe(grid);
    }

    function setupSearchInput() {
        if (!searchInput) {
            return;
        }

        searchInput.addEventListener('input', () => {
            clearTimeout(searchDebounceTimer);
            searchDebounceTimer = setTimeout(() => {
                searchQuery = searchInput.value.trim();
                loadPage(0, true);
            }, SEARCH_DEBOUNCE_MS);
        });
    }

    function updateSortFieldButtons() {
        sortContainer.querySelectorAll('[data-sort-field]').forEach((button) => {
            if (button.dataset.sortField === sortField) {
                button.setAttribute('aria-current', 'true');
            } else {
                button.removeAttribute('aria-current');
            }
        });
    }

    function setupSortControls() {
        if (!sortContainer) {
            return;
        }

        sortContainer.addEventListener('click', (event) => {
            const fieldButton = event.target.closest('[data-sort-field]');
            if (fieldButton) {
                sortField = fieldButton.dataset.sortField;
                updateSortFieldButtons();
                loadPage(0, true);

                return;
            }

            if (sortDirectionButton && event.target.closest('#anime-list-sort-direction')) {
                sortDirection = sortDirection === 'desc' ? 'asc' : 'desc';
                sortDirectionButton.dataset.direction = sortDirection;
                sortDirectionButton.textContent = sortDirection === 'desc' ? '↓' : '↑';
                sortDirectionButton.setAttribute(
                    'aria-label',
                    sortDirectionButton.dataset[sortDirection === 'desc' ? 'labelDesc' : 'labelAsc'],
                );
                loadPage(0, true);
            }
        });
    }

    function init() {
        setupSearchInput();
        setupSortControls();
        setupResizeObserver();
        loadPage(0, true);
    }

    init();
})();
