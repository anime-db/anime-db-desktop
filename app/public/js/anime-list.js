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

// The catalog list core (issue #712, split out of what used to be a single anime-list.js):
// request/abort orchestration for GET /anime and GET /anime/facets, search, sort and URL
// seeding. Owns every piece of state this module and only this module mutates; card rendering
// and pagination live in window.AnimeListGrid, the filter panel's own state (appliedFilters,
// pendingFilters, lastFacets) lives in window.AnimeListFilterPanel, and request/URL building
// comes from the DOM-free window.AnimeListQuery.
(function () {
    // The catalog keeps the address bar in sync with its own state (issue #713): every filter
    // change, search keystroke and sort choice is written back via pushUrlState()/replaceUrlState()
    // below, using the same param shapes window.AnimeListQuery.appendFilterParams()/
    // buildListQuery() themselves produce. The same URL is read back both at init
    // (seedFiltersFromUrl(), issue #697) and on a same-document "back"/"forward" via the popstate
    // handler (handlePopState()), so a link this page built (a label click on the anime detail
    // page, issue #104, a card navigating to /anime/{id} and back, or the panel's own request)
    // reopens the same state it came from.
    // Debounce the search box (issue #199) so a full request isn't fired on every keystroke —
    // AnimeListController resolves this as "name" against Meilisearch, falling back to the
    // FTS5 quick-filter server-side when it is unavailable.
    const SEARCH_DEBOUNCE_MS = 300;
    // Seeding (both at init and on popstate) falls back to these once a param is absent from the
    // URL — sortField/sortDirection below start here too, so the two never drift apart (issue
    // #713).
    const DEFAULT_SORT_FIELD = 'date_update';
    const DEFAULT_SORT_DIRECTION = 'desc';

    // Assigned in mountAnimeList(), not queried here at module-load time (issue #734): the
    // "anime-list" control mounts on htmx:load, not at script-parse time.
    let errorMessage = null;
    let searchInput = null;
    let sortContainer = null;
    let sortDirectionButton = null;
    let chipsShown = null;

    let searchDebounceTimer = null;
    let searchQuery = '';
    let sortField = DEFAULT_SORT_FIELD;
    let sortDirection = DEFAULT_SORT_DIRECTION;
    // Guards against the response race (issue #208): a slow scroll-append response arriving
    // after a faster search response would otherwise splice stale cards into the fresh grid.
    let pendingRequest = null;
    // The facets request (issue #666) has its own AbortController, independent of the list
    // request above: it must fire on every filter change but never on an infinite-scroll
    // page-append, which pendingRequest above already tracks separately.
    let pendingFacetsRequest = null;
    // Total matches under the current filter — from the list response, refreshed on every
    // loadPage() call (including scroll-appends, since the filtered total does not change
    // mid-scroll). Used as the numerator of "Shown X of Y".
    let currentFilteredTotal = 0;
    // Unfiltered catalog size, refreshed from every GET /anime/facets response (issue #688) —
    // the denominator of "Shown X of Y" answers "why are there so few records" only when
    // compared against the whole catalog, not the current page, and must not go stale across a
    // session as records are added, removed, imported or synced.
    let catalogTotal = null;

    async function fetchPage(offset, limit, signal) {
        const query = window.AnimeListQuery.buildListQuery({
            offset,
            limit,
            sortField,
            sortDirection,
            searchQuery,
            filters: window.AnimeListFilterPanel.getAppliedFilters(),
        });
        const response = await fetch(query, { signal });
        if (!response.ok) {
            throw new Error(`Anime list request failed with status ${response.status}`);
        }

        return response.json();
    }

    // Renders the "Shown X of Y" text next to the chip row. Passed to the filter panel as the
    // `refreshShownCount` callback (issue #712) since the totals it reads (catalogTotal,
    // currentFilteredTotal) are list-core state, not the panel's.
    function updateShownCount(catalogue) {
        const total = catalogTotal !== null ? catalogTotal : currentFilteredTotal;
        chipsShown.textContent = window.AppTranslations.resolveKey(catalogue, 'anime_list.filter_shown_count', {
            shown: currentFilteredTotal,
            total,
        });
    }

    // `isNewQuery` tells AnimeListGrid.renderPage() whether this call is fetching a genuinely new
    // result set (search, sort, filter change, "reset all", or a jump to page 1) as opposed to
    // re-fetching the same result set at a different window (a column-count requery, an
    // infinite-scroll top-up, or paging to any page other than the first) — issue #687.
    //
    // `limitOverride` is used for exactly one caller: the infinite-scroll top-up after a resize,
    // which asks for only the handful of records needed to complete the last row rather than a
    // full page (issue #665). Every other caller lets the limit follow the grid's current column
    // count. The offset for the *next* request always comes back from the response (`data.limit`,
    // via AnimeListGrid.setupPagination), never from what this call sent — the server is free to
    // clamp.
    async function loadPage(offset, replace, isNewQuery, limitOverride) {
        window.AnimeListGrid.disconnectSentinel();
        errorMessage.hidden = true;

        if (pendingRequest) {
            pendingRequest.abort();
        }
        const controller = new AbortController();
        pendingRequest = controller;

        const requestColumns = window.AnimeListGrid.getColumnCount();
        const limit = limitOverride !== undefined ? limitOverride : window.AnimeListGrid.computeLimit(requestColumns);

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

        window.AnimeListGrid.renderPage(data, replace, isNewQuery, catalogue);
        currentFilteredTotal = data.total;
        updateShownCount(catalogue);

        window.AnimeListGrid.setupPagination(data);
        window.AnimeListGrid.reconcileColumnCount(requestColumns);
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

        const query = window.AnimeListQuery.buildFacetsQuery({
            searchQuery,
            filters: window.AnimeListFilterPanel.getAppliedFilters(),
        });

        let data;
        try {
            const response = await fetch(query, { signal: controller.signal });
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

        catalogTotal = data.catalog_total;
        window.AnimeListFilterPanel.setFacetsData(data);
        const catalogue = await window.AppTranslations.getCatalogue().catch(() => ({}));
        if (controller.signal.aborted) {
            return;
        }

        // Also redraws the chip row (issue #676 review): it only upgrades an entity chip
        // (label/studio) from a raw id fallback to its resolved display name once the panel's
        // lastFacets carries that name; a failed or superseded facets request just leaves that
        // upgrade for the next successful one.
        window.AnimeListFilterPanel.render(data, catalogue);
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
                // replaceState, not pushState (issue #713): the debounce above already collapses a
                // typed word into one call per pause, but pushState here would still turn every
                // *paused* keystroke into its own history entry, and "back" could never cleanly
                // unwind a word typed one debounce-pause at a time. This overwrites the same entry
                // the previous debounced call already wrote instead.
                replaceUrlState();
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

    // Shared by the direction-toggle click handler and the URL seeding below (issue #697), so
    // the button's arrow/label/dataset stay in one place instead of drifting between the two
    // call sites.
    function updateSortDirectionButton() {
        if (!sortDirectionButton) {
            return;
        }
        sortDirectionButton.dataset.direction = sortDirection;
        sortDirectionButton.textContent = sortDirection === 'desc' ? '↓' : '↑';
        sortDirectionButton.setAttribute(
            'aria-label',
            sortDirectionButton.dataset[sortDirection === 'desc' ? 'labelDesc' : 'labelAsc'],
        );
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
                // pushState (issue #713): a sort change is a single discrete action, not a stream
                // of keystrokes, so "back" undoing it one step at a time is the useful behaviour
                // here — the same reasoning a filter apply/remove/reset gets below.
                pushUrlState();

                return;
            }

            if (sortDirectionButton && event.target.closest('#anime-list-sort-direction')) {
                sortDirection = sortDirection === 'desc' ? 'asc' : 'desc';
                updateSortDirectionButton();
                loadPage(0, true, true);
                pushUrlState();
            }
        });
    }

    // The sort fields window.AnimeListQuery.parseSortField() is allowed to accept from the URL —
    // read off the sort buttons actually present rather than a hardcoded field list, so this
    // never drifts from list.html.twig (issue #697).
    function knownSortFields() {
        if (!sortContainer) {
            return null;
        }

        return Array.from(sortContainer.querySelectorAll('[data-sort-field]')).map((button) => button.dataset.sortField);
    }

    // Reseeds every piece of state this module owns from `params` — a full reset, not just an
    // overlay of whatever `params` happens to contain, so this is equally correct called once at
    // init (where every field already starts at its default) and repeatedly from the popstate
    // handler below (issue #713), where a field present in the *previous* URL but absent from the
    // new one must fall back to its default rather than keep its old value.
    function seedFiltersFromUrl(params, { forceRefresh = false } = {}) {
        window.AnimeListFilterPanel.seedFromUrl(params, { forceRefresh });

        const name = params.get('name');
        searchQuery = name && name.trim() !== '' ? name.trim() : '';
        if (searchInput) {
            searchInput.value = searchQuery;
        }

        const sortFieldFromUrl = window.AnimeListQuery.parseSortField(params, knownSortFields());
        sortField = sortFieldFromUrl || DEFAULT_SORT_FIELD;
        updateSortFieldButtons();

        const sortDirectionFromUrl = window.AnimeListQuery.parseSortDirection(params);
        sortDirection = sortDirectionFromUrl || DEFAULT_SORT_DIRECTION;
        updateSortDirectionButton();
    }

    // The address-bar URL for the catalog's current state (issue #713), built through the pure
    // window.AnimeListQuery.buildStateQuery() so anything written here re-parses through
    // seedFiltersFromUrl() above without any param-shape change.
    function currentStateUrl() {
        const query = window.AnimeListQuery.buildStateQuery({
            sortField,
            sortDirection,
            searchQuery,
            filters: window.AnimeListFilterPanel.getAppliedFilters(),
        });

        return `${window.location.pathname}${query}`;
    }

    function pushUrlState() {
        window.history.pushState(null, '', currentStateUrl());
    }

    function replaceUrlState() {
        window.history.replaceState(null, '', currentStateUrl());
    }

    // Reacts to a same-document "back"/"forward" landing on a URL this module itself wrote via
    // pushUrlState()/replaceUrlState() (issue #713) — a filter/sort/search change from earlier in
    // this session, not just the once-at-init seeding seedFiltersFromUrl() was originally written
    // for (issue #697). Reseeds every piece of state and reloads the list/facets, but must never
    // call pushUrlState()/replaceUrlState() itself: the browser already moved the history pointer,
    // and writing to it again here would fight that traversal instead of following it.
    function handlePopState() {
        // Cancels any in-flight search debounce (issue #717 review): without this, a "back"
        // landing within SEARCH_DEBOUNCE_MS of the last keystroke lets that stale timer fire after
        // seedFiltersFromUrl() below already reseeded the state, triggering a redundant
        // loadPage()/loadFacets()/replaceUrlState() call on top of the one this handler already
        // makes.
        clearTimeout(searchDebounceTimer);
        seedFiltersFromUrl(new URLSearchParams(window.location.search), { forceRefresh: true });
        loadPage(0, true, true);
        loadFacets();
    }

    // Mounts the whole catalog page as a single "anime-list" control (issue #734, which also
    // absorbed issue #729's concern about the five <script> tags' load order): htmx:load itself
    // fires on <body> once the initial document is ready, in addition to firing on every later
    // htmx-inserted fragment, so this one registerControl() replaces both the old
    // DOMContentLoaded/readyState bootstrap and the order dependency between this file and the
    // four helper modules it calls into below — none of them run anything until this control
    // actually mounts.
    function mountAnimeList(root) {
        errorMessage = root.querySelector('#anime-list-error');
        searchInput = root.querySelector('#anime-list-search');
        sortContainer = root.querySelector('#anime-list-sort');
        sortDirectionButton = root.querySelector('#anime-list-sort-direction');
        chipsShown = root.querySelector('#anime-list-chips-shown');

        window.AnimeListGrid.init(root, { requestPage: loadPage });
        window.AnimeListFilterPanel.init(root, {
            // A filter change (apply/remove/reset) must reload the list and refetch facets —
            // starting that request belongs to this module, not the filter panel (issue #712).
            onFiltersChanged: () => {
                loadPage(0, true, true);
                loadFacets();
                // pushState (issue #713): applying/removing a filter or resetting them all is a
                // single discrete action a user thinks of as one step, same as a sort change above.
                pushUrlState();
            },
            refreshShownCount: updateShownCount,
        });

        // Seeds every piece of state from the URL the page opened with, but never writes back —
        // pushUrlState()/replaceUrlState() only ever run from an interactive handler above, so the
        // page opening at /anime with no params stays exactly that until the first user action
        // (issue #713).
        seedFiltersFromUrl(new URLSearchParams(window.location.search));
        setupSearchInput();
        setupSortControls();
        // Deduped on window rather than added unconditionally (issue #734, same concern the old
        // pre-registry code already had to handle): the popstate listener lives outside this
        // control's own subtree, so nothing but this mount/unmount pair itself can be relied on to
        // keep exactly one of them attached — a page that somehow mounts this control a second
        // time without the first instance's unmount ever running (a remount whose predecessor
        // skipped htmx:beforeCleanupElement for any reason) would otherwise double every reload/
        // facets fetch a single popstate fires, same as the double-<script>-tag bug this guarded
        // against before the registry existed.
        if (window.__animeListPopStateHandler) {
            window.removeEventListener('popstate', window.__animeListPopStateHandler);
        }
        window.__animeListPopStateHandler = handlePopState;
        window.addEventListener('popstate', handlePopState);
        window.AnimeListGrid.seedColumnCount();
        loadPage(0, true, true);
        loadFacets();

        // Symmetric demount (issue #734): the popstate listener lives on window, outside this
        // control's own subtree, so a beforeCleanupElement on the root would never reach it on its
        // own — and both in-flight requests plus the grid's ResizeObserver/IntersectionObserver
        // need to stop before a fresh mount's own loadPage()/AnimeListGrid.init() replace them,
        // or a stale response from this instance could still land after a remount.
        return function unmountAnimeList() {
            if (window.__animeListPopStateHandler === handlePopState) {
                window.__animeListPopStateHandler = null;
            }
            window.removeEventListener('popstate', handlePopState);
            clearTimeout(searchDebounceTimer);
            if (pendingRequest) {
                pendingRequest.abort();
            }
            if (pendingFacetsRequest) {
                pendingFacetsRequest.abort();
            }
            window.AnimeListGrid.destroy();
        };
    }

    window.Controller.registerControl('anime-list', mountAnimeList);
})();
