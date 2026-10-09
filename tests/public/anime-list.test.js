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

// Loads the real anime-list.js against a DOM shaped like app/templates/anime/list.html.twig,
// with fetch() and window.AppTranslations replaced by controllable test doubles. The module is
// mounted the same way a page mounts it (issue #734): requiring the five <script> files only
// registers controls/namespaces, so loadAnimeListModule() below also dispatches a synthetic
// htmx:load on the <main data-control="anime-list"> root to actually run the initial loadPage()
// call — the same event htmx itself fires on <body> once the real page finishes loading.
//
// controller.js is required once at file scope, not per test — see controller.test.js for why a
// fresh require() per test would leak document-level listeners.
require('../../app/assets/js/controller.js');

function mountControls(root = document.body) {
    root.dispatchEvent(new CustomEvent('htmx:load', { bubbles: true, detail: { elt: root } }));
}

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => {
        resolve = res;
        reject  = rej;
    });

    return { promise, resolve, reject };
}

function jsonResponse(body) {
    return { ok: true, status: 200, json: () => Promise.resolve(body) };
}

function animeItem(id, title) {
    return { id, title, watch_status: 'watching', type: 'tv', date_premiere: '2024-01-01' };
}

// jsdom does not run real layout, so `grid-template-columns: repeat(auto-fill, ...)` never
// resolves to a track list — the inline style is set directly to whatever getComputedStyle()
// would report in a real browser for the desired column count, one "<n>px" track per column.
// The actual pixel width is irrelevant; only tracks.length (the column count) is read.
function setGridColumns(columns) {
    document.getElementById('anime-list-grid').style.gridTemplateColumns = Array(columns).fill('160px').join(' ');
}

const FILTER_SECTIONS = [
    'watch_status', 'type', 'date_premiere', 'user_rating', 'labels', 'genres', 'themes', 'studios',
];

function filterSectionsMarkup() {
    return FILTER_SECTIONS.map((section) => `
        <section data-filter-section="${section}">
            <button type="button" class="anime-list__filter-section-toggle" aria-expanded="true">
                ${section}<span class="anime-list__filter-section-count" hidden></span>
            </button>
            <div class="anime-list__filter-section-body">
                <p class="anime-list__filter-section-empty" hidden></p>
                <div class="anime-list__filter-section-search" hidden>
                    <input type="search" class="anime-list__filter-section-search-input">
                </div>
                <ul class="anime-list__filter-values"></ul>
                <button type="button" class="anime-list__filter-section-more" hidden></button>
            </div>
        </section>
    `).join('');
}

// Mirrors app/templates/anime/list.html.twig closely enough to drive the real anime-list.js: the
// eight filter sections, the apply button, the chip row and the collapse toggle all need to
// exist with their real ids/classes, since setupFilterPanel()/setupFiltersToggle() query them by
// selector and throw on a missing element the same way a broken template would.
function setUpDom(columns = 1) {
    document.body.innerHTML = `
        <main data-control="anime-list">
        <div id="anime-list-catalog" tabindex="-1">
        <input id="anime-list-search" type="search" />
        <button type="button" id="anime-list-filters-toggle" aria-expanded="true">
            Filters<span id="anime-list-filters-count" hidden></span>
        </button>
        <div id="anime-list-sort">
            <button type="button" data-sort-field="name">Name</button>
            <button type="button" data-sort-field="date_update" aria-current="true">Updated</button>
            <button type="button" data-sort-field="user_rating">Rating</button>
            <button type="button" data-sort-field="date_premiere">Premiere</button>
            <button type="button" data-sort-field="date_end">End</button>
            <button type="button" id="anime-list-sort-direction" data-direction="desc"
                data-label-asc="Ascending" data-label-desc="Descending">↓</button>
        </div>
        <div id="anime-list-chips">
            <ul id="anime-list-chip-list"></ul>
            <span id="anime-list-chips-shown"></span>
            <button type="button" id="anime-list-chips-reset" disabled>Reset all</button>
        </div>
        <div id="anime-list-grid"></div>
        <p id="anime-list-empty" hidden></p>
        <p id="anime-list-error" hidden></p>
        <nav id="anime-list-pagination" hidden></nav>
        <div id="anime-list-sentinel" hidden></div>
        </div>
        <aside id="anime-list-filters">
            <div id="anime-list-filter-sections" data-csrf-token="test-csrf-token">${filterSectionsMarkup()}</div>
            <div class="anime-list__filter-apply-bar">
                <button type="button" id="anime-list-filter-apply" disabled>Filter</button>
            </div>
        </aside>
        </main>
    `;
    setGridColumns(columns);
}

// jsdom does not implement ResizeObserver at all (unlike the real Chromium runtime this app
// ships on). Stores every constructed instance so a test can fire its callback by hand to
// simulate a layout resize, since jsdom will never do it for real.
function mockResizeObserver() {
    const instances = [];

    global.ResizeObserver = class {
        constructor(callback) {
            this.callback = callback;
            instances.push(this);
        }

        observe() {}

        disconnect() {}
    };

    return instances;
}

function triggerResize(instances, columns) {
    setGridColumns(columns);
    instances[0].callback([]);
    jest.advanceTimersByTime(150);
}

// A genuine GET /anime list request always carries `sort`/`direction` (buildListQuery() always
// sets them), which is what tells it apart from GET /anime/facets below.
function isListRequest(url) {
    return url.startsWith('/anime?') && new URL(url, 'http://localhost').searchParams.has('sort');
}

// Queues one deferred per fetch() call to the anime list endpoint, so the test controls exactly
// when each request settles instead of racing against real timers or network I/O. A signal
// listener rejects the call with an AbortError, mirroring what a real fetch() does once its
// AbortController is aborted. GET /anime/facets (issue #666) also goes through this same mocked
// fetch() but is deliberately left out of the returned queue: it is unrelated to what these
// pagination/sort/search tests assert on, and every request still gets a real (if unresolved)
// promise back so awaiting it never throws.
function mockFetchQueue() {
    const calls = [];

    global.fetch = jest.fn((url, options) => {
        const call = deferred();
        call.url = url;
        const signal = options && options.signal;
        if (signal) {
            signal.addEventListener('abort', () => {
                const error = new Error('The operation was aborted');
                error.name = 'AbortError';
                call.reject(error);
            });
        }
        if (isListRequest(url)) {
            calls.push(call);
        }

        return call.promise;
    });

    return calls;
}

function queryParams(url) {
    return Object.fromEntries(new URL(url, 'http://localhost').searchParams);
}

// Queues one deferred per getCatalogue() call, mirroring translations.js: a failed catalogue
// fetch resets its cached promise to null, so the next call starts a fresh one instead of
// reusing a shared promise — the resolve order of separate calls is independent of call order.
function mockCatalogueQueue() {
    const calls = [];

    const getCatalogue = jest.fn(() => {
        const call = deferred();
        calls.push(call);

        return call.promise;
    });

    return { calls, getCatalogue };
}

// jsdom does not implement IntersectionObserver, so setupInfiniteScroll() needs a stand-in.
// Each constructed instance exposes trigger() to simulate the sentinel entering the viewport.
function mockIntersectionObserver() {
    global.IntersectionObserver = jest.fn(function (callback) {
        this.observe = jest.fn();
        this.disconnect = jest.fn();
        this.trigger = () => callback([{ isIntersecting: true }]);
    });
}

async function flushMicrotasks() {
    for (let i = 0; i < 10; i += 1) {
        await Promise.resolve();
    }
}

// The module was split (issue #712) into namespace objects on `window`, the same pattern as
// window.AppTranslations in translations.js — mirrors the require order below, since each file
// assigns a global the next one reads and anime-list.js itself calls init() immediately.
function loadAnimeListModule() {
    jest.isolateModules(() => {
        require('../../app/assets/js/anime-list-query.js');
        require('../../app/assets/js/anime-list-grid.js');
        require('../../app/assets/js/anime-list-filter-render.js');
        require('../../app/assets/js/anime-list-filters.js');
        require('../../app/assets/js/anime-list.js');
    });
    mountControls();
}

// Same five modules as loadAnimeListModule() above, but required in the exact reverse order —
// anime-list.js first, the four helpers after. Used to prove the catalog's init() no longer
// depends on that order (issue #729).
function loadAnimeListModuleReversed() {
    jest.isolateModules(() => {
        require('../../app/assets/js/anime-list.js');
        require('../../app/assets/js/anime-list-filters.js');
        require('../../app/assets/js/anime-list-filter-render.js');
        require('../../app/assets/js/anime-list-grid.js');
        require('../../app/assets/js/anime-list-query.js');
    });
    mountControls();
}

function cardTitles(grid) {
    return Array.from(grid.querySelectorAll('.anime-card__title')).map((node) => node.textContent);
}

function cardHrefs(grid) {
    return Array.from(grid.querySelectorAll('.anime-card')).map((node) => node.getAttribute('href'));
}

let resizeObserverInstances;

beforeEach(() => {
    jest.resetModules();
    jest.useFakeTimers();
    setUpDom();
    resizeObserverInstances = mockResizeObserver();
    Element.prototype.scrollTo = jest.fn();
    mockIntersectionObserver();
});

afterEach(() => {
    jest.useRealTimers();
    delete global.fetch;
    delete global.ResizeObserver;
    delete global.IntersectionObserver;
    delete window.AppTranslations;
    window.history.replaceState({}, '', '/');
    // jest.spyOn() on window.history.pushState/replaceState (issue #713's tests) returns the same
    // mock on a second spy of an already-spied method instead of a fresh one — without restoring
    // here, a later test's "fresh" spy would inherit an earlier test's call history.
    jest.restoreAllMocks();
});

// Issue #835: list.html.twig renders none of the toolbar/sort/grid/filters markup when the
// catalog is empty, replacing it with the onboarding invitation instead. mountAnimeList() must
// recognize that shape and do nothing, rather than crash on the now-missing elements it would
// otherwise assume exist (#anime-list-error, the grid, etc.).
test('mounting on the empty-catalog onboarding markup does not throw and fetches nothing', () => {
    document.body.innerHTML = `
        <main data-control="anime-list">
            <section class="anime-list__onboarding"></section>
        </main>
    `;
    global.fetch = jest.fn();
    const consoleError = jest.spyOn(console, 'error').mockImplementation(() => {});

    loadAnimeListModule();

    expect(global.fetch).not.toHaveBeenCalled();
    expect(consoleError).not.toHaveBeenCalled();
});

test('cards render even when the translations catalogue fails to load', async () => {
    const calls = mockFetchQueue();
    window.AppTranslations = {
        getCatalogue: jest.fn(() => Promise.reject(new Error('Translations request failed with status 500'))),
        resolveKey:   (catalogue, key) => key,
    };

    loadAnimeListModule(); // fires the initial loadPage(0, true) call
    await flushMicrotasks();

    expect(calls).toHaveLength(1);
    calls[0].resolve(jsonResponse({
        items:            [animeItem(1, 'Steins;Gate'), animeItem(2, 'Mushishi')],
        pagination_mode:  'classic',
        total:            2,
        limit:            20,
        offset:           0,
    }));
    await flushMicrotasks();

    const grid = document.getElementById('anime-list-grid');
    expect(grid.children.length).toBeGreaterThan(0);
    expect(cardTitles(grid)).toEqual(['Steins;Gate', 'Mushishi']);
});

test('each rendered card is a link to its anime detail page', async () => {
    const calls = mockFetchQueue();
    setUpTranslations();

    loadAnimeListModule(); // fires the initial loadPage(0, true) call
    await flushMicrotasks();

    expect(calls).toHaveLength(1);
    calls[0].resolve(jsonResponse({
        items:            [animeItem(1, 'Steins;Gate'), animeItem(2, 'Mushishi')],
        pagination_mode:  'classic',
        total:            2,
        limit:            20,
        offset:           0,
    }));
    await flushMicrotasks();

    const grid = document.getElementById('anime-list-grid');
    const cards = grid.querySelectorAll('.anime-card');
    expect(cards).toHaveLength(2);
    cards.forEach((card) => expect(card.tagName).toBe('A'));
    expect(cardHrefs(grid)).toEqual(['/anime/1', '/anime/2']);
});

