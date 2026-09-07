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
    const PAGE_SIZE = 20;
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

    let sentinelObserver = null;
    let searchDebounceTimer = null;
    let searchQuery = '';
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
            placeholder.textContent = window.AppTranslations.resolveKey(catalogue, 'anime_list.no_cover');
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

    function buildQuery(offset) {
        const params = new URLSearchParams({
            limit: String(PAGE_SIZE),
            offset: String(offset),
        });

        if (labelFilter) {
            params.set('labels', labelFilter);
        }

        if (searchQuery) {
            params.set('name', searchQuery);
        }

        return `${API_URL}?${params.toString()}`;
    }

    async function fetchPage(offset, signal) {
        const response = await fetch(buildQuery(offset), { signal });
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

    async function loadPage(offset, replace) {
        disconnectSentinel();
        errorMessage.hidden = true;

        if (pendingRequest) {
            pendingRequest.abort();
        }
        const controller = new AbortController();
        pendingRequest = controller;

        let data;
        try {
            data = await fetchPage(offset, controller.signal);
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

        if (data.pagination_mode === 'classic') {
            setupClassicPagination(data.total, data.limit, data.offset);
        } else {
            setupInfiniteScroll(data.total, data.limit, data.offset);
        }
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

    function init() {
        setupSearchInput();
        loadPage(0, true);
    }

    init();
})();
