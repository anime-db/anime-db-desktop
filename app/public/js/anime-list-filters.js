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
    const filtersToggleButton = document.getElementById('anime-list-filters-toggle');
    const filtersPanel = document.getElementById('anime-list-filters');
    const filterApplyButton = document.getElementById('anime-list-filter-apply');
    const chipsResetButton = document.getElementById('anime-list-chips-reset');

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
    }

    function getAppliedFilters() {
        return appliedFilters;
    }

    // Called by the list core once a GET /anime/facets response lands, before the translations
    // catalogue is awaited — so lastFacets is current even if that await is later cut short by an
    // abort (mirrors the original single-function ordering this panel was split out of).
    function setFacetsData(data) {
        lastFacets = data;
    }

    function render(data, catalogue) {
        window.AnimeListFilterRender.renderPanel(data, catalogue, {
            isValueApplied,
            isValuePending,
            onToggle: handlePendingToggle,
            onInstantApply: instantApply,
        });
        renderChipsAndCount(catalogue);
    }

    // `onFiltersChanged` and `refreshShownCount` are provided by the list core (anime-list.js):
    // this panel triggers a reload on every filter change but does not own the request/abort
    // machinery, and it draws the chip row but not the "Shown X of Y" text next to it, which is
    // built from list-core totals (issue #712).
    function init({ onFiltersChanged: onFiltersChangedCallback, refreshShownCount: refreshShownCountCallback }) {
        onFiltersChanged = onFiltersChangedCallback;
        refreshShownCount = refreshShownCountCallback;

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

        if (filtersToggleButton) {
            filtersToggleButton.addEventListener('click', () => {
                const expanded = filtersToggleButton.getAttribute('aria-expanded') === 'true';
                filtersToggleButton.setAttribute('aria-expanded', String(!expanded));
                filtersPanel.hidden = expanded;
            });
        }
    }

    window.AnimeListFilterPanel = {
        init,
        seedFromUrl,
        getAppliedFilters,
        setFacetsData,
        render,
    };
})();