test('a card thumbnail has an empty alt and the full title moves to the title element (issue #700)', async () => {
    const calls = mockFetchQueue();
    setUpTranslations();

    loadAnimeListModule(); // fires the initial loadPage(0, true) call
    await flushMicrotasks();

    expect(calls).toHaveLength(1);
    calls[0].resolve(jsonResponse({
        items:            [{ ...animeItem(1, 'Steins;Gate'), cover: 'cover.webp' }],
        pagination_mode:  'classic',
        total:            1,
        limit:            20,
        offset:           0,
    }));
    await flushMicrotasks();

    const grid = document.getElementById('anime-list-grid');
    const card = grid.querySelector('.anime-card');
    const thumb = grid.querySelector('.anime-card__thumb');
    const title = grid.querySelector('.anime-card__title');
    expect(thumb.getAttribute('alt')).toBe('');
    expect(title.title).toBe('Steins;Gate');
    expect(card.hasAttribute('title')).toBe(false);
});

test('a full list replacement resets the window scroll position, an append does not', async () => {
    const calls = mockFetchQueue();
    window.AppTranslations = {
        getCatalogue: jest.fn(() => Promise.resolve({})),
        resolveKey:   (catalogue, key) => key,
    };

    loadAnimeListModule(); // fires the initial loadPage(0, true) call
    await flushMicrotasks();

    expect(calls).toHaveLength(1);
    calls[0].resolve(jsonResponse({
        items:            [animeItem(1, 'Steins;Gate')],
        pagination_mode:  'infinite_scroll',
        total:            2,
        limit:            1,
        offset:           0,
    }));
    await flushMicrotasks();

    expect(Element.prototype.scrollTo).toHaveBeenCalledTimes(1);
    expect(Element.prototype.scrollTo.mock.instances[0]).toBe(document.getElementById('anime-list-catalog'));
    expect(Element.prototype.scrollTo).toHaveBeenCalledWith({ top: 0 });
    // The sentinel is observed relative to the catalog area (issue #995), not the window.
    expect(global.IntersectionObserver).toHaveBeenCalledWith(expect.any(Function), { root: document.getElementById('anime-list-catalog') });

    // The sentinel entering the viewport triggers an append (loadPage(offset + limit, false)),
    // which must not reset the scroll position the user is currently reading.
    const sentinelObserver = global.IntersectionObserver.mock.instances[0];
    sentinelObserver.trigger();
    await flushMicrotasks();

    expect(calls).toHaveLength(2);
    calls[1].resolve(jsonResponse({
        items:            [animeItem(2, 'Mushishi')],
        pagination_mode:  'infinite_scroll',
        total:            2,
        limit:            1,
        offset:           1,
    }));
    await flushMicrotasks();

    expect(Element.prototype.scrollTo).toHaveBeenCalledTimes(1);
});

test('navigating to a classic pagination page other than the first does not reset the window scroll', async () => {
    const calls = mockFetchQueue();
    window.AppTranslations = {
        getCatalogue: jest.fn(() => Promise.resolve({})),
        resolveKey:   (catalogue, key) => key,
    };

    loadAnimeListModule(); // fires the initial loadPage(0, true) call
    await flushMicrotasks();

    expect(calls).toHaveLength(1);
    calls[0].resolve(jsonResponse({
        items:            [animeItem(1, 'Steins;Gate')],
        pagination_mode:  'classic',
        total:            2,
        limit:            1,
        offset:           0,
    }));
    await flushMicrotasks();

    expect(Element.prototype.scrollTo).toHaveBeenCalledTimes(1);
    Element.prototype.scrollTo.mockClear();

    const pageTwoButton = document.querySelectorAll('#anime-list-pagination button')[1];
    pageTwoButton.dispatchEvent(new Event('click'));
    await flushMicrotasks();

    expect(calls).toHaveLength(2);
    calls[1].resolve(jsonResponse({
        items:            [animeItem(2, 'Mushishi')],
        pagination_mode:  'classic',
        total:            2,
        limit:            1,
        offset:           1,
    }));
    await flushMicrotasks();

    // Page navigation replaces the grid contents (replace === true) same as a fresh search, but
    // it is not a "the list composition changed" event from the top — the scroll reset must key
    // off the target offset, not the replace flag alone.
    expect(Element.prototype.scrollTo).not.toHaveBeenCalled();
});

test('a stale response that outlives an abort during the catalogue fetch is dropped', async () => {
    const fetchCalls = mockFetchQueue();
    const { calls: catalogueCalls, getCatalogue } = mockCatalogueQueue();
    window.AppTranslations = {
        getCatalogue,
        resolveKey: (catalogue, key) => key,
    };

    loadAnimeListModule(); // first loadPage(0, true) call
    await flushMicrotasks();
    expect(fetchCalls).toHaveLength(1);

    // The first request's fetch resolves before the second loadPage() starts, so the first call
    // is already past fetchPage() and waiting on its own catalogue fetch when it gets aborted —
    // this is what the controller.signal.aborted re-check after that await guards against
    // (issue #208). If the fetch itself were still pending, abort() would reject it with an
    // AbortError and the guard under test would never run.
    fetchCalls[0].resolve(jsonResponse({
        items:           [animeItem(1, 'Steins;Gate'), animeItem(2, 'Mushishi')],
        pagination_mode: 'classic',
        total:           2,
        limit:           20,
        offset:          0,
    }));
    await flushMicrotasks();
    expect(catalogueCalls).toHaveLength(1);

    // A search keystroke triggers a second, overlapping loadPage(0, true) call, which aborts the
    // first request's controller before fetching its own page.
    const searchInput = document.getElementById('anime-list-search');
    searchInput.value = 'gate';
    searchInput.dispatchEvent(new Event('input'));
    jest.advanceTimersByTime(300);
    await flushMicrotasks();
    expect(fetchCalls).toHaveLength(2);

    fetchCalls[1].resolve(jsonResponse({
        items:           [animeItem(3, 'Gate')],
        pagination_mode: 'classic',
        total:           1,
        limit:           20,
        offset:          0,
    }));
    await flushMicrotasks();
    expect(catalogueCalls).toHaveLength(2);

    // Resolve the newer request's catalogue first, then the stale (aborted) one's — the stale
    // one arriving last is exactly the race the abort-signal re-check defends against.
    catalogueCalls[1].resolve({});
    await flushMicrotasks();

    catalogueCalls[0].resolve({});
    await flushMicrotasks();

    const grid = document.getElementById('anime-list-grid');
    expect(cardTitles(grid)).toEqual(['Gate']);
});

function setUpTranslations() {
    window.AppTranslations = {
        getCatalogue: jest.fn(() => Promise.resolve({})),
        resolveKey:   (catalogue, key) => key,
    };
}

function dispatchClick(element) {
    element.dispatchEvent(new Event('click', { bubbles: true }));
}

test('the initial request limit is a multiple of the grid column count, capped at 6 rows', async () => {
    setGridColumns(5); // columns(5) × min(ROWS=6, floor(MAX_LIMIT=100 / 5)=20) → 5 × 6 = 30
    const calls = mockFetchQueue();
    setUpTranslations();

    loadAnimeListModule();
    await flushMicrotasks();

    expect(calls).toHaveLength(1);
    expect(queryParams(calls[0].url)).toMatchObject({ limit: '30', offset: '0' });
});

test('the limit caps rows (not the limit directly) once MAX_LIMIT would otherwise be exceeded', async () => {
    setGridColumns(21); // floor(100 / 21) = 4 rows → 21 × 4 = 84, not 21 × 6 = 126
    const calls = mockFetchQueue();
    setUpTranslations();

    loadAnimeListModule();
    await flushMicrotasks();

    expect(queryParams(calls[0].url).limit).toBe('84');
});

test('clicking a sort field reloads from offset 0 with the chosen field and marks it current', async () => {
    const calls = mockFetchQueue();
    setUpTranslations();
    loadAnimeListModule();
    await flushMicrotasks();
    calls[0].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    await flushMicrotasks();
    Element.prototype.scrollTo.mockClear();

    dispatchClick(document.querySelector('[data-sort-field="name"]'));
    await flushMicrotasks();

    expect(calls).toHaveLength(2);
    expect(queryParams(calls[1].url)).toMatchObject({ sort: 'name', direction: 'desc', offset: '0' });
    expect(document.querySelector('[data-sort-field="name"]').getAttribute('aria-current')).toBe('true');
    expect(document.querySelector('[data-sort-field="date_update"]').hasAttribute('aria-current')).toBe(false);

    // A sort-field change is a genuinely new result set (issue #687's isNewQuery=true call
    // sites), so the window scroll position must reset once the reload lands, same as a search
    // or a filter change.
    calls[1].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    await flushMicrotasks();
    expect(Element.prototype.scrollTo).toHaveBeenCalledTimes(1);
});

test('toggling sort direction flips desc/asc, reloads and updates the button label', async () => {
    const calls = mockFetchQueue();
    setUpTranslations();
    loadAnimeListModule();
    await flushMicrotasks();
    calls[0].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    await flushMicrotasks();
    Element.prototype.scrollTo.mockClear();

    const directionButton = document.getElementById('anime-list-sort-direction');
    dispatchClick(directionButton);
    await flushMicrotasks();

    expect(calls).toHaveLength(2);
    expect(queryParams(calls[1].url).direction).toBe('asc');
    expect(directionButton.textContent).toBe('↑');
    expect(directionButton.getAttribute('aria-label')).toBe('Ascending');

    // Same as the sort-field case above: a direction flip is a new result set, not a re-page of
    // the current one (issue #687).
    calls[1].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    await flushMicrotasks();
    expect(Element.prototype.scrollTo).toHaveBeenCalledTimes(1);
});

test('a search query change resets the window scroll position', async () => {
    const calls = mockFetchQueue();
    setUpTranslations();
    loadAnimeListModule();
    await flushMicrotasks();
    calls[0].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    await flushMicrotasks();
    Element.prototype.scrollTo.mockClear();

    const searchInput = document.getElementById('anime-list-search');
    searchInput.value = 'gate';
    searchInput.dispatchEvent(new Event('input'));
    jest.advanceTimersByTime(300);
    await flushMicrotasks();

    expect(calls).toHaveLength(2);
    calls[1].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    await flushMicrotasks();

    expect(Element.prototype.scrollTo).toHaveBeenCalledTimes(1);
});

test('clicking classic pagination page 1 resets the window scroll position', async () => {
    const calls = mockFetchQueue();
    setUpTranslations();
    loadAnimeListModule();
    await flushMicrotasks();
    calls[0].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 2, limit: 1, offset: 0 }));
    await flushMicrotasks();
    Element.prototype.scrollTo.mockClear();

    // Jumping back to page 1 is treated the same as a fresh search (issue #687) — unlike a jump
    // to any other page, which the "does not reset" test above covers.
    const pageOneButton = document.querySelectorAll('#anime-list-pagination button')[0];
    pageOneButton.dispatchEvent(new Event('click'));
    await flushMicrotasks();

    expect(calls).toHaveLength(2);
    calls[1].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 2, limit: 1, offset: 0 }));
    await flushMicrotasks();

    expect(Element.prototype.scrollTo).toHaveBeenCalledTimes(1);
});

