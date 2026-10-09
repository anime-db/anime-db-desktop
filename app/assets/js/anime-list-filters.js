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

// The catalog filter panel's state and interaction logic (issue #712, split out of
// anime-list.js). Owns appliedFilters, pendingFilters and lastFacets — the three pieces of state
// this panel and only this panel mutates. DOM building (section rows, the chip row) is delegated
// to the stateless window.AnimeListFilterRender; this module supplies that renderer with the
// filter state it needs as parameters and callbacks, never the other way round. Talks to the list
// core (anime-list.js) only through the explicit interface below: init() takes callbacks the core
// provides, render()/setFacetsData() are called by the core once a GET /anime/facets response
// lands, and getAppliedFilters() is read by the core to build the GET /anime and
// GET /anime/facets query strings via window.AnimeListQuery.
(function () {
    // Assigned in init(), not queried here at module-load time (issue #734) — see
    // anime-list-grid.js for the same pattern and its rationale.
    let filtersToggleButton = null;
    let filtersPanel = null;
    let filterApplyButton = null;
    let chipsResetButton = null;

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

    // "Ещё"/search UI state for the four truncatable sections (issue #820), owned here rather
    // than the stateless render module — see anime-list-filter-render.js's renderPanel() doc
    // comment for why: a facets patch triggered by anything other than a direct click on one of
    // these controls (a search debounce keystroke, popstate, another section's apply) must never
    // reset it. Not persisted across a page reload — only section collapse (below) is.
    function createSectionUiState() {
        return {
            labels: { expanded: false },
            genres: { expanded: false },
            themes: { expanded: false },
            studios: { expanded: false, search: '' },
        };
    }
    let sectionUiState = createSectionUiState();

    // Remembers a label/studio id's display name across facets responses (issue #820): a
    // *pending* (checked but not yet applied) entity row has no server-side guarantee the way an
    // applied one now does (AnimeRepository::withGuaranteedEntityBuckets()), so if its bucket
    // disappears from a later, unrelated response, this is the only place its name can still
    // come from — see anime-list-filter-render.js's appendMissingPendingEntries().
    const entityNameCache = { labels: new Map(), studios: new Map() };

    // Which filter-section-toggle buttons are currently collapsed (issue #820), seeded from the
    // server-rendered aria-expanded at init() and persisted in the background to
    // %AppData%/config.json via POST /settings/filter-sections on every toggle click — see
    // persistCollapsedSections() below.
    const collapsedSections = new Set();
    let filterSectionsCsrfToken = null;
    // Guards persistCollapsedSections() below against out-of-order writes: FrankenPHP's worker
    // threads and AppConfigStore's flock only serialize individual writes, they never guarantee
    // the order two concurrent POSTs are applied in, so two clicks in quick succession could have
    // the earlier request's state win in config.json even though it was sent first (issue #820
    // review). Keeping at most one request in flight and re-reading collapsedSections only once
    // that request settles guarantees strictly sequential writes, each carrying the state that was
    // current at send time.
    let persistRequestInFlight = false;
    let persistRequestQueued = false;

    // Provided by the list core at init() — a filter change (apply/remove/reset) must reload the
    // list and refetch facets, but starting that request is the core's job, not this panel's
    // (issue #712).
    let onFiltersChanged = null;
    // Also provided by the list core: the "Shown X of Y" text is core state (catalog/filtered
    // totals) rendered next to the chip row, so redrawing it after a chip change goes through
    // this callback instead of the panel reading the core's totals directly.
    let refreshShownCount = null;

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

    function setsEqual(a, b) {
        return a.size === b.size && Array.from(a).every((value) => b.has(value));
    }

    // Whether applying `pendingFilters` right now would be a no-op (issue #879) — "Отфильтровать"
    // must reflect this, not just whether anything is pending at all: a pending accumulation that
    // only mirrors what is already applied (e.g. right after instantApply() folds a value into
    // both) has nothing left to apply, even though it is not empty.
    function filtersEqual(a, b) {
        return a.date_premiere === b.date_premiere
            && setsEqual(a.watch_status, b.watch_status)
            && setsEqual(a.type, b.type)
            && setsEqual(a.user_rating, b.user_rating)
            && setsEqual(a.labels, b.labels)
            && setsEqual(a.genres, b.genres)
            && setsEqual(a.themes, b.themes)
            && setsEqual(a.studios, b.studios);
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

    // Every id currently pending for a section (issue #820) — used by the render module to spot
    // a pending value whose bucket dropped out of a facets response and needs a synthesized row.
    function pendingIdsFor(sectionKey) {
        if (sectionKey === 'date_premiere') {
            return pendingFilters.date_premiere !== null ? [pendingFilters.date_premiere] : [];
        }

        return Array.from(pendingFilters[sectionKey]);
    }

    // Every id currently applied for a section (issue #820 review) — the section-header count
    // ("Жанры · 2") reads from this rather than from the rendered facets entries, since the
    // server only guarantees a bucket for an applied value narrowed to zero in genres/themes/
    // labels/studios, not in watch_status/type/user_rating/date_premiere.
    function appliedIdsFor(sectionKey) {
        if (sectionKey === 'date_premiere') {
            return appliedFilters.date_premiere !== null ? [appliedFilters.date_premiere] : [];
        }

        return Array.from(appliedFilters[sectionKey]);
    }

    function resolveEntityFallbackName(sectionKey, id) {
        const cache = entityNameCache[sectionKey];

        return (cache && cache.get(id)) || null;
    }

    // Refreshes entityNameCache from a facets response before the panel renders it (issue #820).
    // "labels"/"studios" double as both the filter-section key and the facets response key
    // (same coincidence anime-list-filter-render.js's FACET_SECTIONS documents for facetKey), so
    // no extra mapping is needed between the two.
    function rememberEntityNames(data) {
        Object.keys(entityNameCache).forEach((sectionKey) => {
            (data[sectionKey] || []).forEach((bucket) => {
                entityNameCache[sectionKey].set(String(bucket.id), bucket.name);
            });
        });
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

    // Disabled once pending matches applied — not just when pending is empty (issue #879): a
    // pending accumulation that already mirrors the applied one (right after an apply) has
    // nothing left to apply, even though both can be non-empty.
    function updateApplyButtonState() {
        filterApplyButton.disabled = filtersEqual(pendingFilters, appliedFilters);
    }

    // Applies the whole pending accumulation, not just the one value that was clicked — a label
    // click is a shortcut for "check this box, then press Apply", so any other box already
    // checked elsewhere in the panel is applied together with it rather than discarded.
    function applyPending() {
        appliedFilters = cloneFilters(pendingFilters);
        updateApplyButtonState();
        refreshChips();
        onFiltersChanged();
    }

    // The instant-apply path from a value's name button (issue #666): check it, fold it into the
    // pending accumulation, then apply the whole thing exactly like the Apply button does.
    function instantApply(sectionKey, value, inputType) {
        handlePendingToggle(sectionKey, value, true, inputType);
        applyPending();
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
        onFiltersChanged();
    }

    // "Reset all" clears filters only — sort field/direction and pagination mode are untouched
    // list-core state the reset never even references (issue #666).
    function resetAllFilters() {
        appliedFilters = createEmptyFilters();
        pendingFilters = createEmptyFilters();
        document.querySelectorAll('.anime-list__filter-checkbox').forEach((input) => {
            input.checked = false;
        });
        updateApplyButtonState();
        refreshChips();
        onFiltersChanged();
    }

    // Called right after every appliedFilters mutation so the chip row, the "Filters · N" badge
    // and the reset button track the synchronous state that already drives the list request,
    // instead of only updating once the separate, abortable facets fetch happens to resolve
    // (issue #676 review).
    async function refreshChips() {
        const catalogue = await window.AppTranslations.getCatalogue().catch(() => ({}));
        renderChipsAndCount(catalogue);
    }

    function renderChipsAndCount(catalogue) {
        window.AnimeListFilterRender.renderChips(appliedFilterEntries(), catalogue, {
            lastFacets,
            onRemove: removeAppliedValue,
        });
        refreshShownCount(catalogue);
    }

    // Seeds appliedFilters/pendingFilters from the address bar — at init (issue #697) and, since
    // the catalog now writes its own state back to the URL (issue #713), on every same-document
    // "back"/"forward" via the list core's popstate handler too. This function itself never writes
    // to the URL; the caller decides whether the state it reads back out is worth persisting.
    //
    // `forceRefresh` covers the popstate case: the init call only redraws the chip row when the
    // freshly parsed state is non-empty (issue #676 review) because an empty row is already what
    // the page's initial markup shows — nothing to redraw. A popstate re-seed can go from a
    // filtered state back to an empty one, which the caller must force through instead, or the chip
    // row/badge from the state being left would linger after the URL that produced it is gone.
    function seedFromUrl(urlParams, { forceRefresh = false } = {}) {
        appliedFilters.watch_status = window.AnimeListQuery.parseEnumSectionSet(urlParams, 'watch_status');
        appliedFilters.type = window.AnimeListQuery.parseEnumSectionSet(urlParams, 'type');
        appliedFilters.genres = window.AnimeListQuery.parseEnumSectionSet(urlParams, 'genres');
        appliedFilters.themes = window.AnimeListQuery.parseEnumSectionSet(urlParams, 'themes');
        appliedFilters.studios = window.AnimeListQuery.parseEntityIdSet(urlParams, 'studios');
        appliedFilters.labels = window.AnimeListQuery.parseLabelSet(urlParams);
        appliedFilters.user_rating = window.AnimeListQuery.parseUserRatingSet(urlParams);
        appliedFilters.date_premiere = window.AnimeListQuery.parseDatePremiereFilter(urlParams);
        pendingFilters = cloneFilters(appliedFilters);

        if (forceRefresh || !isFiltersEmpty(appliedFilters)) {
            refreshChips();
        }
        // pendingFilters was just reset to equal appliedFilters, so the apply button's disabled
        // state (issue #879) needs recomputing too — otherwise a popstate landing on a URL that
        // matches the already-applied filters leaves a stale enabled button that fires an empty
        // reload/facets/pushState cycle on click.
        updateApplyButtonState();
    }

    function getAppliedFilters() {
        return appliedFilters;
    }

    // Called by the list core once a GET /anime/facets response lands, before the translations
    // catalogue is awaited — so lastFacets is current even if that await is later cut short by an
    // abort (mirrors the original single-function ordering this panel was split out of).
    function setFacetsData(data) {
        lastFacets = data;
        rememberEntityNames(data);
    }

    // The studios search box only exists once its section has more than
    // AnimeListFilterRender.STUDIOS_SEARCH_MIN_VALUES values (issue #820); once a fresher
    // response drops back to that count or fewer, the box disappears and any query typed into it
    // is dropped too, so it never resurfaces stale once the section grows past the threshold
    // again later.
    function resetSearchIfSectionShrank(data) {
        const count = (data.studios || []).length;
        if (count <= window.AnimeListFilterRender.STUDIOS_SEARCH_MIN_VALUES) {
            sectionUiState.studios.search = '';
        }
    }

    function toggleSectionMore(sectionKey) {
        sectionUiState[sectionKey].expanded = !sectionUiState[sectionKey].expanded;
        rerenderFromLastFacets();
    }

    function handleSectionSearchInput(sectionKey, value) {
        sectionUiState[sectionKey].search = value;
        rerenderFromLastFacets();
    }

    // Re-patches the panel from the already-held facets response (issue #820) — used by the
    // "Ещё"/search controls above, neither of which changes the filter itself, so re-fetching
    // GET /anime/facets for them would be wasted network I/O.
    async function rerenderFromLastFacets() {
        if (!lastFacets) {
            return;
        }
        const catalogue = await window.AppTranslations.getCatalogue().catch(() => ({}));
        window.AnimeListFilterRender.renderPanel(lastFacets, catalogue, filterRenderHooks(), sectionUiState);
    }

    function filterRenderHooks() {
        return {
            isValueApplied,
            isValuePending,
            onToggle: handlePendingToggle,
            onInstantApply: instantApply,
            onToggleMore: toggleSectionMore,
            onSearchInput: handleSectionSearchInput,
            pendingIdsFor,
            appliedIdsFor,
            resolveEntityFallbackName,
        };
    }

    function render(data, catalogue) {
        resetSearchIfSectionShrank(data);
        window.AnimeListFilterRender.renderPanel(data, catalogue, filterRenderHooks(), sectionUiState);
        renderChipsAndCount(catalogue);
    }

    // Saves which sections are collapsed to %AppData%/config.json in the background (issue #820)
    // — fired on every section-toggle click, no page reload, mirroring the JSON+CSRF-in-body
    // shape AnimeLabelController/anime-detail.js already use rather than the form-field CSRF the
    // rest of SettingsController's endpoints take, since there is no form here. Best-effort: a
    // failed save only means the collapse state does not survive the next launch, the toggle
    // itself already applied in the DOM regardless.
    function persistCollapsedSections() {
        if (!filterSectionsCsrfToken) {
            return;
        }

        if (persistRequestInFlight) {
            persistRequestQueued = true;

            return;
        }

        persistRequestInFlight = true;
        fetch('/settings/filter-sections', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token: filterSectionsCsrfToken, collapsed: Array.from(collapsedSections) }),
        }).catch(() => {
            // Best-effort: a failed save only means the collapse state does not survive the next
            // launch — the toggle itself already applied in the DOM regardless (issue #820).
        }).finally(() => {
            persistRequestInFlight = false;
            if (persistRequestQueued) {
                persistRequestQueued = false;
                persistCollapsedSections();
            }
        });
    }

    // `onFiltersChanged` and `refreshShownCount` are provided by the list core (anime-list.js):
    // this panel triggers a reload on every filter change but does not own the request/abort
    // machinery, and it draws the chip row but not the "Shown X of Y" text next to it, which is
    // built from list-core totals (issue #712).
    function init(root, { onFiltersChanged: onFiltersChangedCallback, refreshShownCount: refreshShownCountCallback }) {
        filtersToggleButton = root.querySelector('#anime-list-filters-toggle');
        filtersPanel = root.querySelector('#anime-list-filters');
        filterApplyButton = root.querySelector('#anime-list-filter-apply');
        chipsResetButton = root.querySelector('#anime-list-chips-reset');
        onFiltersChanged = onFiltersChangedCallback;
        refreshShownCount = refreshShownCountCallback;

        window.AnimeListFilterRender.init(root);

        // Reset on every (re)mount, not just declared at module scope (issue #820): a remount
        // (see mountAnimeList()'s unmount/remount symmetry in anime-list.js) must not carry a
        // previous instance's expanded/search UI state or cached entity names into the fresh DOM.
        sectionUiState = createSectionUiState();
        Object.values(entityNameCache).forEach((cache) => cache.clear());

        filterApplyButton.addEventListener('click', applyPending);
        chipsResetButton.addEventListener('click', resetAllFilters);

        const sectionsContainer = root.querySelector('#anime-list-filter-sections');
        filterSectionsCsrfToken = sectionsContainer ? sectionsContainer.dataset.csrfToken : null;
        collapsedSections.clear();
        persistRequestInFlight = false;
        persistRequestQueued = false;

        root.querySelectorAll('.anime-list__filter-section-toggle').forEach((toggle) => {
            const sectionKey = toggle.closest('[data-filter-section]').dataset.filterSection;
            if (toggle.getAttribute('aria-expanded') === 'false') {
                collapsedSections.add(sectionKey);
            }

            toggle.addEventListener('click', () => {
                const expanded = toggle.getAttribute('aria-expanded') === 'true';
                toggle.setAttribute('aria-expanded', String(!expanded));
                toggle.nextElementSibling.hidden = expanded;

                if (expanded) {
                    collapsedSections.add(sectionKey);

                    // Collapsing a truncatable section via its header resets its "Ещё" state
                    // (issue #821 review), since the button no longer offers a way back to the
                    // top-8 view on its own — the next expand shows top-8 + "Ещё (k)" again.
                    if (sectionUiState[sectionKey] && sectionUiState[sectionKey].expanded) {
                        sectionUiState[sectionKey].expanded = false;
                        rerenderFromLastFacets();
                    }
                } else {
                    collapsedSections.delete(sectionKey);
                }
                persistCollapsedSections();
            });
        });

        updateApplyButtonState();

        if (filtersToggleButton) {
            filtersToggleButton.addEventListener('click', () => {
                const expanded = filtersToggleButton.getAttribute('aria-expanded') === 'true';
                filtersToggleButton.setAttribute('aria-expanded', String(!expanded));
                filtersPanel.hidden = expanded;
            });
        }
    }

    // Symmetric with init() (issue #734, same pattern as AnimeListGrid.destroy()): nothing outside
    // this control's own subtree is held any more, so there is nothing to release.
    function destroy() {}

    window.AnimeListFilterPanel = {
        init,
        destroy,
        seedFromUrl,
        getAppliedFilters,
        setFacetsData,
        render,
    };
})();
