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

// Loads the real storage-new.js against a DOM shaped like storage/new.html.twig's form (issue
// #165). The pick-folder button is wired to window.animeDb.pickFolder() only for a writable
// StorageType and only inside Electron (window.animeDb exposed by native/window/preload.js) —
// outside Electron, or for a non-writable type, the path field stays a plain text input, same as
// the window.animeDb guards in app-notifications.js/anime-detail.js.
//
// controller.js is required once at file scope, not inside loadStorageNewModule() — see
// controller.test.js for why a fresh require() per test would leak document-level listeners.
require('../../app/assets/js/controller.js');

function mountControls(root = document.body) {
    root.dispatchEvent(new CustomEvent('htmx:load', { bubbles: true, detail: { elt: root } }));
}

function setUpDom(writableTypes = 'local') {
    document.body.innerHTML = `
        <form data-control="storage-new">
            <select id="storage-new-type" data-writable-types="${writableTypes}">
                <option value="local">local</option>
                <option value="remote">remote</option>
            </select>
            <input type="text" id="storage-new-name" value="">
            <input type="text" id="storage-new-path" value="">
            <button type="button" id="storage-new-pick-folder" hidden></button>
        </form>
    `;
}

function loadStorageNewModule() {
    jest.isolateModules(() => {
        require('../../app/assets/js/storage-new.js');
    });
    mountControls();
}

async function flushMicrotasks() {
    for (let i = 0; i < 10; i += 1) {
        await Promise.resolve();
    }
}

beforeEach(() => {
    jest.resetModules();
});

afterEach(() => {
    delete window.animeDb;
    delete global.fetch;
});

function hintResponse(name) {
    return Promise.resolve({ ok: true, json: () => Promise.resolve({ name }) });
}

function typePath(value) {
    const path = document.getElementById('storage-new-path');
    path.value = value;
    path.dispatchEvent(new Event('change'));
}

test('inside Electron, the pick-folder button is shown for the writable type and clicking it fills the path field', async () => {
    setUpDom('local');
    window.animeDb = { pickFolder: jest.fn(() => Promise.resolve('/media/anime')) };
    loadStorageNewModule();

    expect(document.getElementById('storage-new-pick-folder').hidden).toBe(false);

    document.getElementById('storage-new-pick-folder').click();
    await flushMicrotasks();

    expect(window.animeDb.pickFolder).toHaveBeenCalledTimes(1);
    expect(document.getElementById('storage-new-path').value).toBe('/media/anime');
});

test('outside Electron, the pick-folder button stays hidden even for a writable type', () => {
    setUpDom('local');
    loadStorageNewModule();

    expect(document.getElementById('storage-new-pick-folder').hidden).toBe(true);
});

test('inside Electron, a non-writable storage type keeps the pick-folder button hidden', () => {
    setUpDom('local');
    window.animeDb = { pickFolder: jest.fn() };
    loadStorageNewModule();

    const typeSelect = document.getElementById('storage-new-type');
    typeSelect.value = 'remote';
    typeSelect.dispatchEvent(new Event('change'));

    expect(document.getElementById('storage-new-pick-folder').hidden).toBe(true);
});

test('switching back to a writable type shows the button again', () => {
    setUpDom('local');
    window.animeDb = { pickFolder: jest.fn() };
    loadStorageNewModule();

    const typeSelect = document.getElementById('storage-new-type');
    typeSelect.value = 'remote';
    typeSelect.dispatchEvent(new Event('change'));
    expect(document.getElementById('storage-new-pick-folder').hidden).toBe(true);

    typeSelect.value = 'local';
    typeSelect.dispatchEvent(new Event('change'));
    expect(document.getElementById('storage-new-pick-folder').hidden).toBe(false);
});

test('choosing "cancel" from the folder dialog leaves the path field untouched', async () => {
    setUpDom('local');
    window.animeDb = { pickFolder: jest.fn(() => Promise.resolve(null)) };
    loadStorageNewModule();
    document.getElementById('storage-new-path').value = '/keep/me';

    document.getElementById('storage-new-pick-folder').click();
    await flushMicrotasks();

    expect(document.getElementById('storage-new-path').value).toBe('/keep/me');
});

