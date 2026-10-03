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

// Loads the real downloads-list.js against markup shaped like downloads/index.html.twig. Pins the
// two behavioral invariants the issue calls for (#854): no more than one request in flight at a
// time (the setTimeout chain only schedules its next tick once the previous fetch() has settled),
// and polling stops while the tab is hidden, resuming immediately once it is visible again.
require('../../app/assets/js/controller.js');

const POLL_INTERVAL_MS = 2000;

function setUpDom() {
    document.body.innerHTML = `
        <main data-control="downloads-list" data-status-url="/downloads/status" data-no-card-label="No card">
            <div data-downloads-banner hidden></div>
            <table data-downloads-table>
                <tbody data-downloads-rows></tbody>
            </table>
        </main>
    `;
}

function mountControls() {
    document.body.dispatchEvent(new CustomEvent('htmx:load', { bubbles: true, detail: { elt: document.body } }));
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

function emptyStatusResponse() {
    return { qbittorrentAvailable: true, rows: [], orphans: [] };
}

function jsonResponse(body) {
    return { ok: true, status: 200, json: () => Promise.resolve(body) };
}

// One deferred per fetch() call — the test resolves them explicitly rather than letting a real
// network round-trip settle, and a signal listener rejects with an AbortError the same way a real
// fetch() does once its AbortController fires.
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

async function flushMicrotasks() {
    for (let i = 0; i < 10; i += 1) {
        await Promise.resolve();
    }
}

function setDocumentHidden(hidden) {
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => hidden });
    document.dispatchEvent(new Event('visibilitychange'));
}

beforeEach(() => {
    jest.resetModules();
    jest.useFakeTimers();
    setDocumentHidden(false);
});

afterEach(() => {
    jest.useRealTimers();
    delete global.fetch;
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => false });
});

function loadDownloadsListModule() {
    jest.isolateModules(() => {
        require('../../app/assets/js/downloads-list.js');
    });
    setUpDom();
    mountControls();
}

test('mounting immediately issues the first status request', () => {
    const calls = mockFetchQueue();

    loadDownloadsListModule();

    expect(global.fetch).toHaveBeenCalledTimes(1);
    expect(calls[0].url).toBe('/downloads/status');
});

test('the next request is not sent until the previous one has resolved, even past the poll interval', async () => {
    const calls = mockFetchQueue();
    loadDownloadsListModule();
    expect(global.fetch).toHaveBeenCalledTimes(1);

    // The interval alone must not be enough to fire a second request while the first is still
    // in flight — this is the whole point of the setTimeout chain over a plain setInterval.
    jest.advanceTimersByTime(POLL_INTERVAL_MS * 3);
    expect(global.fetch).toHaveBeenCalledTimes(1);

    calls[0].resolve(jsonResponse(emptyStatusResponse()));
    await flushMicrotasks();

    // Only once the first call settles does the chain schedule (and, after the interval, send)
    // the next one.
    expect(global.fetch).toHaveBeenCalledTimes(1);
    jest.advanceTimersByTime(POLL_INTERVAL_MS);
    expect(global.fetch).toHaveBeenCalledTimes(2);
});

test('polling stops while the tab is hidden and resumes immediately once it becomes visible again', async () => {
    const calls = mockFetchQueue();
    loadDownloadsListModule();
    calls[0].resolve(jsonResponse(emptyStatusResponse()));
    await flushMicrotasks();
    expect(global.fetch).toHaveBeenCalledTimes(1);

    setDocumentHidden(true);

    // No matter how much time passes while hidden, no further request is scheduled or sent.
    jest.advanceTimersByTime(POLL_INTERVAL_MS * 5);
    expect(global.fetch).toHaveBeenCalledTimes(1);

    setDocumentHidden(false);

    // Visibility returning resumes polling right away, not after waiting out a fresh interval.
    expect(global.fetch).toHaveBeenCalledTimes(2);
});

test('an in-flight request is aborted when the tab becomes hidden', async () => {
    const calls = mockFetchQueue();
    loadDownloadsListModule();

    setDocumentHidden(true);

    await flushMicrotasks();
    await expect(calls[0].promise).rejects.toMatchObject({ name: 'AbortError' });
});

test('a successful response renders rows and reveals the qBittorrent-unavailable banner when reported unavailable', async () => {
    const calls = mockFetchQueue();
    loadDownloadsListModule();

    calls[0].resolve(jsonResponse({
        qbittorrentAvailable: false,
        rows: [{
            infoHash:          'a'.repeat(40),
            hasCard:           true,
            animeUrl:          '/anime/1',
            displayName:       'Some anime',
            statusText:        'Waiting',
            sizeText:          null,
            progressText:      null,
            downloadSpeedText: null,
            uploadSpeedText:   null,
            etaText:           null,
            peersText:         null,
            targetStorageName: 'Main folder',
        }],
        orphans: [{
            infoHash:          'b'.repeat(40),
            hasCard:           false,
            animeUrl:          null,
            displayName:       'Orphan torrent',
            statusText:        'No card',
            sizeText:          '1.0 MB',
            progressText:      '10%',
            downloadSpeedText: '0 B/s',
            uploadSpeedText:   '0 B/s',
            etaText:           null,
            peersText:         '1/0',
            targetStorageName: null,
        }],
    }));
    await flushMicrotasks();

    const banner = document.querySelector('[data-downloads-banner]');
    expect(banner.hidden).toBe(false);

    const rows = document.querySelectorAll('[data-downloads-rows] tr');
    expect(rows).toHaveLength(2);
    expect(rows[0].querySelector('a').getAttribute('href')).toBe('/anime/1');
    expect(rows[0].textContent).toContain('Some anime');
    expect(rows[1].textContent).toContain('Orphan torrent');
    expect(rows[1].textContent).toContain('No card');
});