test('a column-count change in infinite scroll tops up the last row to a full row', async () => {
    setGridColumns(5); // initial limit = 30
    const calls = mockFetchQueue();
    setUpTranslations();
    loadAnimeListModule();
    await flushMicrotasks();

    const firstPage = Array.from({ length: 30 }, (_, i) => animeItem(i + 1, `Anime ${i + 1}`));
    calls[0].resolve(jsonResponse({
        items:            firstPage,
        pagination_mode:  'infinite_scroll',
        total:            100,
        limit:            30,
        offset:           0,
    }));
    await flushMicrotasks();

    // 30 cards laid out in 7 columns is 4 full rows plus a 2-card remainder — topping it up to a
    // full row needs 5 more (issue #665's own worked example).
    triggerResize(resizeObserverInstances, 7);
    await flushMicrotasks();

    expect(calls).toHaveLength(2);
    expect(queryParams(calls[1].url)).toMatchObject({ offset: '30', limit: '5' });

    const topUp = Array.from({ length: 5 }, (_, i) => animeItem(31 + i, `Extra ${i + 1}`));
    calls[1].resolve(jsonResponse({
        items:            topUp,
        pagination_mode:  'infinite_scroll',
        total:            100,
        limit:            5,
        offset:           30,
    }));
    await flushMicrotasks();

    expect(document.getElementById('anime-list-grid').children.length).toBe(35);
});

test('a column-count change in classic mode re-pages around the first record of the current page', async () => {
    setGridColumns(5); // initial limit = 30
    const calls = mockFetchQueue();
    setUpTranslations();
    loadAnimeListModule();
    await flushMicrotasks();

    calls[0].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 100, limit: 30, offset: 0 }));
    await flushMicrotasks();

    // Navigate to page 2 (offset 30) before the resize, matching the pagination markup loadPage()
    // itself just rendered.
    dispatchClick(document.querySelectorAll('#anime-list-pagination button')[1]);
    await flushMicrotasks();
    expect(calls).toHaveLength(2);
    calls[1].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 100, limit: 30, offset: 30 }));
    await flushMicrotasks();
    Element.prototype.scrollTo.mockClear();

    // newLimit = 3 × 6 = 18; the record at index 30 now falls on page floor(30/18)+1 = 2, i.e.
    // offset 18. A widening resize (e.g. to 10 columns, newLimit 60) would land on page 1 (offset
    // 0) regardless of whether the anchor math ran at all, since floor(30/60)+1 is always 1 — that
    // case cannot distinguish real anchoring from an unconditional "reset to page 1" and is
    // covered separately below (issue #687).
    triggerResize(resizeObserverInstances, 3);
    await flushMicrotasks();

    expect(calls).toHaveLength(3);
    expect(queryParams(calls[2].url)).toMatchObject({ offset: '18', limit: '18' });
    calls[2].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 100, limit: 18, offset: 18 }));
    await flushMicrotasks();
    expect(Element.prototype.scrollTo).not.toHaveBeenCalled();
});

test('a column-count change in classic mode does not reset the window scroll when the anchor lands back on offset 0', async () => {
    setGridColumns(5); // initial limit = 30
    const calls = mockFetchQueue();
    setUpTranslations();
    loadAnimeListModule();
    await flushMicrotasks();

    calls[0].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 100, limit: 30, offset: 0 }));
    await flushMicrotasks();

    // Navigate to page 2 (offset 30) before the resize, matching the pagination markup loadPage()
    // itself just rendered.
    dispatchClick(document.querySelectorAll('#anime-list-pagination button')[1]);
    await flushMicrotasks();
    expect(calls).toHaveLength(2);
    calls[1].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 100, limit: 30, offset: 30 }));
    await flushMicrotasks();
    Element.prototype.scrollTo.mockClear();

    // newLimit = 10 × 6 = 60; floor(30 / 60) + 1 = 1, so the anchor lands back on offset 0 — the
    // exact same request a brand-new search would send. The gate must tell these apart by an
    // explicit flag from the caller, not by offset === 0, which is coincidence here, not intent
    // (issue #687).
    triggerResize(resizeObserverInstances, 10);
    await flushMicrotasks();

    expect(calls).toHaveLength(3);
    expect(queryParams(calls[2].url)).toMatchObject({ offset: '0', limit: '60' });
    calls[2].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 100, limit: 60, offset: 0 }));
    await flushMicrotasks();
    expect(Element.prototype.scrollTo).not.toHaveBeenCalled();
});

test('a resize that arrives before the first response still restores the row invariant', async () => {
    setGridColumns(8); // initial limit = 8 × 6 = 48
    const calls = mockFetchQueue();
    setUpTranslations();
    loadAnimeListModule();
    await flushMicrotasks();
    expect(queryParams(calls[0].url).limit).toBe('48');

    // Resize while the first request is still in flight — paginationMode is still null at this
    // point, so handleGridResize() alone cannot act on it (issue #665).
    triggerResize(resizeObserverInstances, 7);
    await flushMicrotasks();

    const firstPage = Array.from({ length: 48 }, (_, i) => animeItem(i + 1, `Anime ${i + 1}`));
    calls[0].resolve(jsonResponse({
        items:            firstPage,
        pagination_mode:  'infinite_scroll',
        total:            1000,
        limit:            48,
        offset:           0,
    }));
    await flushMicrotasks();

    // 48 cards laid out in 7 columns is 6 full rows plus a 6-card remainder — the invariant must
    // be restored once the response lands, not only on the next resize.
    expect(calls).toHaveLength(2);
    expect(queryParams(calls[1].url)).toMatchObject({ offset: '48', limit: '1' });
});

test('the synthetic initial ResizeObserver callback does not trigger a duplicate request', async () => {
    // Per spec, ResizeObserver delivers one callback right after observe() with the current size,
    // not just on a later real resize — the shared mock's observe() is a no-op, so this test wires
    // its own to reproduce that and pin down that the app does not react to it as if it were one.
    global.ResizeObserver = class {
        constructor(callback) {
            this.callback = callback;
        }

        observe() {
            this.callback([]);
        }

        disconnect() {}
    };

    const calls = mockFetchQueue();
    setUpTranslations();

    loadAnimeListModule();
    jest.advanceTimersByTime(150);
    await flushMicrotasks();

    expect(calls).toHaveLength(1);
});

// The filter-panel tests below need to see every request, not just list ones — unlike
// mockFetchQueue() above, which exists precisely to hide the facets requests from tests that are
// not about them (issue #666).
function classifyRequest(url) {
    return url.startsWith('/anime/facets') ? 'facets' : 'list';
}

function mockFetchQueueAll() {
    const calls = [];

    global.fetch = jest.fn((url, options) => {
        const call = deferred();
        call.url = url;
        call.kind = classifyRequest(url);
        const signal = options && options.signal;
        if (signal) {
            signal.addEventListener('abort', () => {
                const error = new Error('The operation was aborted');
                error.name = 'AbortError';
                call.reject(error);
            });
        }
        calls.push(call);

        return call.promise;
    });

    return calls;
}

function byKind(calls, kind) {
    return calls.filter((call) => call.kind === kind);
}

function emptyFacets() {
    return jsonResponse({
        catalog_total: 0,
        watch_status: [], type: [], date_premiere_decade: [], user_rating: [],
        labels: [], genres: [], themes: [], studios: [],
    });
}

// Issue #734 absorbed issue #729: registerControl() only ever stores a mountFn in a registry
// keyed by name, so requiring the five files in reverse of list.html.twig's <script> order cannot
// throw the way the old immediate init() call did (it used to reach for window.AnimeListGrid etc.
// before those scripts had run at all). Nothing runs until the "anime-list" control actually
// mounts on htmx:load, dispatched here by loadAnimeListModuleReversed() itself — so this test
// proves order-independence directly, without needing to fake document.readyState/
// DOMContentLoaded the way the pre-#734 version of this test did.
test('the catalog initializes correctly even when its five modules are required in the reverse of the template order (issue #729, absorbed by #734)', async () => {
    window.history.replaceState({}, '', '/anime?labels=7');
    const calls = mockFetchQueueAll();
    setUpTranslations();

    loadAnimeListModuleReversed();
    await flushMicrotasks();

    // The grid's request fired (AnimeListGrid.init() ran), the address bar's ?labels=7 seeded the
    // filter panel's chip row (AnimeListFilterPanel.init()/seedFromUrl() ran), and the list request
    // itself carries that seeded filter (AnimeListQuery.buildListQuery() ran).
    expect(byKind(calls, 'list')).toHaveLength(1);
    expect(byKind(calls, 'facets')).toHaveLength(1);
    expect(queryParams(byKind(calls, 'list')[0].url)['labels[]']).toBe('7');
    expect(document.querySelectorAll('.anime-list__chip-label')).toHaveLength(1);
});

test('initializing the catalog fires exactly two requests: GET /anime and GET /anime/facets', async () => {
    const calls = mockFetchQueueAll();
    setUpTranslations();

    loadAnimeListModule();
    await flushMicrotasks();

    expect(calls).toHaveLength(2);
    expect(byKind(calls, 'list')).toHaveLength(1);
    expect(byKind(calls, 'facets')).toHaveLength(1);
});

// Shared setup for the applied-filter scroll-reset tests below: resolves the initial load, then
// applies the "watching" checkbox via its value-label shortcut so there is a chip in place for a
// removeAppliedValue()/resetAllFilters() call to act on.
async function applyWatchingFilter(calls) {
    byKind(calls, 'list')[0].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    byKind(calls, 'facets')[0].resolve(jsonResponse({
        watch_status: [{ value: 'watching', count: 5 }],
        type: [], date_premiere_decade: [], user_rating: [], labels: [], genres: [], themes: [], studios: [],
    }));
    await flushMicrotasks();

    document.querySelector('[data-filter-section="watch_status"] .anime-list__filter-value-name')
        .dispatchEvent(new Event('click', { bubbles: true }));
    await flushMicrotasks();
    byKind(calls, 'list')[1].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    byKind(calls, 'facets')[1].resolve(jsonResponse({
        watch_status: [{ value: 'watching', count: 5 }],
        type: [], date_premiere_decade: [], user_rating: [], labels: [], genres: [], themes: [], studios: [],
    }));
    await flushMicrotasks();
}

test('the facets request has its own AbortController and does not repeat on an infinite-scroll page append', async () => {
    const calls = mockFetchQueueAll();
    setUpTranslations();

    loadAnimeListModule(); // fires the initial loadPage() and loadFacets() calls
    await flushMicrotasks();

    expect(byKind(calls, 'list')).toHaveLength(1);
    expect(byKind(calls, 'facets')).toHaveLength(1);

    byKind(calls, 'list')[0].resolve(jsonResponse({
        items: [animeItem(1, 'Steins;Gate')], pagination_mode: 'infinite_scroll', total: 2, limit: 1, offset: 0,
    }));
    byKind(calls, 'facets')[0].resolve(emptyFacets());
    await flushMicrotasks();

    const sentinelObserver = global.IntersectionObserver.mock.instances[0];
    sentinelObserver.trigger();
    await flushMicrotasks();

    expect(byKind(calls, 'list')).toHaveLength(2);
    expect(byKind(calls, 'facets')).toHaveLength(1);
});

test('the "Shown X of Y" denominator uses the latest facets catalog_total, refreshed on every filter change', async () => {
    const calls = mockFetchQueueAll();
    const resolveKey = jest.fn((catalogue, key, params) => (params ? `${key}:${JSON.stringify(params)}` : key));
    window.AppTranslations = { getCatalogue: jest.fn(() => Promise.resolve({})), resolveKey };

    loadAnimeListModule();
    await flushMicrotasks();
    byKind(calls, 'list')[0].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 3, limit: 6, offset: 0 }));
    byKind(calls, 'facets')[0].resolve(jsonResponse({
        catalog_total: 10,
        watch_status: [{ value: 'watching', count: 5 }],
        type: [], date_premiere_decade: [], user_rating: [], labels: [], genres: [], themes: [], studios: [],
    }));
    await flushMicrotasks();

    const chipsShown = document.getElementById('anime-list-chips-shown');
    expect(chipsShown.textContent).toBe('anime_list.filter_shown_count:{"shown":3,"total":10}');

    const nameButton = document.querySelector('[data-filter-section="watch_status"] .anime-list__filter-value-name');
    nameButton.dispatchEvent(new Event('click', { bubbles: true }));
    await flushMicrotasks();

    expect(byKind(calls, 'list')).toHaveLength(2);
    expect(byKind(calls, 'facets')).toHaveLength(2);

    byKind(calls, 'list')[1].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 1, limit: 6, offset: 0 }));
    // The catalog grew mid-session (e.g. an import finished) — the fresh facets response must
    // win over the value fetched at initialization (issue #688).
    byKind(calls, 'facets')[1].resolve(jsonResponse({
        catalog_total: 12,
        watch_status: [{ value: 'watching', count: 5 }],
        type: [], date_premiere_decade: [], user_rating: [], labels: [], genres: [], themes: [], studios: [],
    }));
    await flushMicrotasks();

    expect(chipsShown.textContent).toBe('anime_list.filter_shown_count:{"shown":1,"total":12}');
});

