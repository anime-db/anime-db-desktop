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
        labels: { facetKey: 'labels', kind: 'entity', input: 'checkbox' },
        genres: { facetKey: 'genres', kind: 'enum', translatePrefix: 'genre', input: 'checkbox' },
        themes: { facetKey: 'themes', kind: 'enum', translatePrefix: 'theme', input: 'checkbox' },
        studios: { facetKey: 'studios', kind: 'entity', input: 'checkbox' },
    };

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

    function buildValueRow(entry, sectionKey, inputType, catalogue, hooks) {
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

    // `hooks`: { isValueApplied(sectionKey, id), isValuePending(sectionKey, id), onToggle, onInstantApply } —
    // provided by window.AnimeListFilterPanel, which owns appliedFilters/pendingFilters.
    function renderPanel(data, catalogue, hooks) {
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

            const entries = buckets.map((bucket) => {
                const id = config.kind === 'entity' ? String(bucket.id) : bucket.value;

                return {
                    id,
                    name: nameFromBucket(config, bucket, catalogue),
                    count: bucket.count,
                    applied: hooks.isValueApplied(sectionKey, id),
                    pending: hooks.isValuePending(sectionKey, id),
                };
            });

            patchValueList(list, entries, sectionKey, config.input, catalogue, hooks);
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
            remove.textContent = '×';
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
    };
})();
