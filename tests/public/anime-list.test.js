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

function setUpDom() {
    document.body.innerHTML = `
        <input id="anime-list-search" type="search" />
        <div id="anime-list-grid"></div>
        <p id="anime-list-empty" hidden></p>
        <p id="anime-list-error" hidden></p>
        <nav id="anime-list-pagination" hidden></nav>
        <div id="anime-list-sentinel" hidden></div>
    `;
}

// Queues one deferred per fetch() call to the anime list endpoint, so the test controls exactly
// when each request settles instead of racing against real timers or network I/O. A signal
// listener rejects the call with an AbortError, mirroring what a real fetch() does once its
// AbortController is aborted.
function mockFetchQueue() {
    const calls = [];

    global.fetch = jest.fn((url, options) => {
        const call = deferred();
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

beforeEach(() => {
    jest.resetModules();
    jest.useFakeTimers();
    setUpDom();
});

afterEach(() => {
    jest.useRealTimers();
    delete global.fetch;
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
