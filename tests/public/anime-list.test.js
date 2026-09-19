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
// with fetch() and window.AppTranslations replaced by controllable test doubles. The module is a
// self-invoking browser script with no exports, so it is driven the same way a page would: by
// requiring it (which fires the initial loadPage() call) and, for the overlap scenario, by
// dispatching an `input` event on the search box to trigger a second loadPage() call.

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

function setUpDom(columns = 1) {
    document.body.innerHTML = `
        <input id="anime-list-search" type="search" />
        <div id="anime-list-sort">
            <button type="button" data-sort-field="name">Name</button>
            <button type="button" data-sort-field="date_update" aria-current="true">Updated</button>
            <button type="button" data-sort-field="user_rating">Rating</button>
            <button type="button" data-sort-field="date_premiere">Premiere</button>
            <button type="button" data-sort-field="date_end">End</button>
            <button type="button" id="anime-list-sort-direction" data-direction="desc"
                data-label-asc="Ascending" data-label-desc="Descending">↓</button>
        </div>
        <div id="anime-list-grid"></div>
        <p id="anime-list-empty" hidden></p>
        <p id="anime-list-error" hidden></p>
        <nav id="anime-list-pagination" hidden></nav>
        <div id="anime-list-sentinel" hidden></div>
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

// Queues one deferred per fetch() call to the anime list endpoint, so the test controls exactly
// when each request settles instead of racing against real timers or network I/O. A signal
// listener rejects the call with an AbortError, mirroring what a real fetch() does once its
// AbortController is aborted.
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
        calls.push(call);

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

async function flushMicrotasks() {
    for (let i = 0; i < 10; i += 1) {
        await Promise.resolve();
    }
}

function loadAnimeListModule() {
    jest.isolateModules(() => {
        require('../../app/public/js/anime-list.js');
    });
}

function cardTitles(grid) {
    return Array.from(grid.querySelectorAll('.anime-card__title')).map((node) => node.textContent);
}

// jsdom does not implement IntersectionObserver either. The infinite-scroll sentinel it would
// watch is never intersected in these tests, so a no-op stand-in is enough — only its
// construction (setupInfiniteScroll()) needs to not throw.
function mockIntersectionObserver() {
    global.IntersectionObserver = class {
        observe() {}

        disconnect() {}
    };
}

let resizeObserverInstances;

beforeEach(() => {
    jest.resetModules();
    jest.useFakeTimers();
    setUpDom();
    resizeObserverInstances = mockResizeObserver();
    mockIntersectionObserver();
});

afterEach(() => {
    jest.useRealTimers();
    delete global.fetch;
    delete global.ResizeObserver;
    delete global.IntersectionObserver;
    delete window.AppTranslations;
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

    dispatchClick(document.querySelector('[data-sort-field="name"]'));
    await flushMicrotasks();

    expect(calls).toHaveLength(2);
    expect(queryParams(calls[1].url)).toMatchObject({ sort: 'name', direction: 'desc', offset: '0' });
    expect(document.querySelector('[data-sort-field="name"]').getAttribute('aria-current')).toBe('true');
    expect(document.querySelector('[data-sort-field="date_update"]').hasAttribute('aria-current')).toBe(false);
});

test('toggling sort direction flips desc/asc, reloads and updates the button label', async () => {
    const calls = mockFetchQueue();
    setUpTranslations();
    loadAnimeListModule();
    await flushMicrotasks();
    calls[0].resolve(jsonResponse({ items: [], pagination_mode: 'classic', total: 0, limit: 6, offset: 0 }));
    await flushMicrotasks();

    const directionButton = document.getElementById('anime-list-sort-direction');
    dispatchClick(directionButton);
    await flushMicrotasks();

    expect(calls).toHaveLength(2);
    expect(queryParams(calls[1].url).direction).toBe('asc');
    expect(directionButton.textContent).toBe('↑');
    expect(directionButton.getAttribute('aria-label')).toBe('Ascending');
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

    // newLimit = 10 × 6 = 60; the record at index 30 now falls on page floor(30/60)+1 = 1.
    triggerResize(resizeObserverInstances, 10);
    await flushMicrotasks();

    expect(calls).toHaveLength(3);
    expect(queryParams(calls[2].url)).toMatchObject({ offset: '0', limit: '60' });
});
