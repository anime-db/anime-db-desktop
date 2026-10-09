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

// DOM building for the filter panel (issue #712, split out of the filter-panel module): section
// value rows, the in-place patch, and the chip row. Holds no filter state of its own — every
// value it needs (which entries are applied/pending, the last facets response) arrives as a
// parameter or a callback from window.AnimeListFilterPanel, which owns that state.
(function () {
    // The eight filter-panel sections (issue #666), in the fixed display order the issue
    // requires. Each key doubles as the property name on the filters state objects in
    // anime-list-filters.js and as the `data-filter-section` attribute in list.html.twig, so a
    // section only has to be named once. `facetKey` is the matching property on the
    // GET /anime/facets response.
    const FACET_SECTIONS = {
        watch_status: { facetKey: 'watch_status', kind: 'enum', translatePrefix: 'watch_status', input: 'checkbox' },
        type: { facetKey: 'type', kind: 'enum', translatePrefix: 'anime_type', input: 'checkbox' },
        date_premiere: { facetKey: 'date_premiere_decade', kind: 'decade', input: 'radio' },
        user_rating: { facetKey: 'user_rating', kind: 'rating', input: 'checkbox' },
        labels: { facetKey: 'labels', kind: 'entity', input: 'checkbox', truncatable: true },
        genres: { facetKey: 'genres', kind: 'enum', translatePrefix: 'genre', input: 'checkbox', truncatable: true },
        themes: { facetKey: 'themes', kind: 'enum', translatePrefix: 'theme', input: 'checkbox', truncatable: true },
        studios: { facetKey: 'studios', kind: 'entity', input: 'checkbox', truncatable: true, searchable: true },
    };

    // Truncation (issue #820): a truncatable section shows only its top TRUNCATE_TOP_COUNT
    // unchecked values plus every applied/pending one (always pinned, never counted against the
    // limit) once more than TRUNCATE_MIN_UNCHECKED values would otherwise be hidden — a
    // remainder of 1-2 stays flat rather than being tucked behind a button for almost nothing.
    const TRUNCATE_TOP_COUNT = 8;
    const TRUNCATE_MIN_UNCHECKED = 10;
    // The studios search box (the only searchable section) only appears once the current
    // response actually has enough rows to be worth searching — exported so
    // anime-list-filters.js can reset its stored query using the exact same threshold once a
    // narrower response drops back under it, instead of duplicating the number.
    const STUDIOS_SEARCH_MIN_VALUES = 20;

    // Assigned in init(), not queried here at module-load time (issue #734) — see
    // anime-list-grid.js for the same pattern and its rationale.
    let chipList = null;
    let chipsResetButton = null;
    let filtersCountBadge = null;

    function init(root) {
        chipList = root.querySelector('#anime-list-chip-list');
        chipsResetButton = root.querySelector('#anime-list-chips-reset');
        filtersCountBadge = root.querySelector('#anime-list-filters-count');
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
    function resolveValueName(sectionKey, value, catalogue, lastFacets) {
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

    // Count-desc, then display-name-asc via localeCompare in the interface locale (issue #820) —
    // <html lang>, the same source translations.js itself reads the catalogue's own locale from,
    // so this never drifts from what the page is actually rendered in.
    function sortEntries(entries) {
        const locale = document.documentElement.lang || undefined;

        return entries.slice().sort((a, b) => {
            if (b.count !== a.count) {
                return b.count - a.count;
            }

            return a.name.localeCompare(b.name, locale);
        });
    }

    function isPinned(entry) {
        return entry.applied || entry.pending;
    }

    // The section-toggle header shows the applied count next to the label (issue #820, "Жанры ·
    // 2") so a collapsed section with a filter still active is legible without expanding it —
    // same " · N" shape as the top-bar filtersCountBadge below. Reads the count from the panel's
    // own applied-filter state (hooks.appliedIdsFor), not from the rendered entries: the server
    // only guarantees a bucket for an applied genre/theme/label/studio narrowed to zero elsewhere
    // (AnimeRepository::withGuaranteedValueBuckets()/withGuaranteedEntityBuckets()) — watch_status,
    // type, user_rating and date_premiere have no such guarantee (GROUP BY never returns an empty
    // group), so an applied value there that a sibling filter narrows to zero would otherwise drop
    // out of entries and silently hide the very count this exists to keep visible.
    function updateSectionHeaderCount(sectionEl, sectionKey, hooks) {
        const countEl = sectionEl.querySelector('.anime-list__filter-section-count');
        if (!countEl) {
            return;
        }

        const appliedCount = hooks.appliedIdsFor(sectionKey).length;
        countEl.textContent = ` · ${appliedCount}`;
        countEl.hidden = appliedCount === 0;
    }

    function buildValueRow(entry, sectionKey, inputType, catalogue, hooks) {
        const row = document.createElement('li');
        row.className = 'anime-list__filter-value';
        row.dataset.value = entry.id;

        const checkbox = document.createElement('input');
        checkbox.type = inputType;
        checkbox.className = 'form-check-input anime-list__filter-checkbox';
        if (inputType === 'radio') {
            checkbox.name = `anime-list-filter-${sectionKey}`;
        }
        checkbox.checked = entry.pending;
        checkbox.setAttribute('aria-label', entry.name);
        checkbox.title = window.AppTranslations.resolveKey(catalogue, 'anime_list.filter_value_accumulate_hint');
        checkbox.addEventListener('change', () => {
            hooks.onToggle(sectionKey, entry.id, checkbox.checked, inputType);
        });

        const nameButton = document.createElement('button');
        nameButton.type = 'button';
        nameButton.className = 'anime-list__filter-value-name';
        nameButton.textContent = entry.name;
        nameButton.title = window.AppTranslations.resolveKey(catalogue, 'anime_list.filter_value_instant_hint');
        nameButton.addEventListener('click', () => {
            checkbox.checked = true;
            hooks.onInstantApply(sectionKey, entry.id, inputType);
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
    function patchValueList(list, entries, sectionKey, inputType, catalogue, hooks) {
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
                row = buildValueRow(entry, sectionKey, inputType, catalogue, hooks);
            }

            const afterNode = previousNode ? previousNode.nextSibling : list.firstChild;
            if (afterNode !== row) {
                // insertBefore() on a node that already sits elsewhere in this same list moves it
                // rather than cloning it, but Electron still blurs focus resting inside it during
                // that move — restore it on the same element once the move is done, or an
                // instant-apply click on a value below the top row silently drops keyboard focus
                // to <body> (issue #820 review).
                const focused = row.contains(document.activeElement) ? document.activeElement : null;
                list.insertBefore(row, afterNode);
                if (focused) {
                    focused.focus();
                }
            }
            previousNode = row;
        });

        existingByValue.forEach((row, value) => {
            if (!seen.has(value)) {
                row.remove();
            }
        });
    }

    // A pending value (checked but not yet applied, issue #666) is never sent to GET
    // /anime/facets, so the server can never guarantee it a bucket the way it now does for
    // applied values (issue #820 point 3). If a later, unrelated facets refresh (another
    // section's apply, a search debounce, popstate) drops that bucket, this synthesizes a
    // count: 0 row from what the hooks can still resolve without one: an enum/rating/decade name
    // is always derivable from the value's own code, but an entity (label/studio) name only
    // survives via hooks.resolveEntityFallbackName()'s memory of an earlier response — see
    // AnimeListFilterPanel.entityNameCache.
    function appendMissingPendingEntries(entries, sectionKey, config, catalogue, hooks) {
        const present = new Set(entries.map((entry) => entry.id));
        hooks.pendingIdsFor(sectionKey).forEach((id) => {
            if (present.has(id)) {
                return;
            }

            const name = config.kind === 'entity'
                ? hooks.resolveEntityFallbackName(sectionKey, id)
                : nameFromBucket(config, { value: id }, catalogue);
            if (!name) {
                return;
            }

            entries.push({
                id,
                name,
                count: 0,
                applied: hooks.isValueApplied(sectionKey, id),
                pending: true,
            });
        });
    }

    // Renders/updates a truncatable section's search box (studios only, config.searchable) and
    // reports whether it is currently narrowing the list — the search box element itself is
    // static markup (list.html.twig), only its hidden/value/handler are ever touched, so it never
    // loses focus across a patch the way rebuilding it every render would.
    function updateSearchBox(sectionEl, sectionKey, config, buckets, state, hooks) {
        const searchBox = sectionEl.querySelector('.anime-list__filter-section-search');
        if (!searchBox) {
            return false;
        }
        if (!config.searchable) {
            searchBox.hidden = true;

            return false;
        }

        const searchInput = searchBox.querySelector('.anime-list__filter-section-search-input');
        const showSearch = buckets.length > STUDIOS_SEARCH_MIN_VALUES;
        searchBox.hidden = !showSearch;
        if (!showSearch) {
            // The stored query (state.search) is reset by the panel itself once the response
            // shrinks (AnimeListFilterPanel.resetSearchIfSectionShrank()) — this mirrors that
            // reset onto the hidden input's own DOM value so a stale query is not still sitting
            // there, unseen, if the section ever grows back past the threshold.
            searchInput.value = '';

            return false;
        }

        // Never overwrites the input while the user is actively typing in it — setting .value
        // to the same string it already holds is harmless, but re-syncing mid-keystroke from a
        // state update this same keystroke just triggered is not worth the risk of moving the
        // caret in a browser that doesn't no-op an identical assignment.
        if (document.activeElement !== searchInput) {
            searchInput.value = state.search || '';
        }
        searchInput.oninput = () => hooks.onSearchInput(sectionKey, searchInput.value);

        return (state.search || '').trim() !== '';
    }

    // No collapse-back control inside the section (issue #821 review): once "Ещё" is clicked the
    // button hides for good — the only way back to the top-8 view is collapsing the section itself
    // via its header toggle, which resets `expanded` (see anime-list-filters.js's toggle handler).
    function updateMoreButton(sectionEl, sectionKey, truncationEligible, expanded, moreCount, catalogue, hooks) {
        const moreButton = sectionEl.querySelector('.anime-list__filter-section-more');
        if (!moreButton) {
            return;
        }

        if (!truncationEligible || expanded) {
            moreButton.hidden = true;

            return;
        }

        moreButton.hidden = false;
        moreButton.textContent = window.AppTranslations.resolveKey(catalogue, 'anime_list.filter_section_more_button', { count: moreCount });
        moreButton.onclick = () => hooks.onToggleMore(sectionKey);
    }

    // `hooks`: { isValueApplied(sectionKey, id), isValuePending(sectionKey, id), onToggle, onInstantApply,
    // onToggleMore(sectionKey), onSearchInput(sectionKey, value), pendingIdsFor(sectionKey),
    // appliedIdsFor(sectionKey), resolveEntityFallbackName(sectionKey, id) } — provided by window.AnimeListFilterPanel,
    // which owns appliedFilters/pendingFilters and the per-section "Ещё"/search UI state.
    //
    // `sectionState`: { [sectionKey]: { expanded, search } } — also owned by the panel (issue
    // #820): this module never stores it between calls, so a patch triggered by an unrelated
    // facets refresh (a search debounce keystroke, popstate, another section's apply) can never
    // reset a section a user had expanded, the bug this split-out state exists to avoid.
    function renderPanel(data, catalogue, hooks, sectionState) {
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
                buckets = window.AnimeListQuery.RATING_ORDER.filter((value) => byValue.has(value)).map((value) => byValue.get(value));
            }

            emptyText.hidden = buckets.length > 0;

            let entries = buckets.map((bucket) => {
                const id = config.kind === 'entity' ? String(bucket.id) : bucket.value;

                return {
                    id,
                    name: nameFromBucket(config, bucket, catalogue),
                    count: bucket.count,
                    applied: hooks.isValueApplied(sectionKey, id),
                    pending: hooks.isValuePending(sectionKey, id),
                };
            });
            appendMissingPendingEntries(entries, sectionKey, config, catalogue, hooks);

            updateSectionHeaderCount(sectionEl, sectionKey, hooks);

            if (!config.truncatable) {
                patchValueList(list, entries, sectionKey, config.input, catalogue, hooks);
                updateMoreButton(sectionEl, sectionKey, false, false, 0, catalogue, hooks);
                const searchBox = sectionEl.querySelector('.anime-list__filter-section-search');
                if (searchBox) {
                    searchBox.hidden = true;
                }

                return;
            }

            const state = (sectionState && sectionState[sectionKey]) || {};
            entries = sortEntries(entries);

            const searchActive = updateSearchBox(sectionEl, sectionKey, config, buckets, state, hooks);
            if (searchActive) {
                const query = state.search.trim().toLowerCase();
                entries = entries.filter((entry) => isPinned(entry) || entry.name.toLowerCase().includes(query));
            }

            const pinned = entries.filter(isPinned);
            const rest = entries.filter((entry) => !isPinned(entry));

            const truncationEligible = !searchActive && rest.length > TRUNCATE_MIN_UNCHECKED;
            let visible = pinned.concat(rest);
            let moreCount = 0;
            if (truncationEligible && !state.expanded) {
                visible = pinned.concat(rest.slice(0, TRUNCATE_TOP_COUNT));
                moreCount = rest.length - TRUNCATE_TOP_COUNT;
            }

            patchValueList(list, visible, sectionKey, config.input, catalogue, hooks);
            updateMoreButton(sectionEl, sectionKey, truncationEligible, Boolean(state.expanded), moreCount, catalogue, hooks);
        });
    }

    // `entries`: appliedFilterEntries() output. `context`: { lastFacets, onRemove(sectionKey, value) }.
    function renderChips(entries, catalogue, context) {
        chipList.replaceChildren();
        entries.forEach(({ sectionKey, value }) => {
            const name = resolveValueName(sectionKey, value, catalogue, context.lastFacets);
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
            const removeIcon = document.getElementById('icon-x-lg');
            if (removeIcon) {
                remove.appendChild(removeIcon.content.cloneNode(true));
            }
            remove.addEventListener('click', () => context.onRemove(sectionKey, value));
            chip.appendChild(remove);

            chipList.appendChild(chip);
        });

        chipsResetButton.disabled = entries.length === 0;
        filtersCountBadge.textContent = ` · ${entries.length}`;
        filtersCountBadge.hidden = entries.length === 0;
    }

    window.AnimeListFilterRender = {
        init,
        renderPanel,
        renderChips,
        STUDIOS_SEARCH_MIN_VALUES,
    };
})();