test('a checkbox accumulates without firing a request; the value label applies it immediately', async () => {
    const calls = mockFetchQueueAll();
    setUpTranslations();

    loadAnimeListModule();
    await flushMicrotasks();
    byKind(calls, 'list')[0].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    byKind(calls, 'facets')[0].resolve(jsonResponse({
        watch_status: [{ value: 'watching', count: 5 }],
        type: [], date_premiere_decade: [], user_rating: [], labels: [], genres: [], themes: [], studios: [],
    }));
    await flushMicrotasks();

    const row = document.querySelector('[data-filter-section="watch_status"] .anime-list__filter-value');
    const checkbox = row.querySelector('.anime-list__filter-checkbox');
    const applyButton = document.getElementById('anime-list-filter-apply');
    expect(applyButton.disabled).toBe(true);

    checkbox.checked = true;
    checkbox.dispatchEvent(new Event('change'));

    // Accumulating must not fire anything — only "Отфильтровать" or the value label does.
    expect(byKind(calls, 'list')).toHaveLength(1);
    expect(byKind(calls, 'facets')).toHaveLength(1);
    expect(applyButton.disabled).toBe(false);

    const nameButton = row.querySelector('.anime-list__filter-value-name');
    nameButton.dispatchEvent(new Event('click', { bubbles: true }));
    await flushMicrotasks();

    expect(byKind(calls, 'list')).toHaveLength(2);
    expect(byKind(calls, 'facets')).toHaveLength(2);
    expect(queryParams(byKind(calls, 'list')[1].url)['watch_status[]']).toBe('watching');

    // Instant apply folds the clicked value straight into appliedFilters, leaving nothing in the
    // pending accumulation still to apply — "Отфильтровать" must go back to disabled (issue
    // #879). Before the fix it stayed enabled, since the button only checked whether pending was
    // non-empty, not whether it still differed from what's already applied.
    expect(applyButton.disabled).toBe(true);
});

// seedFromUrl() resets pendingFilters to equal appliedFilters on every popstate (the list core's
// handlePopState() calls it with the new URL's params), but the apply button's disabled state used
// to be left stale from before the traversal: check a filter without applying it (button enabled),
// navigate back to a URL matching what's already applied, and the button stayed enabled — a click
// at that point fired an empty reload/facets/pushState cycle for no actual filter change.
test('a popstate event that reseeds pendingFilters back to appliedFilters disables the apply button (issue #879)', async () => {
    const calls = mockFetchQueueAll();
    setUpTranslations();

    loadAnimeListModule();
    await flushMicrotasks();
    byKind(calls, 'list')[0].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    byKind(calls, 'facets')[0].resolve(jsonResponse({
        watch_status: [{ value: 'watching', count: 5 }],
        type: [], date_premiere_decade: [], user_rating: [], labels: [], genres: [], themes: [], studios: [],
    }));
    await flushMicrotasks();

    const checkbox = document.querySelector('[data-filter-section="watch_status"] .anime-list__filter-checkbox');
    const applyButton = document.getElementById('anime-list-filter-apply');
    checkbox.checked = true;
    checkbox.dispatchEvent(new Event('change'));
    expect(applyButton.disabled).toBe(false);

    window.history.pushState(null, '', '/anime');
    window.dispatchEvent(new PopStateEvent('popstate'));
    await flushMicrotasks();

    expect(applyButton.disabled).toBe(true);
});

test('"reset all" clears applied filters and refetches without touching the chosen sort field', async () => {
    const calls = mockFetchQueueAll();
    setUpTranslations();

    loadAnimeListModule();
    await flushMicrotasks();
    byKind(calls, 'list')[0].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    byKind(calls, 'facets')[0].resolve(jsonResponse({
        watch_status: [{ value: 'watching', count: 5 }],
        type: [], date_premiere_decade: [], user_rating: [], labels: [], genres: [], themes: [], studios: [],
    }));
    await flushMicrotasks();

    dispatchClick(document.querySelector('[data-sort-field="name"]'));
    await flushMicrotasks();
    byKind(calls, 'list')[1].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    await flushMicrotasks();

    const nameButton = document.querySelector('[data-filter-section="watch_status"] .anime-list__filter-value-name');
    nameButton.dispatchEvent(new Event('click', { bubbles: true }));
    await flushMicrotasks();
    expect(byKind(calls, 'list')).toHaveLength(3);
    byKind(calls, 'list')[2].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    byKind(calls, 'facets')[1].resolve(jsonResponse({
        watch_status: [{ value: 'watching', count: 5 }],
        type: [], date_premiere_decade: [], user_rating: [], labels: [], genres: [], themes: [], studios: [],
    }));
    await flushMicrotasks();

    const resetButton = document.getElementById('anime-list-chips-reset');
    expect(resetButton.disabled).toBe(false);
    resetButton.dispatchEvent(new Event('click', { bubbles: true }));
    await flushMicrotasks();

    expect(byKind(calls, 'list')).toHaveLength(4);
    const resetQuery = queryParams(byKind(calls, 'list')[3].url);
    expect(resetQuery.sort).toBe('name');
    expect(resetQuery.direction).toBe('desc');
    expect(resetQuery).not.toHaveProperty('watch_status[]');
});

test('applying a pending filter resets the window scroll position', async () => {
    const calls = mockFetchQueueAll();
    setUpTranslations();

    loadAnimeListModule();
    await flushMicrotasks();
    byKind(calls, 'list')[0].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    byKind(calls, 'facets')[0].resolve(jsonResponse({
        watch_status: [{ value: 'watching', count: 5 }],
        type: [], date_premiere_decade: [], user_rating: [], labels: [], genres: [], themes: [], studios: [],
    }));
    await flushMicrotasks();
    Element.prototype.scrollTo.mockClear();

    document.querySelector('[data-filter-section="watch_status"] .anime-list__filter-value-name')
        .dispatchEvent(new Event('click', { bubbles: true }));
    await flushMicrotasks();

    expect(byKind(calls, 'list')).toHaveLength(2);
    byKind(calls, 'list')[1].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    await flushMicrotasks();

    expect(Element.prototype.scrollTo).toHaveBeenCalledTimes(1);
});

test('removing an applied filter chip resets the window scroll position', async () => {
    const calls = mockFetchQueueAll();
    setUpTranslations();

    loadAnimeListModule();
    await flushMicrotasks();
    await applyWatchingFilter(calls);
    Element.prototype.scrollTo.mockClear();

    document.querySelector('.anime-list__chip-remove').dispatchEvent(new Event('click', { bubbles: true }));
    await flushMicrotasks();

    expect(byKind(calls, 'list')).toHaveLength(3);
    byKind(calls, 'list')[2].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    await flushMicrotasks();

    expect(Element.prototype.scrollTo).toHaveBeenCalledTimes(1);
});

test('"reset all" resets the window scroll position', async () => {
    const calls = mockFetchQueueAll();
    setUpTranslations();

    loadAnimeListModule();
    await flushMicrotasks();
    await applyWatchingFilter(calls);
    Element.prototype.scrollTo.mockClear();

    document.getElementById('anime-list-chips-reset').dispatchEvent(new Event('click', { bubbles: true }));
    await flushMicrotasks();

    expect(byKind(calls, 'list')).toHaveLength(3);
    byKind(calls, 'list')[2].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    await flushMicrotasks();

    expect(Element.prototype.scrollTo).toHaveBeenCalledTimes(1);
});

test('the filters badge text always equals the number of applied chips', async () => {
    const calls = mockFetchQueueAll();
    setUpTranslations();

    loadAnimeListModule();
    await flushMicrotasks();
    byKind(calls, 'list')[0].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    byKind(calls, 'facets')[0].resolve(jsonResponse({
        watch_status: [{ value: 'watching', count: 5 }],
        type: [], date_premiere_decade: [], user_rating: [], labels: [], genres: [], themes: [], studios: [],
    }));
    await flushMicrotasks();

    const badge = document.getElementById('anime-list-filters-count');
    expect(badge.hidden).toBe(true);

    const nameButton = document.querySelector('[data-filter-section="watch_status"] .anime-list__filter-value-name');
    nameButton.dispatchEvent(new Event('click', { bubbles: true }));
    await flushMicrotasks();

    expect(badge.textContent).toBe(' · 1');
    expect(badge.hidden).toBe(false);
});

test('a ?labels=<id> URL seeds the list request, the chip row and the filters badge', async () => {
    window.history.replaceState({}, '', '/anime?labels=7');
    const calls = mockFetchQueueAll();
    setUpTranslations();

    loadAnimeListModule(); // fires seedFiltersFromUrl() before the first loadPage()/loadFacets()
    await flushMicrotasks();

    expect(queryParams(byKind(calls, 'list')[0].url)['labels[]']).toBe('7');
    expect(document.querySelectorAll('.anime-list__chip-label')).toHaveLength(1);
    expect(document.getElementById('anime-list-filters-count').textContent).toBe(' · 1');
    expect(document.getElementById('anime-list-filters-count').hidden).toBe(false);
});

test('clicking a decade in "date premiere" applies a from/to range and a second click replaces it', async () => {
    const calls = mockFetchQueueAll();
    setUpTranslations();

    loadAnimeListModule();
    await flushMicrotasks();
    byKind(calls, 'list')[0].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    byKind(calls, 'facets')[0].resolve(jsonResponse({
        watch_status: [], type: [],
        date_premiere_decade: [{ value: '2010s', count: 3 }, { value: '2000s', count: 2 }],
        user_rating: [], labels: [], genres: [], themes: [], studios: [],
    }));
    await flushMicrotasks();

    const rows = document.querySelectorAll('[data-filter-section="date_premiere"] .anime-list__filter-value');
    expect(rows).toHaveLength(2);
    rows.forEach((row) => {
        expect(row.querySelector('.anime-list__filter-checkbox').type).toBe('radio');
    });

    rows[0].querySelector('.anime-list__filter-value-name').dispatchEvent(new Event('click', { bubbles: true }));
    await flushMicrotasks();

    expect(byKind(calls, 'list')).toHaveLength(2);
    expect(queryParams(byKind(calls, 'list')[1].url)).toMatchObject({
        date_premiere_from: '2010-01-01',
        date_premiere_to: '2019-12-31',
    });
    byKind(calls, 'list')[1].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    byKind(calls, 'facets')[1].resolve(jsonResponse({
        watch_status: [], type: [],
        date_premiere_decade: [{ value: '2010s', count: 3 }, { value: '2000s', count: 2 }],
        user_rating: [], labels: [], genres: [], themes: [], studios: [],
    }));
    await flushMicrotasks();

    // A second decade replaces the first rather than accumulating alongside it (radio semantics).
    const rowsAfter = document.querySelectorAll('[data-filter-section="date_premiere"] .anime-list__filter-value');
    rowsAfter[1].querySelector('.anime-list__filter-value-name').dispatchEvent(new Event('click', { bubbles: true }));
    await flushMicrotasks();

    expect(byKind(calls, 'list')).toHaveLength(3);
    const secondQuery = queryParams(byKind(calls, 'list')[2].url);
    expect(secondQuery).toMatchObject({ date_premiere_from: '2000-01-01', date_premiere_to: '2009-12-31' });
    expect(document.querySelectorAll('.anime-list__chip-label')).toHaveLength(1);
});

