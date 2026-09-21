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
    const FACETS_URL = '/anime/facets';
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
    // only filter this page reads from the URL; it seeds the filter panel once at init, nothing
    // is ever written back to the address bar (issue #666).
    const labelFilter = new URLSearchParams(window.location.search).get('labels');
    // Debounce the search box (issue #199) so a full request isn't fired on every keystroke —
    // AnimeListController resolves this as "name" against Meilisearch, falling back to the
    // FTS5 quick-filter server-side when it is unavailable.
    const SEARCH_DEBOUNCE_MS = 300;

    // The eight filter-panel sections (issue #666), in the fixed display order the issue
    // requires. Each key doubles as the property name on the filters state objects below and as
    // the `data-filter-section` attribute in list.html.twig, so a section only has to be named
    // once. `facetKey` is the matching property on the GET /anime/facets response.
    const FACET_SECTIONS = {
        watch_status: { facetKey: 'watch_status', kind: 'enum', translatePrefix: 'watch_status', input: 'checkbox' },
        type: { facetKey: 'type', kind: 'enum', translatePrefix: 'anime_type', input: 'checkbox' },
        date_premiere: { facetKey: 'date_premiere_decade', kind: 'decade', input: 'radio' },
        user_rating: { facetKey: 'user_rating', kind: 'rating', input: 'checkbox' },
        labels: { facetKey: 'labels', kind: 'entity', input: 'checkbox' },
        genres: { facetKey: 'genres', kind: 'enum', translatePrefix: 'genre', input: 'checkbox' },
        themes: { facetKey: 'themes', kind: 'enum', translatePrefix: 'theme', input: 'checkbox' },
        studios: { facetKey: 'studios', kind: 'entity', input: 'checkbox' },
    };
    // Rating facet buckets come back in GROUP BY order, not display order — the issue requires
    // five checkboxes counting down from 5, plus "no rating" last.
    const RATING_ORDER = ['5', '4', '3', '2', '1', 'none'];

    const grid = document.getElementById('anime-list-grid');
    const emptyMessage = document.getElementById('anime-list-empty');
    const errorMessage = document.getElementById('anime-list-error');
    const pagination = document.getElementById('anime-list-pagination');
    const sentinel = document.getElementById('anime-list-sentinel');
    const searchInput = document.getElementById('anime-list-search');
    const sortContainer = document.getElementById('anime-list-sort');
    const sortDirectionButton = document.getElementById('anime-list-sort-direction');
    const filtersToggleButton = document.getElementById('anime-list-filters-toggle');
    const filtersCountBadge = document.getElementById('anime-list-filters-count');
    const filtersPanel = document.getElementById('anime-list-filters');
    const filterApplyButton = document.getElementById('anime-list-filter-apply');
    const chipList = document.getElementById('anime-list-chip-list');
    const chipsShown = document.getElementById('anime-list-chips-shown');
    const chipsResetButton = document.getElementById('anime-list-chips-reset');

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
    // The facets request (issue #666) has its own AbortController, independent of the list
    // request above: it must fire on every filter change but never on an infinite-scroll
    // page-append, which pendingRequest above already tracks separately.
    let pendingFacetsRequest = null;
    // The filter values a click has actually applied — drives the list/facets query, the chip
    // row and the "applied" highlight in the panel.
    let appliedFilters = createEmptyFilters();
    // The filter values currently marked in the panel but not yet applied — a checkbox toggles
    // this without touching appliedFilters; the panel's checked state always reflects this, not
    // appliedFilters (issue #666).
    let pendingFilters = createEmptyFilters();
    // Last GET /anime/facets response, kept around so a chip removed from another part of the
    // page (or the panel patch itself) can resolve a label/studio id back to its display name
    // without a second request.
    let lastFacets = null;
    // Total matches under the current filter — from the list response, refreshed on every
    // loadPage() call (including scroll-appends, since the filtered total does not change
    // mid-scroll). Used as the numerator of "Shown X of Y".
    let currentFilteredTotal = 0;
    // Unfiltered catalog size, fetched once — the denominator of "Shown X of Y" answers "why are
    // there so few records" only when compared against the whole catalog, not the current page.
    let catalogTotal = null;

    function createEmptyFilters() {
        return {
            watch_status: new Set(),
            type: new Set(),
            date_premiere: null,
            user_rating: new Set(),
            labels: new Set(),
            genres: new Set(),
            themes: new Set(),
            studios: new Set(),
        };
    }

    function cloneFilters(filters) {
        return {
            watch_status: new Set(filters.watch_status),
            type: new Set(filters.type),
            date_premiere: filters.date_premiere,
            user_rating: new Set(filters.user_rating),
            labels: new Set(filters.labels),
            genres: new Set(filters.genres),
            themes: new Set(filters.themes),
            studios: new Set(filters.studios),
        };
    }

    function isFiltersEmpty(filters) {
        return filters.watch_status.size === 0
            && filters.type.size === 0
            && filters.date_premiere === null
            && filters.user_rating.size === 0
            && filters.labels.size === 0
            && filters.genres.size === 0
            && filters.themes.size === 0
            && filters.studios.size === 0;
    }

    // Every entry across every section counts as one — this is what both the "Filters · N"
    // toggle badge and the chip row must agree on (issue #666 acceptance criterion).
    function appliedFilterEntries() {
        const entries = [];
        ['watch_status', 'type', 'user_rating', 'labels', 'genres', 'themes', 'studios'].forEach((sectionKey) => {
            appliedFilters[sectionKey].forEach((value) => entries.push({ sectionKey, value }));
        });
        if (appliedFilters.date_premiere !== null) {
            entries.push({ sectionKey: 'date_premiere', value: appliedFilters.date_premiere });
        }

        return entries;
    }

    function decadeRange(decade) {
        const start = parseInt(decade, 10);

        return { from: `${start}-01-01`, to: `${start + 9}-12-31` };
    }

    function appendFilterParams(params, filters) {
        filters.watch_status.forEach((value) => params.append('watch_status[]', value));
        filters.type.forEach((value) => params.append('type[]', value));
        filters.genres.forEach((value) => params.append('genres[]', value));
        filters.themes.forEach((value) => params.append('themes[]', value));
        filters.labels.forEach((value) => params.append('labels[]', value));
        filters.studios.forEach((value) => params.append('studios[]', value));

        filters.user_rating.forEach((value) => {
            if (value === 'none') {
                params.set('user_rating_none', '1');
            } else {
                params.append('user_rating[]', value);
            }
        });

        if (filters.date_premiere === 'none') {
            params.set('date_premiere_none', '1');
        } else if (filters.date_premiere !== null) {
            const range = decadeRange(filters.date_premiere);
            params.set('date_premiere_from', range.from);
            params.set('date_premiere_to', range.to);
        }
    }

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

    function renderCards(items, replace, isNewQuery, catalogue) {
        if (replace) {
            grid.replaceChildren();
        }
        if (replace && isNewQuery) {
            // The grid collapsing to a shorter height would otherwise leave the window scroll
            // position wherever the browser clamps it, not at the top of the new list. Gated on
            // an explicit isNewQuery flag from the caller (issue #687), not on offset === 0: a
            // column-count requery of the same page can land back on offset 0 too, and that is
            // not a "the result set changed" event the way a search/sort/filter change is.
            window.scrollTo(0, 0);
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

    function buildListQuery(offset, limit) {
        const params = new URLSearchParams({
            limit: String(limit),
            offset: String(offset),
            sort: sortField,
            direction: sortDirection,
        });

        if (searchQuery) {
            params.set('name', searchQuery);
        }
        appendFilterParams(params, appliedFilters);

        return `${API_URL}?${params.toString()}`;
    }

    function buildFacetsQuery() {
        const params = new URLSearchParams();

        if (searchQuery) {
            params.set('name', searchQuery);
        }
        appendFilterParams(params, appliedFilters);

        return `${FACETS_URL}?${params.toString()}`;
    }

    async function fetchPage(offset, limit, signal) {
        const response = await fetch(buildListQuery(offset, limit), { signal });
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
            // Jumping to page 1 is treated the same as a fresh search (isNewQuery = true) —
            // it puts the viewport back where a reset expects it. Any other page is just
            // browsing the same result set at a different offset, so the scroll stays put.
            button.addEventListener('click', () => loadPage((page - 1) * limit, true, page === 1));
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
                loadPage(offset + limit, false, false);
            }
        });
        sentinelObserver.observe(sentinel);
    }

    // Re-pages (classic) or tops up the last row (infinite scroll) so the multiple-of-columns
    // invariant holds under the grid's current column count. Shared by handleGridResize() and by
    // loadPage()'s post-response reconciliation below (issue #665) — a resize that happens while
    // a request is still in flight cannot be handled by handleGridResize() itself, since
    // paginationMode is only known once a response has landed.
    function applyColumnCountChange(columns) {
        if (paginationMode === 'classic') {
            const newLimit = computeLimit(columns);
            const newPage = Math.floor(currentOffset / newLimit) + 1;
            // isNewQuery is always false here even when the anchor lands back on offset 0
            // (e.g. widening the window enough that the current record now fits on page 1):
            // this is a requery of the same result set, not a new one (issue #687).
            loadPage((newPage - 1) * newLimit, true, false);
        } else if (paginationMode === 'infinite_scroll') {
            const deficit = (columns - (loadedCount % columns)) % columns;
            if (deficit > 0) {
                loadPage(loadedCount, false, false, deficit);
            }
        }
    }

    function updateShownCount(catalogue) {
        const total = catalogTotal !== null ? catalogTotal : currentFilteredTotal;
        chipsShown.textContent = window.AppTranslations.resolveKey(catalogue, 'anime_list.filter_shown_count', {
            shown: currentFilteredTotal,
            total,
        });
    }

    // `isNewQuery` tells renderCards() whether this call is fetching a genuinely new result set
    // (search, sort, filter change, "reset all", or a jump to page 1) as opposed to re-fetching
    // the same result set at a different window (a column-count requery, an infinite-scroll
    // top-up, or paging to any page other than the first) — see renderCards() (issue #687).
    //
    // `limitOverride` is used for exactly one caller: the infinite-scroll top-up after a resize,
    // which asks for only the handful of records needed to complete the last row rather than a
    // full page (issue #665). Every other caller lets the limit follow the grid's current column
    // count. The offset for the *next* request always comes back from the response (`data.limit`,
    // via setup{Classic,Infinite}), never from what this call sent — the server is free to clamp.
    async function loadPage(offset, replace, isNewQuery, limitOverride) {
        disconnectSentinel();
        errorMessage.hidden = true;

        if (pendingRequest) {
            pendingRequest.abort();
        }
        const controller = new AbortController();
        pendingRequest = controller;

        const requestColumns = getColumnCount();
        const limit = limitOverride !== undefined ? limitOverride : computeLimit(requestColumns);

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

        renderCards(data.items, replace, isNewQuery, catalogue);
        loadedCount = replace ? data.items.length : loadedCount + data.items.length;
        paginationMode = data.pagination_mode;
        currentFilteredTotal = data.total;
        updateShownCount(catalogue);

        if (paginationMode === 'classic') {
            setupClassicPagination(data.total, data.limit, data.offset);
        } else {
            setupInfiniteScroll(data.total, data.limit, data.offset);
        }

        // The column count can change while this request was in flight (paginationMode is not
        // known until here, so a resize that happened mid-request could not act on it via
        // handleGridResize() alone). Compare against the column count the just-sent limit was
        // computed from, not against lastColumnCount, since a resize's own debounced callback may
        // already have updated lastColumnCount without being able to correct anything (issue #665).
        const columns = getColumnCount();
        lastColumnCount = columns;
        if (columns !== requestColumns) {
            applyColumnCountChange(columns);
        }
    }

    // Counters for the filter panel (issue #666), refetched on every filter/search change with
    // its own AbortController so a page-append from infinite scroll — which never calls this
    // function — cannot be confused with a facet-affecting change, and so a fast filter click
    // right after a slow one cannot splice stale counts into the panel.
    async function loadFacets() {
        if (pendingFacetsRequest) {
            pendingFacetsRequest.abort();
        }
        const controller = new AbortController();
        pendingFacetsRequest = controller;

        let data;
        try {
            const response = await fetch(buildFacetsQuery(), { signal: controller.signal });
            if (!response.ok) {
                throw new Error(`Anime facets request failed with status ${response.status}`);
            }
            data = await response.json();
        } catch {
            // Facets are supplementary to the list — a failed or superseded fetch just leaves
            // the panel showing its last known counts instead of surfacing an error state.
            return;
        }
        if (controller.signal.aborted) {
            return;
        }

        lastFacets = data;
        const catalogue = await window.AppTranslations.getCatalogue().catch(() => ({}));
        if (controller.signal.aborted) {
            return;
        }

        renderFilterPanel(data, catalogue);
        // Chips are also redrawn synchronously on every appliedFilters mutation (applyPending,
        // removeAppliedValue, resetAllFilters, the URL seed) — this call never is their only
        // source. It only upgrades an entity chip (label/studio) from a raw id fallback to its
        // resolved display name once lastFacets carries that name; a failed or superseded facets
        // request just leaves that upgrade for the next successful one (issue #676 review).
        renderChips(catalogue);
    }

    async function loadCatalogTotal() {
        try {
            const response = await fetch(`${API_URL}?limit=1&offset=0`);
            if (response.ok) {
                const data = await response.json();
                catalogTotal = data.total;
            }
        } catch {
            // Best effort — the shown-count denominator falls back to the filtered total until
            // this resolves, which only matters on the very first paint.
        }

        const catalogue = await window.AppTranslations.getCatalogue().catch(() => ({}));
        updateShownCount(catalogue);
    }

    function nameFromBucket(config, bucket, catalogue) {
        if (config.kind === 'enum') {
            return window.AppTranslations.resolveKey(catalogue, `${config.translatePrefix}.${bucket.value}`);
        }
        if (config.kind === 'entity') {
            return bucket.name;
        }
        if (config.kind === 'rating') {
            return bucket.value === 'none'
                ? window.AppTranslations.resolveKey(catalogue, 'anime_list.filter_no_rating')
                : bucket.value;
        }

        return bucket.value === 'none'
            ? window.AppTranslations.resolveKey(catalogue, 'anime_list.filter_no_date_premiere')
            : window.AppTranslations.resolveKey(catalogue, 'anime_list.filter_decade_label', {
                decade: bucket.value.replace(/s$/, ''),
            });
    }

    // Chips only carry a section + raw value/id, so an entity chip (label/studio) resolves its
    // display name back out of the last facets response rather than a bucket it never kept.
    function resolveValueName(sectionKey, value, catalogue) {
        const config = FACET_SECTIONS[sectionKey];
        if (config.kind === 'entity') {
            const buckets = (lastFacets && lastFacets[config.facetKey]) || [];
            const bucket = buckets.find((candidate) => String(candidate.id) === value);

            return bucket ? bucket.name : value;
        }

        return nameFromBucket(config, { value }, catalogue);
    }

    function sectionTitle(sectionKey, catalogue) {
        return window.AppTranslations.resolveKey(catalogue, `anime_list.filter_section_${sectionKey}`);
    }

    function isValueApplied(sectionKey, value) {
        return sectionKey === 'date_premiere'
            ? appliedFilters.date_premiere === value
            : appliedFilters[sectionKey].has(value);
    }

    function isValuePending(sectionKey, value) {
        return sectionKey === 'date_premiere'
            ? pendingFilters.date_premiere === value
            : pendingFilters[sectionKey].has(value);
    }

    function handlePendingToggle(sectionKey, value, checked, inputType) {
        if (inputType === 'radio') {
            pendingFilters.date_premiere = value;
        } else if (checked) {
            pendingFilters[sectionKey].add(value);
        } else {
            pendingFilters[sectionKey].delete(value);
        }
        updateApplyButtonState();
    }

    function updateApplyButtonState() {
        filterApplyButton.disabled = isFiltersEmpty(pendingFilters);
    }

    // Applies the whole pending accumulation, not just the one value that was clicked — a label
    // click is a shortcut for "check this box, then press Apply", so any other box already
    // checked elsewhere in the panel is applied together with it rather than discarded.
    function applyPending() {
        appliedFilters = cloneFilters(pendingFilters);
        refreshChips();
        loadPage(0, true, true);
        loadFacets();
    }

    function uncheckValueInput(sectionKey, value) {
        const sectionEl = document.querySelector(`[data-filter-section="${sectionKey}"]`);
        if (!sectionEl) {
            return;
        }
        const row = Array.from(sectionEl.querySelectorAll('.anime-list__filter-value'))
            .find((item) => item.dataset.value === value);
        const input = row ? row.querySelector('.anime-list__filter-checkbox') : null;
        if (input) {
            input.checked = false;
        }
    }

    function removeAppliedValue(sectionKey, value) {
        if (sectionKey === 'date_premiere') {
            appliedFilters.date_premiere = null;
            if (pendingFilters.date_premiere === value) {
                pendingFilters.date_premiere = null;
            }
        } else {
            appliedFilters[sectionKey].delete(value);
            pendingFilters[sectionKey].delete(value);
        }
        uncheckValueInput(sectionKey, value);
        updateApplyButtonState();
        refreshChips();
        loadPage(0, true, true);
        loadFacets();
    }

    // "Reset all" clears filters only — sort field/direction and pagination mode are untouched
    // module-level state the reset never even references (issue #666).
    function resetAllFilters() {
        appliedFilters = createEmptyFilters();
        pendingFilters = createEmptyFilters();
        document.querySelectorAll('.anime-list__filter-checkbox').forEach((input) => {
            input.checked = false;
        });
        updateApplyButtonState();
        refreshChips();
        loadPage(0, true, true);
        loadFacets();
    }

    function buildValueRow(entry, sectionKey, inputType, catalogue) {
        const row = document.createElement('li');
        row.className = 'anime-list__filter-value';
        row.dataset.value = entry.id;

        const checkbox = document.createElement('input');
        checkbox.type = inputType;
        checkbox.className = 'anime-list__filter-checkbox';
        if (inputType === 'radio') {
            checkbox.name = `anime-list-filter-${sectionKey}`;
        }
        checkbox.checked = entry.pending;
        checkbox.setAttribute('aria-label', entry.name);
        checkbox.title = window.AppTranslations.resolveKey(catalogue, 'anime_list.filter_value_accumulate_hint');
        checkbox.addEventListener('change', () => {
            handlePendingToggle(sectionKey, entry.id, checkbox.checked, inputType);
        });

        const nameButton = document.createElement('button');
        nameButton.type = 'button';
        nameButton.className = 'anime-list__filter-value-name';
        nameButton.textContent = entry.name;
        nameButton.title = window.AppTranslations.resolveKey(catalogue, 'anime_list.filter_value_instant_hint');
        nameButton.addEventListener('click', () => {
            checkbox.checked = true;
            handlePendingToggle(sectionKey, entry.id, true, inputType);
            applyPending();
        });

        const count = document.createElement('span');
        count.className = 'anime-list__filter-value-count';
        count.textContent = String(entry.count);

        row.append(checkbox, nameButton, count);
        row.classList.toggle('anime-list__filter-value--applied', entry.applied);

        return row;
    }

    function updateValueRow(row, entry) {
        row.querySelector('.anime-list__filter-value-count').textContent = String(entry.count);
        row.querySelector('.anime-list__filter-value-name').textContent = entry.name;
        row.classList.toggle('anime-list__filter-value--applied', entry.applied);
        // The checkbox/radio's checked state is deliberately left untouched here: it reflects
        // pendingFilters, which this patch (a facets refresh) never changes on its own — only a
        // direct user action on that exact input does (issue #666).
    }

    // Reconciles the section's <ul> against the latest bucket list without ever calling
    // replaceChildren() on it — a full teardown would tear down existing row nodes (and any focus
    // resting on one of them) even though nothing about them changed. Checked-but-not-yet-applied
    // checkboxes survive either way, since buildValueRow() restores checkbox.checked from
    // entry.pending; the panel's own scrollTop lives on .anime-list__filter-sections, a container
    // this function never touches (issue #666).
    function patchValueList(list, entries, sectionKey, inputType, catalogue) {
        const existingByValue = new Map();
        Array.from(list.children).forEach((row) => existingByValue.set(row.dataset.value, row));

        const seen = new Set();
        let previousNode = null;
        entries.forEach((entry) => {
            seen.add(entry.id);
            let row = existingByValue.get(entry.id);
            if (row) {
                updateValueRow(row, entry);
            } else {
                row = buildValueRow(entry, sectionKey, inputType, catalogue);
            }

            const afterNode = previousNode ? previousNode.nextSibling : list.firstChild;
            if (afterNode !== row) {
                list.insertBefore(row, afterNode);
            }
            previousNode = row;
        });

        existingByValue.forEach((row, value) => {
            if (!seen.has(value)) {
                row.remove();
            }
        });
    }

    function renderFilterPanel(data, catalogue) {
        Object.keys(FACET_SECTIONS).forEach((sectionKey) => {
            const config = FACET_SECTIONS[sectionKey];
            const sectionEl = document.querySelector(`[data-filter-section="${sectionKey}"]`);
            if (!sectionEl) {
                return;
            }
            const list = sectionEl.querySelector('.anime-list__filter-values');
            const emptyText = sectionEl.querySelector('.anime-list__filter-section-empty');

            let buckets = data[config.facetKey] || [];
            if (config.kind === 'rating') {
                const byValue = new Map(buckets.map((bucket) => [bucket.value, bucket]));
                buckets = RATING_ORDER.filter((value) => byValue.has(value)).map((value) => byValue.get(value));
            }

            emptyText.hidden = buckets.length > 0;

            const entries = buckets.map((bucket) => {
                const id = config.kind === 'entity' ? String(bucket.id) : bucket.value;

                return {
                    id,
                    name: nameFromBucket(config, bucket, catalogue),
                    count: bucket.count,
                    applied: isValueApplied(sectionKey, id),
                    pending: isValuePending(sectionKey, id),
                };
            });

            patchValueList(list, entries, sectionKey, config.input, catalogue);
        });
    }

    function renderChips(catalogue) {
        const entries = appliedFilterEntries();

        chipList.replaceChildren();
        entries.forEach(({ sectionKey, value }) => {
            const name = resolveValueName(sectionKey, value, catalogue);
            const label = `${sectionTitle(sectionKey, catalogue)}: ${name}`;

            const chip = document.createElement('li');
            chip.className = 'anime-list__chip';

            const text = document.createElement('span');
            text.className = 'anime-list__chip-label';
            text.textContent = label;
            chip.appendChild(text);

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'anime-list__chip-remove';
            remove.setAttribute(
                'aria-label',
                window.AppTranslations.resolveKey(catalogue, 'anime_list.filter_chip_remove_button', { label: name }),
            );
            remove.textContent = '×';
            remove.addEventListener('click', () => removeAppliedValue(sectionKey, value));
            chip.appendChild(remove);

            chipList.appendChild(chip);
        });

        chipsResetButton.disabled = entries.length === 0;
        filtersCountBadge.textContent = ` · ${entries.length}`;
        filtersCountBadge.hidden = entries.length === 0;
        updateShownCount(catalogue);
    }

    // Called right after every appliedFilters mutation so the chip row, the "Filters · N" badge
    // and the reset button track the synchronous state that already drives the list request,
    // instead of only updating once the separate, abortable facets fetch happens to resolve
    // (issue #676 review).
    async function refreshChips() {
        const catalogue = await window.AppTranslations.getCatalogue().catch(() => ({}));
        renderChips(catalogue);
    }

    function setupFilterPanel() {
        filterApplyButton.addEventListener('click', applyPending);
        chipsResetButton.addEventListener('click', resetAllFilters);

        document.querySelectorAll('.anime-list__filter-section-toggle').forEach((toggle) => {
            toggle.addEventListener('click', () => {
                const expanded = toggle.getAttribute('aria-expanded') === 'true';
                toggle.setAttribute('aria-expanded', String(!expanded));
                toggle.nextElementSibling.hidden = expanded;
            });
        });

        updateApplyButtonState();
    }

    function setupFiltersToggle() {
        if (!filtersToggleButton) {
            return;
        }

        filtersToggleButton.addEventListener('click', () => {
            const expanded = filtersToggleButton.getAttribute('aria-expanded') === 'true';
            filtersToggleButton.setAttribute('aria-expanded', String(!expanded));
            filtersPanel.hidden = expanded;
        });
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
                loadPage(0, true, true);
                loadFacets();
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
                loadPage(0, true, true);

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
                loadPage(0, true, true);
            }
        });
    }

    function seedFiltersFromUrl() {
        if (!labelFilter) {
            return;
        }
        appliedFilters.labels.add(labelFilter);
        pendingFilters.labels.add(labelFilter);
        // Draws the chip/badge/reset button right away (issue #676 review) instead of leaving
        // them dependent on the first loadFacets() call below, which is a separate, abortable
        // network round-trip that can fail independently of this seeding.
        refreshChips();
    }

    function init() {
        seedFiltersFromUrl();
        setupSearchInput();
        setupSortControls();
        setupFilterPanel();
        setupFiltersToggle();
        setupResizeObserver();
        // ResizeObserver delivers a synthetic initial callback right after observe() (spec
        // behaviour, not a real resize) — seed lastColumnCount now so handleGridResize()
        // treats it as a no-op instead of re-requesting the page it is about to load anyway.
        lastColumnCount = getColumnCount();
        loadPage(0, true, true);
        loadFacets();
        loadCatalogTotal();
    }

    init();
})();