test('a not-applicable type disables and clears the path field, returning restores the typed value', () => {
    document.body.innerHTML = `
        <form data-control="storage-new">
            <select id="storage-new-type" data-path-not-applicable-types="video">
                <option value="local">local</option>
                <option value="video">video</option>
            </select>
            <input type="text" id="storage-new-path" value="">
            <button type="button" id="storage-new-pick-folder" hidden></button>
            <p id="storage-new-path-not-applicable" hidden></p>
        </form>
    `;
    loadStorageNewModule();

    const typeSelect = document.getElementById('storage-new-type');
    const path = document.getElementById('storage-new-path');
    const hint = document.getElementById('storage-new-path-not-applicable');
    path.value = '/media/anime';

    typeSelect.value = 'video';
    typeSelect.dispatchEvent(new Event('change'));
    expect(path.disabled).toBe(true);
    expect(path.value).toBe('');
    expect(hint.hidden).toBe(false);

    typeSelect.value = 'local';
    typeSelect.dispatchEvent(new Event('change'));
    expect(path.disabled).toBe(false);
    expect(path.value).toBe('/media/anime');
    expect(hint.hidden).toBe(true);
});

describe('name hint', () => {
    const nameField = () => document.getElementById('storage-new-name');

    test('picking a folder requests the hint with the shell header and fills the empty name', async () => {
        setUpDom('local');
        window.animeDb = { pickFolder: jest.fn(() => Promise.resolve('D:\\')) };
        global.fetch = jest.fn(() => hintResponse('Backup'));
        loadStorageNewModule();

        document.getElementById('storage-new-pick-folder').click();
        await flushMicrotasks();

        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(global.fetch.mock.calls[0][0]).toBe('/storage/new/name-hint?path=' + encodeURIComponent('D:\\'));
        expect(global.fetch.mock.calls[0][1].headers['X-AnimeDB-Shell']).toBe('1');
        expect(nameField().value).toBe('Backup');
    });

    test('a manual change of the path requests the hint', async () => {
        setUpDom('local');
        global.fetch = jest.fn(() => hintResponse('anime'));
        loadStorageNewModule();

        typePath('/media/anime');
        await flushMicrotasks();

        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(nameField().value).toBe('anime');
    });

    test('only the answer to the last request is applied', async () => {
        setUpDom('local');
        const resolvers = [];
        global.fetch = jest.fn(() => new Promise((resolve) => resolvers.push(resolve)));
        loadStorageNewModule();

        typePath('/first');
        typePath('/second');
        resolvers[1]({ ok: true, json: () => Promise.resolve({ name: 'second' }) });
        await flushMicrotasks();
        resolvers[0]({ ok: true, json: () => Promise.resolve({ name: 'first' }) });
        await flushMicrotasks();

        expect(nameField().value).toBe('second');
    });

    test('a name typed by a person is never overwritten', async () => {
        setUpDom('local');
        global.fetch = jest.fn(() => hintResponse('anime'));
        loadStorageNewModule();
        nameField().value = 'My own';

        typePath('/media/anime');
        await flushMicrotasks();

        expect(nameField().value).toBe('My own');
    });

    test('the previous hint is replaced, and cleared when the new answer is null', async () => {
        setUpDom('local');
        const answers = ['one', 'two', null];
        global.fetch = jest.fn(() => hintResponse(answers.shift()));
        loadStorageNewModule();

        for (const expected of ['one', 'two', '']) {
            typePath('/p');
            await flushMicrotasks();
            expect(nameField().value).toBe(expected);
        }
    });

    test('a prefilled path with an empty name requests the hint on load and fills the name', async () => {
        setUpDom('local');
        document.getElementById('storage-new-path').value = 'D:\\';
        global.fetch = jest.fn(() => hintResponse('Backup'));
        loadStorageNewModule();
        await flushMicrotasks();

        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(global.fetch.mock.calls[0][0]).toBe('/storage/new/name-hint?path=D%3A%5C');
        expect(global.fetch.mock.calls[0][1].headers['X-AnimeDB-Shell']).toBe('1');
        expect(nameField().value).toBe('Backup');
    });

    test('a name rendered by the server does not trigger a request on load', async () => {
        setUpDom('local');
        document.getElementById('storage-new-path').value = '/media/anime';
        nameField().value = 'anime';
        nameField().dataset.nameHint = 'anime';
        global.fetch = jest.fn(() => hintResponse('Other'));
        loadStorageNewModule();
        await flushMicrotasks();

        expect(global.fetch).not.toHaveBeenCalled();
        expect(nameField().value).toBe('anime');
    });

    test('the server-rendered hint from the data attribute is replaced on a path change', async () => {
        setUpDom('local');
        nameField().value = 'Rendered';
        nameField().dataset.nameHint = 'Rendered';
        global.fetch = jest.fn(() => hintResponse('Other'));
        loadStorageNewModule();

        typePath('/other');
        await flushMicrotasks();

        expect(nameField().value).toBe('Other');
    });
});