test('applying a filter does not clear the checkbox marks once the following facets response repaints the panel', async () => {
    const calls = mockFetchQueueAll();
    setUpTranslations();

    loadAnimeListModule();
    await flushMicrotasks();
    byKind(calls, 'list')[0].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    byKind(calls, 'facets')[0].resolve(jsonResponse({
        watch_status: [{ value: 'watching', count: 5 }, { value: 'planned', count: 2 }],
        type: [], date_premiere_decade: [], user_rating: [], labels: [], genres: [], themes: [], studios: [],
    }));
    await flushMicrotasks();

    const rows = document.querySelectorAll('[data-filter-section="watch_status"] .anime-list__filter-value');
    const watchingCheckbox = rows[0].querySelector('.anime-list__filter-checkbox');
    const plannedCheckbox = rows[1].querySelector('.anime-list__filter-checkbox');
    plannedCheckbox.checked = true;
    plannedCheckbox.dispatchEvent(new Event('change'));

    rows[0].querySelector('.anime-list__filter-value-name').dispatchEvent(new Event('click', { bubbles: true }));
    await flushMicrotasks();

    byKind(calls, 'list')[1].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    byKind(calls, 'facets')[1].resolve(jsonResponse({
        watch_status: [{ value: 'watching', count: 5 }, { value: 'planned', count: 2 }],
        type: [], date_premiere_decade: [], user_rating: [], labels: [], genres: [], themes: [], studios: [],
    }));
    await flushMicrotasks();

    const rowsAfter = document.querySelectorAll('[data-filter-section="watch_status"] .anime-list__filter-value');
    expect(rowsAfter[0].querySelector('.anime-list__filter-checkbox').checked).toBe(true);
    expect(rowsAfter[1].querySelector('.anime-list__filter-checkbox').checked).toBe(true);
    expect(watchingCheckbox.checked).toBe(true);
});

// Issue #697: the catalog reads its full state back out of the address bar at init, not just
// the ?labels=<id> scalar from #104 covered above.

function errorResponse(status) {
    return { ok: false, status, json: () => Promise.resolve({}) };
}

test('a ?labels[]=<id> URL (the list shape appendFilterParams() itself writes) seeds the same as ?labels=<id>', async () => {
    window.history.replaceState({}, '', '/anime?labels[]=7');
    const calls = mockFetchQueueAll();
    setUpTranslations();

    loadAnimeListModule();
    await flushMicrotasks();

    expect(queryParams(byKind(calls, 'list')[0].url)['labels[]']).toBe('7');
    expect(document.querySelectorAll('.anime-list__chip-label')).toHaveLength(1);
});

test('?date_premiere_from=1990-01-01&date_premiere_to=1999-12-31 seeds the 1990s decade bucket', async () => {
    window.history.replaceState({}, '', '/anime?date_premiere_from=1990-01-01&date_premiere_to=1999-12-31');
    const calls = mockFetchQueueAll();
    setUpTranslations();

    loadAnimeListModule();
    await flushMicrotasks();

    expect(queryParams(byKind(calls, 'list')[0].url)).toMatchObject({
        date_premiere_from: '1990-01-01',
        date_premiere_to: '1999-12-31',
    });
    expect(document.querySelectorAll('.anime-list__chip-label')).toHaveLength(1);
});

test('a date range that does not line up with a whole decade is left unapplied and out of the chips', async () => {
    window.history.replaceState({}, '', '/anime?date_premiere_from=1990-01-01&date_premiere_to=1999-06-30');
    const calls = mockFetchQueueAll();
    setUpTranslations();

    loadAnimeListModule();
    await flushMicrotasks();

    const listParams = queryParams(byKind(calls, 'list')[0].url);
    expect(listParams).not.toHaveProperty('date_premiere_from');
    expect(listParams).not.toHaveProperty('date_premiere_to');
    expect(document.querySelectorAll('.anime-list__chip-label')).toHaveLength(0);
});

test('a date range whose "from" year is not a decade start (e.g. 1995) is left unapplied and out of the chips', async () => {
    window.history.replaceState({}, '', '/anime?date_premiere_from=1995-01-01&date_premiere_to=2004-12-31');
    const calls = mockFetchQueueAll();
    setUpTranslations();

    loadAnimeListModule();
    await flushMicrotasks();

    const listParams = queryParams(byKind(calls, 'list')[0].url);
    expect(listParams).not.toHaveProperty('date_premiere_from');
    expect(listParams).not.toHaveProperty('date_premiere_to');
    expect(document.querySelectorAll('.anime-list__chip-label')).toHaveLength(0);
});

test('?user_rating[]=5&user_rating_none=1 checks both "5" and "no rating" in the rating section', async () => {
    window.history.replaceState({}, '', '/anime?user_rating[]=5&user_rating_none=1');
    const calls = mockFetchQueueAll();
    setUpTranslations();

    loadAnimeListModule();
    await flushMicrotasks();

    const listParams = queryParams(byKind(calls, 'list')[0].url);
    expect(listParams['user_rating[]']).toBe('5');
    expect(listParams.user_rating_none).toBe('1');
    expect(document.querySelectorAll('.anime-list__chip-label')).toHaveLength(2);
});

test('mounting focuses the catalog area when nothing has focus (issue #995)', async () => {
    mockFetchQueue();
    setUpDom();
    document.activeElement.blur();

    loadAnimeListModule();
    await flushMicrotasks();

    expect(document.activeElement).toBe(document.getElementById('anime-list-catalog'));
});

test('mounting does not steal focus from a field (issue #995)', async () => {
    mockFetchQueue();
    setUpDom();
    const search = document.getElementById('anime-list-search');
    search.focus();

    loadAnimeListModule();
    await flushMicrotasks();

    expect(document.activeElement).toBe(search);
});

test('?name=...&sort=...&direction=... seeds the search box and the sort controls', async () => {
    window.history.replaceState({}, '', '/anime?name=gate&sort=user_rating&direction=asc');
    const calls = mockFetchQueueAll();
    setUpTranslations();

    loadAnimeListModule();
    await flushMicrotasks();

    expect(document.getElementById('anime-list-search').value).toBe('gate');
    expect(document.querySelector('[data-sort-field="user_rating"]').getAttribute('aria-current')).toBe('true');
    expect(document.getElementById('anime-list-sort-direction').textContent).toBe('↑');
    expect(queryParams(byKind(calls, 'list')[0].url)).toMatchObject({
        name: 'gate', sort: 'user_rating', direction: 'asc',
    });
});

test('non-numeric label/studio ids in the URL are dropped rather than sent to the backend', async () => {
    window.history.replaceState({}, '', '/anime?labels[]=abc&studios[]=xyz');
    const calls = mockFetchQueueAll();
    setUpTranslations();

    loadAnimeListModule();
    await flushMicrotasks();

    const listParams = queryParams(byKind(calls, 'list')[0].url);
    expect(listParams).not.toHaveProperty('labels[]');
    expect(listParams).not.toHaveProperty('studios[]');
    expect(document.querySelectorAll('.anime-list__chip-label')).toHaveLength(0);
});

test('a catalog opened with an unknown enum value does not throw and stays recoverable via "reset all"', async () => {
    window.history.replaceState({}, '', '/anime?watch_status[]=not-a-real-status');
    const calls = mockFetchQueueAll();
    setUpTranslations();

    // watch_status/type/genres/themes are not validated against their backend enums client-side
    // (issue #697) — an unknown value is passed through and left for the server to reject, which
    // this test simulates via a 400 response below. The requirement under test is that parsing it
    // never throws and the page stays usable, not that the value magically becomes valid.
    expect(() => loadAnimeListModule()).not.toThrow();
    await flushMicrotasks();

    expect(queryParams(byKind(calls, 'list')[0].url)['watch_status[]']).toBe('not-a-real-status');

    byKind(calls, 'list')[0].resolve(errorResponse(400));
    byKind(calls, 'facets')[0].resolve(errorResponse(400));
    await flushMicrotasks();

    expect(document.getElementById('anime-list-error').hidden).toBe(false);

    const resetButton = document.getElementById('anime-list-chips-reset');
    expect(resetButton.disabled).toBe(false);
    resetButton.dispatchEvent(new Event('click', { bubbles: true }));
    await flushMicrotasks();

    expect(byKind(calls, 'list')).toHaveLength(2);
    expect(queryParams(byKind(calls, 'list')[1].url)).not.toHaveProperty('watch_status[]');
});

test('a ?sort= value containing characters invalid in a CSS attribute selector does not throw and stays recoverable', async () => {
    window.history.replaceState({}, '', '/anime?sort=%22%5D');
    const calls = mockFetchQueueAll();
    setUpTranslations();

    expect(() => loadAnimeListModule()).not.toThrow();
    await flushMicrotasks();

    expect(byKind(calls, 'list')).toHaveLength(1);
    expect(queryParams(byKind(calls, 'list')[0].url).sort).toBe('date_update');
});

// Issue #713 replaces the old "window.location.search is never written to..." contract above with
// the opposite one: the catalog now keeps the address bar in sync with its own state, so it
// survives a card navigation and back. The tests below cover, in order: nothing is written before
// the first user action; a filter/sort change pushes a new history entry; a search keystroke
// replaces the last one instead of piling up; a same-document popstate reseeds state without
// writing history itself; and a URL the module wrote re-parses to the same state on a fresh load
// (the card-navigate-and-return path, since anime cards are real <a href> links and "back" from
// one is a full navigation, not something this module intercepts).

test('opening /anime with no params does not write to window.location.search until the first user action (issue #713)', async () => {
    const calls = mockFetchQueueAll();
    setUpTranslations();
    const pushSpy = jest.spyOn(window.history, 'pushState');
    const replaceSpy = jest.spyOn(window.history, 'replaceState');

    loadAnimeListModule();
    await flushMicrotasks();
    byKind(calls, 'list')[0].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    byKind(calls, 'facets')[0].resolve(emptyFacets());
    await flushMicrotasks();

    expect(window.location.search).toBe('');
    expect(pushSpy).not.toHaveBeenCalled();
    expect(replaceSpy).not.toHaveBeenCalled();
});

test('applying a filter seeded from the URL pushes the merged state back to window.location.search', async () => {
    window.history.replaceState({}, '', '/anime?watch_status[]=watching');
    const calls = mockFetchQueueAll();
    setUpTranslations();
    const pushSpy = jest.spyOn(window.history, 'pushState');

    loadAnimeListModule();
    await flushMicrotasks();
    byKind(calls, 'list')[0].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    byKind(calls, 'facets')[0].resolve(jsonResponse({
        watch_status: [{ value: 'watching', count: 5 }],
        type: [], date_premiere_decade: [], user_rating: [], labels: [], genres: [], themes: [], studios: [],
    }));
    await flushMicrotasks();

    // Nothing written yet — the URL is exactly what the page opened with (issue #713's "first
    // load must not append anything" requirement).
    expect(window.location.search).toBe('?watch_status[]=watching');
    expect(pushSpy).not.toHaveBeenCalled();

    document.querySelector('[data-filter-section="watch_status"] .anime-list__filter-value-name')
        .dispatchEvent(new Event('click', { bubbles: true }));
    await flushMicrotasks();

    // A filter apply/remove/reset uses pushState, not replaceState (issue #713): it is a single
    // discrete action, so "back" should undo it one step at a time, same as a card navigation does.
    expect(pushSpy).toHaveBeenCalledTimes(1);
    const written = new URLSearchParams(window.location.search);
    expect(written.get('sort')).toBe('date_update');
    expect(written.get('direction')).toBe('desc');
    expect(written.getAll('watch_status[]')).toEqual(['watching']);
});

