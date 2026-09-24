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

// Pure query-string layer for the catalog (issue #712): parses filter/sort state out of a
// URLSearchParams and builds the GET /anime and GET /anime/facets query strings back out of it.
// No DOM access and no module-level state — every input arrives as a parameter and every output
// is a plain value, so this layer is testable without a jsdom page fixture. Consumed by
// anime-list.js (the list/facets requests) and anime-list-filters.js (URL seeding).
(function () {
    const API_URL = '/anime';
    const FACETS_URL = '/anime/facets';

    // Rating facet buckets come back in GROUP BY order, not display order — the issue requires
    // five checkboxes counting down from 5, plus "no rating" last. Shared with the filter panel,
    // which reorders GET /anime/facets buckets against this same list.
    const RATING_ORDER = ['5', '4', '3', '2', '1', 'none'];

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

    function buildListQuery({ offset, limit, sortField, sortDirection, searchQuery, filters }) {
        const params = new URLSearchParams({
            limit: String(limit),
            offset: String(offset),
            sort: sortField,
            direction: sortDirection,
        });

        if (searchQuery) {
            params.set('name', searchQuery);
        }
        appendFilterParams(params, filters);

        return `${API_URL}?${params.toString()}`;
    }

    function buildFacetsQuery({ searchQuery, filters }) {
        const params = new URLSearchParams();

        if (searchQuery) {
            params.set('name', searchQuery);
        }
        appendFilterParams(params, filters);

        return `${FACETS_URL}?${params.toString()}`;
    }

    // The address-bar query the catalog keeps itself in sync with (issue #713): the same
    // sort/direction/name/filter parameters buildListQuery() itself sends, minus offset/limit —
    // pagination position is deliberately not part of the persisted state (a page reload restores
    // the result set, not the scroll position or page number). Always carries sort/direction,
    // mirroring buildListQuery()'s own unconditional inclusion of both, so a value written here
    // re-parses through parseSortField()/parseSortDirection() the same way any other caller's link
    // would.
    function buildStateQuery({ sortField, sortDirection, searchQuery, filters }) {
        const params = new URLSearchParams({
            sort: sortField,
            direction: sortDirection,
        });

        if (searchQuery) {
            params.set('name', searchQuery);
        }
        appendFilterParams(params, filters);

        return `?${params.toString()}`;
    }

    // watch_status/type/genres/themes are read as-is: validating them would mean duplicating
    // the WatchStatus/AnimeType/GenreCode/ThemeCode enums here, a second place for them to drift
    // out of sync with the backend. An unknown value is instead left for AnimeListRequestParser
    // to reject with a 400 on the resulting GET /anime — a failed list/facets fetch is already
    // handled without throwing by the list core, so a bad link degrades to an error state instead
    // of breaking the page (issue #697).
    function parseEnumSectionSet(params, name) {
        return new Set(params.getAll(`${name}[]`).filter((value) => value !== ''));
    }

    // Entity ids are always numeric on the wire (AnimeListRequestParser::parseIntListParam) —
    // unlike the enum sections above, a non-numeric value here could never resolve to a real
    // studio/label, so it is dropped rather than sent on to fail as a 400 (issue #697).
    function parseEntityIdSet(params, name) {
        return new Set(params.getAll(`${name}[]`).filter((value) => /^\d+$/.test(value)));
    }

    // The one section the URL still has to accept a second shape for: ?labels=<id> is the link a
    // label click on the anime detail page builds (issue #104), while labels[]=<id> is what
    // appendFilterParams() itself writes. Both feed the same Set so either one seeds the panel.
    function parseLabelSet(params) {
        return new Set([...params.getAll('labels'), ...params.getAll('labels[]')].filter((value) => /^\d+$/.test(value)));
    }

    function isTruthyFlag(rawValue) {
        return rawValue !== null && ['1', 'true', 'on', 'yes'].includes(rawValue.toLowerCase());
    }

    // The wire splits "rating" across two parameters (user_rating[] and user_rating_none), but
    // the panel has one section where "no rating" is a value like any other (RATING_ORDER already
    // lists it alongside '5'..'1') — so both are folded into the one Set the panel understands
    // (issue #697).
    function parseUserRatingSet(params) {
        const values = params.getAll('user_rating[]').filter((value) => RATING_ORDER.includes(value));
        const set = new Set(values);
        if (isTruthyFlag(params.get('user_rating_none'))) {
            set.add('none');
        }

        return set;
    }

    // The panel can only ever show a whole decade (issue #666); a from/to pair that does not
    // line up with one exactly would be an applied filter the chip row and "Filters · N" badge
    // could never render, so it is left unapplied entirely rather than shown as a lookalike
    // bucket (issue #697's own resolution of that gap).
    function parseDatePremiereFilter(params) {
        if (isTruthyFlag(params.get('date_premiere_none'))) {
            return 'none';
        }

        const from = params.get('date_premiere_from');
        const to = params.get('date_premiere_to');
        if (!from || !to) {
            return null;
        }

        const match = /^(\d{4})-01-01$/.exec(from);
        if (!match || parseInt(match[1], 10) % 10 !== 0) {
            return null;
        }

        const decade = `${match[1]}s`;

        return decadeRange(decade).to === to ? decade : null;
    }

    // Validated against the sort fields the caller says are known rather than a hardcoded field
    // list, so this never drifts from list.html.twig's own set of sort buttons — an unknown field
    // is simply left at the default (issue #697). Kept DOM-free by taking that known-field list as
    // a parameter (the caller reads it off the page) instead of querying the page itself.
    function parseSortField(params, knownFields) {
        const value = params.get('sort');
        if (!value || !knownFields) {
            return null;
        }

        return knownFields.includes(value) ? value : null;
    }

    function parseSortDirection(params) {
        const value = params.get('direction');

        return value === 'asc' || value === 'desc' ? value : null;
    }

    window.AnimeListQuery = {
        RATING_ORDER,
        decadeRange,
        appendFilterParams,
        buildListQuery,
        buildFacetsQuery,
        buildStateQuery,
        parseEnumSectionSet,
        parseEntityIdSet,
        parseLabelSet,
        isTruthyFlag,
        parseUserRatingSet,
        parseDatePremiereFilter,
        parseSortField,
        parseSortDirection,
    };
})();
