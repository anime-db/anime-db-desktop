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

(function () {
    const API_URL = '/anime';
    // AnimeListController requires watch_status (issue #74); the filter UI itself is a
    // separate future task, so this is a placeholder default rather than a real choice.
    const DEFAULT_WATCH_STATUS = 'watching';
    const PAGE_SIZE = 20;
    // A label click on the anime detail page (issue #104) links here with ?labels=<id> — the
    // only filter this page currently understands from the URL, ahead of the full filter UI.
    const labelFilter = new URLSearchParams(window.location.search).get('labels');

    const grid = document.getElementById('anime-list-grid');
    const emptyMessage = document.getElementById('anime-list-empty');
    const errorMessage = document.getElementById('anime-list-error');
    const pagination = document.getElementById('anime-list-pagination');
    const sentinel = document.getElementById('anime-list-sentinel');

    let sentinelObserver = null;
    // Populated once from GET /translations/{locale}.json (issue #87) before the first render;
    // watch_status.*/anime_type.* keys already exist in the messages catalogue, so there is no
    // separate JS dictionary to keep in sync with them.
    let messages = {};

    function trans(key, fallback) {
        return Object.prototype.hasOwnProperty.call(messages, key) ? messages[key] : fallback;
    }

    function buildCard(anime) {
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
            placeholder.textContent = trans('anime_list.no_cover', 'anime_list.no_cover');
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
        badge.textContent = trans(`watch_status.${anime.watch_status}`, anime.watch_status);
        body.appendChild(badge);

        const meta = document.createElement('p');
        meta.className = 'anime-card__meta';
        const year = anime.date_premiere ? anime.date_premiere.slice(0, 4) : '—';
        meta.textContent = `${trans(`anime_type.${anime.type}`, anime.type)} · ${year}`;
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

    function renderCards(items, replace) {
        if (replace) {
            grid.replaceChildren();
        }
        items.forEach((anime) => grid.appendChild(buildCard(anime)));
        emptyMessage.hidden = grid.children.length > 0;
    }

    function buildQuery(offset) {
        const params = new URLSearchParams({
            watch_status: DEFAULT_WATCH_STATUS,
            limit: String(PAGE_SIZE),
            offset: String(offset),
        });

        if (labelFilter) {
            params.set('labels', labelFilter);
        }

        return `${API_URL}?${params.toString()}`;
    }

    async function fetchPage(offset) {
        const response = await fetch(buildQuery(offset));
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

        let data;
        try {
            data = await fetchPage(offset);
        } catch {
            errorMessage.hidden = false;
            return;
        }

        renderCards(data.items, replace);

        if (data.pagination_mode === 'classic') {
            setupClassicPagination(data.total, data.limit, data.offset);
        } else {
            setupInfiniteScroll(data.total, data.limit, data.offset);
        }
    }

    async function init() {
        try {
            messages = await window.AppTranslations.getCatalogue();
        } catch {
            messages = {};
        }

        loadPage(0, true);
    }

    init();
})();