test('a sort change pushes state; removing the last chip and "reset all" push state too', async () => {
    const calls = mockFetchQueueAll();
    setUpTranslations();
    const pushSpy = jest.spyOn(window.history, 'pushState');

    loadAnimeListModule();
    await flushMicrotasks();
    await applyWatchingFilter(calls);
    pushSpy.mockClear();

    dispatchClick(document.querySelector('[data-sort-field="name"]'));
    await flushMicrotasks();
    expect(pushSpy).toHaveBeenCalledTimes(1);
    expect(new URLSearchParams(window.location.search).get('sort')).toBe('name');
    byKind(calls, 'list')[2].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    await flushMicrotasks();
    pushSpy.mockClear();

    // The direction toggle is its own branch in setupSortControls() alongside the field click
    // above, and must push state exactly like the field click does (issue #717 review).
    dispatchClick(document.getElementById('anime-list-sort-direction'));
    await flushMicrotasks();
    expect(pushSpy).toHaveBeenCalledTimes(1);
    expect(new URLSearchParams(window.location.search).get('direction')).toBe('asc');
    byKind(calls, 'list')[3].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    await flushMicrotasks();
    pushSpy.mockClear();

    document.querySelector('.anime-list__chip-remove').dispatchEvent(new Event('click', { bubbles: true }));
    await flushMicrotasks();
    expect(pushSpy).toHaveBeenCalledTimes(1);
    // The filter is gone, but the sort choice pushed just above must survive the chip removal.
    expect(new URLSearchParams(window.location.search).getAll('watch_status[]')).toEqual([]);
    expect(new URLSearchParams(window.location.search).get('sort')).toBe('name');
});

test('typing in the search box replaces window.location.search instead of pushing one entry per keystroke (issue #713)', async () => {
    const calls = mockFetchQueueAll();
    setUpTranslations();

    loadAnimeListModule();
    await flushMicrotasks();
    byKind(calls, 'list')[0].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    byKind(calls, 'facets')[0].resolve(emptyFacets());
    await flushMicrotasks();
    const pushSpy = jest.spyOn(window.history, 'pushState');
    const replaceSpy = jest.spyOn(window.history, 'replaceState');

    const searchInput = document.getElementById('anime-list-search');
    // Each keystroke restarts the 300ms debounce, so nothing fires until it settles once at the end
    // — the search box's own debounce (issue #199) is the boundary the URL write rides on top of.
    'gate'.split('').forEach((char) => {
        searchInput.value += char;
        searchInput.dispatchEvent(new Event('input'));
        jest.advanceTimersByTime(100);
    });
    jest.advanceTimersByTime(300);
    await flushMicrotasks();

    expect(pushSpy).not.toHaveBeenCalled();
    expect(replaceSpy).toHaveBeenCalledTimes(1);
    expect(new URLSearchParams(window.location.search).get('name')).toBe('gate');
});

test('a popstate event reseeds filters/search/sort from the new URL, refreshes the list/facets/chips and writes no history itself', async () => {
    window.history.replaceState({}, '', '/anime?watch_status[]=watching&name=gate&sort=name&direction=asc');
    const calls = mockFetchQueueAll();
    setUpTranslations();

    loadAnimeListModule();
    await flushMicrotasks();
    byKind(calls, 'list')[0].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    byKind(calls, 'facets')[0].resolve(jsonResponse({
        watch_status: [{ value: 'watching', count: 5 }],
        type: [], date_premiere_decade: [], user_rating: [], labels: [], genres: [], themes: [], studios: [],
    }));
    await flushMicrotasks();

    expect(document.querySelectorAll('.anime-list__chip-label')).toHaveLength(1);
    expect(document.getElementById('anime-list-search').value).toBe('gate');
    expect(document.querySelector('[data-sort-field="name"]').getAttribute('aria-current')).toBe('true');

    // Simulates the browser landing back on the plain /anime entry that pushUrlState() itself
    // would have created had this filter/search/sort been applied through the UI instead of
    // already being on the URL the page opened with — a same-document traversal, which is exactly
    // when the browser fires popstate (unlike the cross-document card-navigate-and-return path
    // covered by the round-trip test below).
    window.history.pushState(null, '', '/anime');
    const pushSpy = jest.spyOn(window.history, 'pushState');
    const replaceSpy = jest.spyOn(window.history, 'replaceState');

    window.dispatchEvent(new PopStateEvent('popstate'));
    await flushMicrotasks();

    expect(pushSpy).not.toHaveBeenCalled();
    expect(replaceSpy).not.toHaveBeenCalled();
    expect(document.querySelectorAll('.anime-list__chip-label')).toHaveLength(0);
    expect(document.getElementById('anime-list-search').value).toBe('');
    expect(document.querySelector('[data-sort-field="date_update"]').getAttribute('aria-current')).toBe('true');
    expect(document.querySelector('[data-sort-field="name"]').hasAttribute('aria-current')).toBe(false);

    expect(byKind(calls, 'list')).toHaveLength(2);
    expect(queryParams(byKind(calls, 'list')[1].url)).toMatchObject({ sort: 'date_update', direction: 'desc' });
    expect(queryParams(byKind(calls, 'list')[1].url)).not.toHaveProperty('watch_status[]');
    expect(byKind(calls, 'facets')).toHaveLength(2);
});

test('a popstate event cancels a pending search debounce instead of letting it fire afterwards (issue #717 review)', async () => {
    const calls = mockFetchQueueAll();
    setUpTranslations();

    loadAnimeListModule();
    await flushMicrotasks();
    byKind(calls, 'list')[0].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    byKind(calls, 'facets')[0].resolve(emptyFacets());
    await flushMicrotasks();

    const searchInput = document.getElementById('anime-list-search');
    searchInput.value = 'gate';
    searchInput.dispatchEvent(new Event('input'));
    // "Back" lands mid-debounce (issue #717 review) — the 300ms timer from the keystroke above is
    // still pending when popstate fires.
    jest.advanceTimersByTime(100);

    window.history.pushState(null, '', '/anime');
    const replaceSpy = jest.spyOn(window.history, 'replaceState');

    window.dispatchEvent(new PopStateEvent('popstate'));
    await flushMicrotasks();

    byKind(calls, 'list')[1].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    byKind(calls, 'facets')[1].resolve(emptyFacets());
    await flushMicrotasks();

    expect(byKind(calls, 'list')).toHaveLength(2);
    expect(byKind(calls, 'facets')).toHaveLength(2);

    // Without clearTimeout() in handlePopState(), the stale debounce would fire here and cause a
    // redundant third list/facets request plus a stray replaceState call.
    jest.advanceTimersByTime(300);
    await flushMicrotasks();

    expect(byKind(calls, 'list')).toHaveLength(2);
    expect(byKind(calls, 'facets')).toHaveLength(2);
    expect(replaceSpy).not.toHaveBeenCalled();
});

test('a filter selected before navigating to a card round-trips through a fresh load of the URL it wrote', async () => {
    let calls = mockFetchQueueAll();
    setUpTranslations();

    loadAnimeListModule();
    await flushMicrotasks();
    byKind(calls, 'list')[0].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    byKind(calls, 'facets')[0].resolve(jsonResponse({
        watch_status: [{ value: 'watching', count: 5 }],
        type: [], date_premiere_decade: [], user_rating: [], labels: [], genres: [], themes: [], studios: [],
    }));
    await flushMicrotasks();

    document.querySelector('[data-filter-section="watch_status"] .anime-list__filter-value-name')
        .dispatchEvent(new Event('click', { bubbles: true }));
    await flushMicrotasks();

    // A card is a real <a href="/anime/{id}"> link (issue #104) — opening one and returning is a
    // full cross-document navigation this module never intercepts, so "return" means a fresh load
    // of /anime at whatever URL pushUrlState() left behind, exercised here the same way the
    // existing read-side tests below drive a fresh load from a hand-built URL.
    const returnUrl = window.location.pathname + window.location.search;

    jest.resetModules();
    setUpDom();
    resizeObserverInstances = mockResizeObserver();
    Element.prototype.scrollTo = jest.fn();
    mockIntersectionObserver();
    window.history.replaceState({}, '', returnUrl);
    calls = mockFetchQueueAll();
    setUpTranslations();

    loadAnimeListModule();
    await flushMicrotasks();

    expect(queryParams(byKind(calls, 'list')[0].url)['watch_status[]']).toBe('watching');
    expect(document.querySelectorAll('.anime-list__chip-label')).toHaveLength(1);
});

test('state built by appendFilterParams() and placed in the URL restores in full on re-parse (round trip)', async () => {
    const query = new URLSearchParams({ sort: 'name', direction: 'asc', name: 'steins' });
    query.append('watch_status[]', 'watching');
    query.append('type[]', 'tv');
    query.append('genres[]', 'action');
    query.append('themes[]', 'mecha');
    query.append('studios[]', '3');
    query.append('labels[]', '7');
    query.append('user_rating[]', '5');
    query.set('user_rating_none', '1');
    query.set('date_premiere_from', '1990-01-01');
    query.set('date_premiere_to', '1999-12-31');
    window.history.replaceState({}, '', `/anime?${query.toString()}`);

    const calls = mockFetchQueueAll();
    setUpTranslations();

    loadAnimeListModule();
    await flushMicrotasks();

    expect(queryParams(byKind(calls, 'list')[0].url)).toMatchObject({
        'watch_status[]': 'watching',
        'type[]': 'tv',
        'genres[]': 'action',
        'themes[]': 'mecha',
        'studios[]': '3',
        'labels[]': '7',
        'user_rating[]': '5',
        user_rating_none: '1',
        date_premiere_from: '1990-01-01',
        date_premiere_to: '1999-12-31',
        name: 'steins',
        sort: 'name',
        direction: 'asc',
    });

    expect(document.getElementById('anime-list-search').value).toBe('steins');
    expect(document.querySelector('[data-sort-field="name"]').getAttribute('aria-current')).toBe('true');
    expect(document.getElementById('anime-list-sort-direction').textContent).toBe('↑');

    // One chip per applied value: watch_status, type, genres, themes, studios, labels (6) plus
    // two rating values ('5' and 'none') plus the date_premiere decade bucket = 9.
    expect(document.querySelectorAll('.anime-list__chip-label')).toHaveLength(9);
    expect(document.getElementById('anime-list-filters-count').textContent).toBe(' · 9');
});

// Issue #820: truncation/sort/pin/search for the truncatable sections (genres, themes, labels,
// studios) plus the section-collapse persistence endpoint. `resolveKey` below is the fuller mock
// (mirrors the one at line ~913) rather than setUpTranslations()'s "just return the key" one — the
// "Ещё (%count%)" button and section-count badge text are asserted on exactly, so the params they
// carry must actually be substituted.
function setUpDetailedTranslations() {
    window.AppTranslations = {
        getCatalogue: jest.fn(() => Promise.resolve({})),
        resolveKey: (catalogue, key, params) => (params ? `${key}:${JSON.stringify(params)}` : key),
    };
}

function facetsWith(overrides) {
    return jsonResponse({
        catalog_total: 0,
        watch_status: [], type: [], date_premiere_decade: [], user_rating: [],
        labels: [], genres: [], themes: [], studios: [],
        ...overrides,
    });
}

// Synthetic genre-shaped buckets g0..g(count-1), counts strictly descending (g0 highest) so
// truncation/sort order is unambiguous to assert on.
function genreBuckets(count) {
    return Array.from({ length: count }, (_, i) => ({ value: `g${i}`, count: count - i }));
}

function studioBuckets(count) {
    return Array.from({ length: count }, (_, i) => ({ id: i + 1, name: `Studio${i}`, count: count - i }));
}

async function loadAndResolveFacets(calls, facetsOverrides) {
    loadAnimeListModule();
    await flushMicrotasks();
    byKind(calls, 'list')[0].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    byKind(calls, 'facets')[0].resolve(facetsWith(facetsOverrides));
    await flushMicrotasks();
}

test('a truncatable section with more than 10 unchecked values shows only the top 8 plus an "Ещё (k)" button (issue #820)', async () => {
    const calls = mockFetchQueueAll();
    setUpDetailedTranslations();
    await loadAndResolveFacets(calls, { genres: genreBuckets(12) });

    const section = document.querySelector('[data-filter-section="genres"]');
    expect(section.querySelectorAll('.anime-list__filter-value')).toHaveLength(8);
    const moreButton = section.querySelector('.anime-list__filter-section-more');
    expect(moreButton.hidden).toBe(false);
    expect(moreButton.textContent).toBe('anime_list.filter_section_more_button:{"count":4}');
});

