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

// Loads the real download-new.js against a DOM shaped like app/templates/downloads/new.html.twig,
// with fetch() replaced by a controllable test double. See anime-list.test.js for why
// controller.js is required once at file scope rather than per test.
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

function setUpDom() {
    document.body.innerHTML = `
        <main data-control="download-new">
            <input type="hidden" id="download-new-anime-id" value="">
            <input type="text" id="download-new-anime-search" autocomplete="off">
            <ul id="download-new-anime-results" hidden></ul>

            <div id="download-new-drop-zone">
                <input type="file" id="download-new-file">
            </div>
        </main>
    `;
}

function mockFetchQueue() {
    const calls = [];

    global.fetch = jest.fn((url) => {
        const call = deferred();
        call.url = url;
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

function loadDownloadNewModule() {
    jest.isolateModules(() => {
        require('../../app/assets/js/download-new.js');
    });
    mountControls();
}

function typeSearch(value) {
    const searchInput = document.getElementById('download-new-anime-search');
    searchInput.value = value;
    searchInput.dispatchEvent(new Event('input'));
    jest.advanceTimersByTime(300);
}

beforeEach(() => {
    jest.resetModules();
    jest.useFakeTimers();
    setUpDom();
});

afterEach(() => {
    jest.useRealTimers();
    delete global.fetch;
});

test('mousedown on a result keeps the search field focused and fills the hidden anime id', async () => {
    const calls = mockFetchQueue();
    loadDownloadNewModule();

    const searchInput = document.getElementById('download-new-anime-search');
    searchInput.focus();
    typeSearch('attack');
    await flushMicrotasks();

    expect(calls).toHaveLength(1);
    calls[0].resolve(jsonResponse({ items: [{ id: 42, title: 'Attack on Titan' }] }));
    await flushMicrotasks();

    const entry = document.querySelector('#download-new-anime-results li');
    expect(entry).not.toBeNull();

    // A real press-and-hold is mousedown, then (after the hold) mouseup and click. The list must
    // be chosen on mousedown, before any of that later sequence — a blur in between would already
    // have hidden it by the time click fires (issue reported for #890).
    entry.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));

    expect(document.activeElement).toBe(searchInput);
    expect(document.getElementById('download-new-anime-id').value).toBe('42');
    expect(document.getElementById('download-new-anime-search').value).toBe('Attack on Titan');
    expect(document.getElementById('download-new-anime-results').hidden).toBe(true);
});

test('a slower response to an earlier query does not overwrite the results of a later one', async () => {
    const calls = mockFetchQueue();
    loadDownloadNewModule();

    typeSearch('a');
    await flushMicrotasks();
    typeSearch('attack');
    await flushMicrotasks();

    expect(calls).toHaveLength(2);

    // The second request ("attack") settles first, the first ("a") arrives late — exactly the
    // out-of-order race the request-id guard in download-new.js defends against.
    calls[1].resolve(jsonResponse({ items: [{ id: 2, title: 'Attack on Titan' }] }));
    await flushMicrotasks();
    calls[0].resolve(jsonResponse({ items: [{ id: 1, title: 'Aria' }] }));
    await flushMicrotasks();

    const titles = Array.from(document.querySelectorAll('#download-new-anime-results li')).map((node) => node.textContent);
    expect(titles).toEqual(['Attack on Titan']);
});