test('a truncatable section with 10 or fewer unchecked values is not truncated at all (issue #820)', async () => {
    const calls = mockFetchQueueAll();
    setUpDetailedTranslations();
    await loadAndResolveFacets(calls, { genres: genreBuckets(10) });

    const section = document.querySelector('[data-filter-section="genres"]');
    expect(section.querySelectorAll('.anime-list__filter-value')).toHaveLength(10);
    expect(section.querySelector('.anime-list__filter-section-more').hidden).toBe(true);
});

test('watch_status/type/date_premiere/user_rating are never truncated regardless of value count (issue #820)', async () => {
    const calls = mockFetchQueueAll();
    setUpDetailedTranslations();
    await loadAndResolveFacets(calls, {
        watch_status: genreBuckets(15),
        type: genreBuckets(15),
        date_premiere_decade: genreBuckets(15),
        // user_rating's own value domain (RATING_ORDER, anime-list-query.js) caps at 6 buckets —
        // real data can never reach the 10-unchecked truncation threshold regardless of whether
        // the section were mistakenly marked truncatable, so this leg only proves it renders every
        // RATING_ORDER value untruncated, not that a >10-bucket response would survive too.
        user_rating: ['5', '4', '3', '2', '1', 'none'].map((value, i) => ({ value, count: 10 - i })),
    });

    [
        ['watch_status', 15],
        ['type', 15],
        ['date_premiere', 15],
        ['user_rating', 6],
    ].forEach(([sectionKey, expectedCount]) => {
        const section = document.querySelector(`[data-filter-section="${sectionKey}"]`);
        expect(section.querySelectorAll('.anime-list__filter-value')).toHaveLength(expectedCount);
        expect(section.querySelector('.anime-list__filter-section-more').hidden).toBe(true);
    });
});

test('a truncatable section sorts by count descending, then by display name ascending on a tie (issue #820)', async () => {
    const calls = mockFetchQueueAll();
    setUpDetailedTranslations();
    await loadAndResolveFacets(calls, {
        genres: [
            { value: 'b', count: 5 },
            { value: 'a', count: 5 },
            { value: 'c', count: 9 },
        ],
    });

    const names = Array.from(document.querySelectorAll('[data-filter-section="genres"] .anime-list__filter-value-name'))
        .map((el) => el.textContent);
    expect(names).toEqual(['genre.c', 'genre.a', 'genre.b']);
});

test('instant-apply on a value not in the first row keeps keyboard focus on that same value after it is repositioned (issue #820 review)', async () => {
    const calls = mockFetchQueueAll();
    setUpDetailedTranslations();
    await loadAndResolveFacets(calls, { genres: genreBuckets(12) });

    const section = document.querySelector('[data-filter-section="genres"]');
    const g3Row = Array.from(section.querySelectorAll('.anime-list__filter-value')).find((row) => row.dataset.value === 'g3');
    const nameButton = g3Row.querySelector('.anime-list__filter-value-name');

    // Simulates a keyboard user who has already tabbed to g3's name button and presses Enter —
    // the click handler applies g3 and patchValueList() moves its row to the pinned/applied block
    // at the top, which must not blur it (issue #820 review: Electron 35 does blur a node that
    // insertBefore() repositions, unlike a no-op move).
    nameButton.focus();
    nameButton.dispatchEvent(new Event('click', { bubbles: true }));
    await flushMicrotasks();

    byKind(calls, 'list')[1].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    byKind(calls, 'facets')[1].resolve(facetsWith({ genres: genreBuckets(12) }));
    await flushMicrotasks();

    const g3RowAfter = Array.from(section.querySelectorAll('.anime-list__filter-value')).find((row) => row.dataset.value === 'g3');
    expect(g3RowAfter.dataset.value).toBe('g3');
    expect(document.activeElement).toBe(g3RowAfter.querySelector('.anime-list__filter-value-name'));
});

test('an applied value outside the top 8 is pinned above it and excluded from the "Ещё" count (issue #820)', async () => {
    window.history.replaceState({}, '', '/anime?genres[]=g11');
    const calls = mockFetchQueueAll();
    setUpDetailedTranslations();
    await loadAndResolveFacets(calls, { genres: genreBuckets(12) });

    const section = document.querySelector('[data-filter-section="genres"]');
    const ids = Array.from(section.querySelectorAll('.anime-list__filter-value')).map((row) => row.dataset.value);
    expect(ids).toHaveLength(9); // the pinned applied value + the top 8
    expect(ids[0]).toBe('g11');
    expect(section.querySelector('.anime-list__filter-section-more').textContent).toBe('anime_list.filter_section_more_button:{"count":3}');
});

test('clicking "Ещё" hides the button and shows every value — there is no collapse-back control inside the section (issue #821 review)', async () => {
    const calls = mockFetchQueueAll();
    setUpDetailedTranslations();
    await loadAndResolveFacets(calls, { genres: genreBuckets(12) });

    const section = document.querySelector('[data-filter-section="genres"]');
    dispatchClick(section.querySelector('.anime-list__filter-section-more'));
    await flushMicrotasks();

    expect(section.querySelectorAll('.anime-list__filter-value')).toHaveLength(12);
    expect(section.querySelector('.anime-list__filter-section-more').hidden).toBe(true);
});

test('collapsing a truncatable section via its header resets "Ещё" — re-expanding shows the top 8 and "Ещё (k)" again (issue #821 review)', async () => {
    const calls = mockFetchQueueAll();
    setUpDetailedTranslations();
    await loadAndResolveFacets(calls, { genres: genreBuckets(12) });

    const section = document.querySelector('[data-filter-section="genres"]');
    dispatchClick(section.querySelector('.anime-list__filter-section-more')); // expand "Ещё"
    await flushMicrotasks();
    expect(section.querySelectorAll('.anime-list__filter-value')).toHaveLength(12);

    const toggle = section.querySelector('.anime-list__filter-section-toggle');
    dispatchClick(toggle); // collapse the section itself
    await flushMicrotasks();
    dispatchClick(toggle); // expand it back

    expect(section.querySelectorAll('.anime-list__filter-value')).toHaveLength(8);
    const moreButton = section.querySelector('.anime-list__filter-section-more');
    expect(moreButton.hidden).toBe(false);
    expect(moreButton.textContent).toBe('anime_list.filter_section_more_button:{"count":4}');
});

test('a pending (checked but not applied) value outside the top 8 stays pinned after the section is collapsed and re-expanded (issue #821 review)', async () => {
    const calls = mockFetchQueueAll();
    setUpDetailedTranslations();
    await loadAndResolveFacets(calls, { genres: genreBuckets(12) });

    const section = document.querySelector('[data-filter-section="genres"]');
    dispatchClick(section.querySelector('.anime-list__filter-section-more')); // expand "Ещё"
    await flushMicrotasks();

    const g11Row = Array.from(section.querySelectorAll('.anime-list__filter-value')).find((row) => row.dataset.value === 'g11');
    const checkbox = g11Row.querySelector('.anime-list__filter-checkbox');
    checkbox.checked = true;
    checkbox.dispatchEvent(new Event('change'));

    const toggle = section.querySelector('.anime-list__filter-section-toggle');
    dispatchClick(toggle); // collapse the section, resetting "Ещё"
    await flushMicrotasks();
    dispatchClick(toggle); // expand it back
    await flushMicrotasks();

    const idsAfter = Array.from(section.querySelectorAll('.anime-list__filter-value')).map((row) => row.dataset.value);
    expect(idsAfter[0]).toBe('g11');
});

test('an expanded "Ещё" section stays expanded once an unrelated facets refresh repaints the panel (issue #820)', async () => {
    const calls = mockFetchQueueAll();
    setUpDetailedTranslations();
    await loadAndResolveFacets(calls, { genres: genreBuckets(12) });

    const section = document.querySelector('[data-filter-section="genres"]');
    dispatchClick(section.querySelector('.anime-list__filter-section-more'));
    await flushMicrotasks();
    expect(section.querySelectorAll('.anime-list__filter-value')).toHaveLength(12);
    expect(section.querySelector('.anime-list__filter-section-more').hidden).toBe(true);

    // A facets refresh unrelated to this section — the same one a debounced main-title search
    // keystroke fires — must not collapse it back.
    document.getElementById('anime-list-search').dispatchEvent(new Event('input'));
    jest.advanceTimersByTime(300);
    await flushMicrotasks();
    byKind(calls, 'list')[1].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    byKind(calls, 'facets')[1].resolve(facetsWith({ genres: genreBuckets(12) }));
    await flushMicrotasks();

    expect(section.querySelectorAll('.anime-list__filter-value')).toHaveLength(12);
});

test('the studios search box appears only once there are more than 20 values, filters client-side and ignores truncation while active (issue #820)', async () => {
    const calls = mockFetchQueueAll();
    setUpDetailedTranslations();
    await loadAndResolveFacets(calls, { studios: studioBuckets(21) });

    const section = document.querySelector('[data-filter-section="studios"]');
    const searchBox = section.querySelector('.anime-list__filter-section-search');
    const searchInput = searchBox.querySelector('.anime-list__filter-section-search-input');
    expect(searchBox.hidden).toBe(false);

    searchInput.value = 'Studio1';
    searchInput.dispatchEvent(new Event('input'));
    await flushMicrotasks();

    const names = Array.from(section.querySelectorAll('.anime-list__filter-value-name')).map((el) => el.textContent);
    // "Studio1" matches Studio1 and Studio10..Studio19 (11 rows) — every match shown at once,
    // "Ещё" disabled regardless of how many that is.
    expect(names.length).toBeGreaterThan(8);
    expect(names.every((name) => name.includes('Studio1'))).toBe(true);
    expect(section.querySelector('.anime-list__filter-section-more').hidden).toBe(true);
});

test('a pinned (applied) studio value stays visible while a search query it does not match is active (issue #820 review)', async () => {
    window.history.replaceState({}, '', '/anime?studios[]=99');
    const calls = mockFetchQueueAll();
    setUpDetailedTranslations();
    await loadAndResolveFacets(calls, { studios: [{ id: 99, name: 'Applied Studio', count: 3 }, ...studioBuckets(21)] });

    const section = document.querySelector('[data-filter-section="studios"]');
    const searchInput = section.querySelector('.anime-list__filter-section-search-input');
    searchInput.value = 'Studio1'; // matches none of "Applied Studio", only the unpinned Studio1x rows
    searchInput.dispatchEvent(new Event('input'));
    await flushMicrotasks();

    const rows = Array.from(section.querySelectorAll('.anime-list__filter-value'));
    const pinnedRow = rows.find((row) => row.dataset.value === '99');
    expect(pinnedRow).toBeDefined();
    expect(pinnedRow.querySelector('.anime-list__filter-value-name').textContent).toBe('Applied Studio');
    // Every other visible row still went through the search filter — the pinned row is the sole
    // exception, not a sign the filter stopped applying altogether.
    const unpinnedNames = rows.filter((row) => row !== pinnedRow).map((row) => row.querySelector('.anime-list__filter-value-name').textContent);
    expect(unpinnedNames.every((name) => name.includes('Studio1'))).toBe(true);
});

test('the studios search box hides and drops its query once a fresher response has 20 or fewer values (issue #820)', async () => {
    const calls = mockFetchQueueAll();
    setUpDetailedTranslations();
    await loadAndResolveFacets(calls, { studios: studioBuckets(21) });

    const section = document.querySelector('[data-filter-section="studios"]');
    const searchBox = section.querySelector('.anime-list__filter-section-search');
    const searchInput = searchBox.querySelector('.anime-list__filter-section-search-input');
    searchInput.value = 'Studio1';
    searchInput.dispatchEvent(new Event('input'));
    await flushMicrotasks();

    document.getElementById('anime-list-search').dispatchEvent(new Event('input'));
    jest.advanceTimersByTime(300);
    await flushMicrotasks();
    byKind(calls, 'list')[1].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    byKind(calls, 'facets')[1].resolve(facetsWith({ studios: studioBuckets(20) }));
    await flushMicrotasks();

    expect(searchBox.hidden).toBe(true);
    expect(searchInput.value).toBe('');
});

test('the header of a collapsed section with applied values shows the applied count, "Жанры · 2" style (issue #820)', async () => {
    window.history.replaceState({}, '', '/anime?genres[]=g0&genres[]=g1');
    const calls = mockFetchQueueAll();
    setUpDetailedTranslations();
    await loadAndResolveFacets(calls, { genres: genreBuckets(3) });

    const countEl = document.querySelector('[data-filter-section="genres"] .anime-list__filter-section-count');
    expect(countEl.hidden).toBe(false);
    expect(countEl.textContent).toBe(' · 2');
});

test('an applied studio with a server-guaranteed zero-count bucket stays pinned above a truncated section (issue #820)', async () => {
    window.history.replaceState({}, '', '/anime?studios[]=99');
    const calls = mockFetchQueueAll();
    setUpDetailedTranslations();
    // Simulates AnimeRepository::withGuaranteedEntityBuckets(): the applied studio dropped out of
    // the current selection (narrowed to zero elsewhere) but the server still returns its bucket
    // with count: 0 and its name, instead of the row silently disappearing. studioBuckets(21) alone
    // makes the section both search-eligible and truncation-eligible, so this also proves the
    // pinned row survives sorting/truncation, not just name resolution (which predates this PR).
    await loadAndResolveFacets(calls, { studios: [{ id: 99, name: 'Applied Studio', count: 0 }, ...studioBuckets(21)] });

    const section = document.querySelector('[data-filter-section="studios"]');
    const rows = Array.from(section.querySelectorAll('.anime-list__filter-value'));
    expect(rows[0].dataset.value).toBe('99');
    expect(rows[0].querySelector('.anime-list__filter-value-name').textContent).toBe('Applied Studio');
    expect(rows[0].querySelector('.anime-list__filter-value-count').textContent).toBe('0');
    expect(rows[0].classList.contains('anime-list__filter-value--applied')).toBe(true);
    expect(document.querySelector('.anime-list__chip-label').textContent).toBe('anime_list.filter_section_studios: Applied Studio');
});

test('the header count for a section without a server-side empty-bucket guarantee (watch_status) stays visible once its applied value falls out of a facets response after being unchecked-but-not-applied (issue #820)', async () => {
    window.history.replaceState({}, '', '/anime?watch_status[]=plan');
    const calls = mockFetchQueueAll();
    setUpDetailedTranslations();
    await loadAndResolveFacets(calls, { watch_status: [{ value: 'plan', count: 5 }] });

    // Unchecking without pressing Apply drops 'plan' from pendingFilters while appliedFilters keeps
    // it — the only way the section header count and appendMissingPendingEntries's own pending-only
    // synthesis can actually disagree.
    const section = document.querySelector('[data-filter-section="watch_status"]');
    const row = Array.from(section.querySelectorAll('.anime-list__filter-value')).find((candidate) => candidate.dataset.value === 'plan');
    const checkbox = row.querySelector('.anime-list__filter-checkbox');
    checkbox.checked = false;
    checkbox.dispatchEvent(new Event('change'));

    // An unrelated facets refresh (a search debounce) whose response narrows watch_status=plan to
    // zero elsewhere. watch_status/type/user_rating/date_premiere have no
    // AnimeRepository::withGuaranteedValueBuckets() equivalent (GROUP BY never returns an empty
    // group), so the bucket can legitimately be absent entirely rather than count: 0.
    document.getElementById('anime-list-search').dispatchEvent(new Event('input'));
    jest.advanceTimersByTime(300);
    await flushMicrotasks();
    byKind(calls, 'list')[1].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    byKind(calls, 'facets')[1].resolve(facetsWith({ watch_status: [] }));
    await flushMicrotasks();

    const countEl = section.querySelector('.anime-list__filter-section-count');
    expect(countEl.hidden).toBe(false);
    expect(countEl.textContent).toBe(' · 1');
});

test('a pending enum value survives an unrelated facets refresh whose response no longer includes its bucket (issue #820)', async () => {
    const calls = mockFetchQueueAll();
    setUpDetailedTranslations();
    await loadAndResolveFacets(calls, { genres: genreBuckets(12) });

    const section = document.querySelector('[data-filter-section="genres"]');
    dispatchClick(section.querySelector('.anime-list__filter-section-more')); // expand
    await flushMicrotasks();

    const g11Row = Array.from(section.querySelectorAll('.anime-list__filter-value')).find((row) => row.dataset.value === 'g11');
    g11Row.querySelector('.anime-list__filter-checkbox').checked = true;
    g11Row.querySelector('.anime-list__filter-checkbox').dispatchEvent(new Event('change'));
    await flushMicrotasks();

    // An unrelated facets refresh (the same debounced main-title search keystroke as elsewhere in
    // this suite) whose response drops g11's bucket entirely rather than narrowing it to zero.
    document.getElementById('anime-list-search').dispatchEvent(new Event('input'));
    jest.advanceTimersByTime(300);
    await flushMicrotasks();
    byKind(calls, 'list')[1].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    byKind(calls, 'facets')[1].resolve(facetsWith({ genres: genreBuckets(11) })); // g11 no longer present
    await flushMicrotasks();

    const survivingRow = Array.from(section.querySelectorAll('.anime-list__filter-value')).find((row) => row.dataset.value === 'g11');
    expect(survivingRow).toBeDefined();
    expect(survivingRow.querySelector('.anime-list__filter-value-count').textContent).toBe('0');
    expect(survivingRow.querySelector('.anime-list__filter-checkbox').checked).toBe(true);
});

test('a pending studio value survives an unrelated facets refresh via its name cached from the previous response (issue #820)', async () => {
    const calls = mockFetchQueueAll();
    setUpDetailedTranslations();
    await loadAndResolveFacets(calls, { studios: studioBuckets(21) });

    const section = document.querySelector('[data-filter-section="studios"]');
    const row = Array.from(section.querySelectorAll('.anime-list__filter-value')).find((candidate) => candidate.dataset.value === '1');
    row.querySelector('.anime-list__filter-checkbox').checked = true;
    row.querySelector('.anime-list__filter-checkbox').dispatchEvent(new Event('change'));
    await flushMicrotasks();

    // An unrelated facets refresh whose response drops studio id 1's bucket entirely — unlike an
    // enum value, its name cannot be derived from the id itself, only from
    // AnimeListFilterPanel.entityNameCache, populated by the previous response.
    document.getElementById('anime-list-search').dispatchEvent(new Event('input'));
    jest.advanceTimersByTime(300);
    await flushMicrotasks();
    byKind(calls, 'list')[1].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    byKind(calls, 'facets')[1].resolve(facetsWith({ studios: studioBuckets(20).filter((bucket) => bucket.id !== 1) }));
    await flushMicrotasks();

    const survivingRow = Array.from(section.querySelectorAll('.anime-list__filter-value')).find((candidate) => candidate.dataset.value === '1');
    expect(survivingRow).toBeDefined();
    expect(survivingRow.querySelector('.anime-list__filter-value-name').textContent).toBe('Studio0');
    expect(survivingRow.querySelector('.anime-list__filter-value-count').textContent).toBe('0');
    expect(survivingRow.querySelector('.anime-list__filter-checkbox').checked).toBe(true);
});

test('clicking a section toggle persists the collapsed sections via POST /settings/filter-sections without a page reload (issue #820)', async () => {
    const calls = mockFetchQueueAll();
    setUpDetailedTranslations();
    await loadAndResolveFacets(calls, {});

    const toggle = document.querySelector('[data-filter-section="genres"] .anime-list__filter-section-toggle');
    dispatchClick(toggle);
    await flushMicrotasks();

    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    const persistCall = global.fetch.mock.calls.find(([url]) => url === '/settings/filter-sections');
    expect(persistCall).toBeDefined();
    const [, options] = persistCall;
    expect(options.method).toBe('POST');
    expect(JSON.parse(options.body)).toEqual({ token: 'test-csrf-token', collapsed: ['genres'] });
});

test('collapsedSections is seeded from the server-rendered aria-expanded, and toggling a different section preserves it (issue #820)', async () => {
    document.querySelector('[data-filter-section="studios"] .anime-list__filter-section-toggle').setAttribute('aria-expanded', 'false');
    const calls = mockFetchQueueAll();
    setUpDetailedTranslations();
    await loadAndResolveFacets(calls, {});

    const persistCalls = () => calls.filter((call) => call.url === '/settings/filter-sections');
    const lastPersistBody = () => JSON.parse(global.fetch.mock.calls.filter(([url]) => url === '/settings/filter-sections').pop()[1].body);

    const genresToggle = document.querySelector('[data-filter-section="genres"] .anime-list__filter-section-toggle');
    dispatchClick(genresToggle); // collapse genres, on top of the seeded "studios" collapse
    await flushMicrotasks();

    expect(persistCalls()).toHaveLength(1);
    expect(lastPersistBody().collapsed.slice().sort()).toEqual(['genres', 'studios']);
    persistCalls()[0].resolve({ ok: true, status: 204, json: () => Promise.resolve(null) });
    await flushMicrotasks();

    dispatchClick(genresToggle); // expand genres back
    await flushMicrotasks();

    expect(persistCalls()).toHaveLength(2);
    expect(lastPersistBody().collapsed).toEqual(['studios']);
});

test('a section-toggle click while a persist POST is still in flight is queued rather than sent immediately, then fires with the final state once the first settles (issue #820 review)', async () => {
    const calls = mockFetchQueueAll();
    setUpDetailedTranslations();
    await loadAndResolveFacets(calls, {});

    const persistCalls = () => calls.filter((call) => call.url === '/settings/filter-sections');
    const lastPersistBody = () => JSON.parse(global.fetch.mock.calls.filter(([url]) => url === '/settings/filter-sections').pop()[1].body);

    const genresToggle = document.querySelector('[data-filter-section="genres"] .anime-list__filter-section-toggle');
    const studiosToggle = document.querySelector('[data-filter-section="studios"] .anime-list__filter-section-toggle');

    dispatchClick(genresToggle); // collapse genres — POST #1 goes out, left unresolved
    await flushMicrotasks();
    expect(persistCalls()).toHaveLength(1);

    dispatchClick(studiosToggle); // collapse studios while POST #1 is still in flight
    await flushMicrotasks();

    // Exactly one request in flight: the second click must not fire its own POST yet, or the two
    // writes could land out of order in config.json (persistRequestInFlight's own reason for being).
    expect(persistCalls()).toHaveLength(1);

    persistCalls()[0].resolve({ ok: true, status: 204, json: () => Promise.resolve(null) });
    await flushMicrotasks();

    // Once POST #1 settles, the queued click's own request goes out — carrying the up-to-date
    // state (both sections), not the stale state POST #1 was built from.
    expect(persistCalls()).toHaveLength(2);
    expect(lastPersistBody().collapsed.slice().sort()).toEqual(['genres', 'studios']);
});

test('the filter panel does not manage a --anime-list-filters-top offset any more (issue #995)', async () => {
    const calls = mockFetchQueueAll();
    setUpTranslations();

    loadAnimeListModule();
    await flushMicrotasks();
    byKind(calls, 'list')[0].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    byKind(calls, 'facets')[0].resolve(emptyFacets());
    await flushMicrotasks();

    const panel = document.getElementById('anime-list-filters');
    panel.getBoundingClientRect = jest.fn(() => ({ top: 96 }));
    window.dispatchEvent(new Event('scroll'));
    window.dispatchEvent(new Event('resize'));
    jest.advanceTimersByTime(16);
    document.getElementById('anime-list-filters-toggle').dispatchEvent(new Event('click', { bubbles: true }));
    document.getElementById('anime-list-filters-toggle').dispatchEvent(new Event('click', { bubbles: true }));

    expect(panel.style.getPropertyValue('--anime-list-filters-top')).toBe('');
    expect(panel.getBoundingClientRect).not.toHaveBeenCalled();
});
