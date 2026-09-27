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

// Exercises just the "labels-widget" control in anime-detail.js (issue #734) against a minimal DOM
// shaped like the fragment app/templates/anime/show.html.twig renders for the Jira-style labels
// field. Requiring the real module also registers the open-folder-button and catalog-back-link
// controls (see anime-detail-back-link.test.js for the latter), but mounting is a no-op for any
// data-control name not present in this test's DOM.
//
// controller.js is required once at file scope, not inside loadAnimeDetailModule() — see
// controller.test.js for why a fresh require() per test would leak document-level listeners.
require('../../app/assets/js/controller.js');

function mountControls(root = document.body) {
    root.dispatchEvent(new CustomEvent('htmx:load', { bubbles: true, detail: { elt: root } }));
}

function setUpDom(labels = [{ id: 1, name: 'Sci-Fi' }]) {
    document.body.innerHTML = `
        <section class="anime-detail__labels"
                 data-control="labels-widget"
                 data-labels='${JSON.stringify(labels)}'
                 data-update-url="/anime/1/labels"
                 data-search-url="/labels"
                 data-csrf-token="csrf-token">
            <div class="anime-detail__labels-header">
                <h2 class="anime-detail__labels-heading">Labels</h2>
                <button type="button" data-labels-edit>Edit</button>
            </div>
            <ul class="anime-detail__labels-list" data-labels-view></ul>
            <div class="anime-detail__labels-editor" data-labels-editor hidden>
                <ul class="anime-detail__labels-chips" data-labels-chips></ul>
                <input type="text" class="anime-detail__labels-input" data-labels-input>
                <ul class="anime-detail__labels-suggestions" data-labels-suggestions hidden></ul>
                <p class="anime-detail__labels-error" data-labels-error hidden></p>
                <div class="anime-detail__labels-actions">
                    <button type="button" data-labels-save>Save</button>
                    <button type="button" data-labels-cancel>Cancel</button>
                </div>
            </div>
        </section>
    `;
}

function jsonResponse(body) {
    return { ok: true, status: 200, json: () => Promise.resolve(body) };
}

function loadAnimeDetailModule() {
    jest.isolateModules(() => {
        require('../../app/assets/js/anime-detail.js');
    });
    mountControls();
}

async function flushMicrotasks() {
    for (let i = 0; i < 10; i += 1) {
        await Promise.resolve();
    }
}

function openEditor() {
    document.querySelector('[data-labels-edit]').click();
}

function typeInput(text) {
    const input = document.querySelector('[data-labels-input]');
    input.value = text;
    input.dispatchEvent(new Event('input'));
}

function pressKey(key) {
    const input = document.querySelector('[data-labels-input]');
    input.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }));
}

beforeEach(() => {
    jest.resetModules();
});

afterEach(() => {
    delete global.fetch;
});

test('opening the editor pre-fills chips from the existing labels', () => {
    setUpDom([{ id: 1, name: 'Sci-Fi' }, { id: 2, name: 'Drama' }]);
    loadAnimeDetailModule();

    openEditor();

    const chipTexts = Array.from(document.querySelectorAll('[data-labels-chips] li span')).map((el) => el.textContent);
    expect(chipTexts).toEqual(['Sci-Fi', 'Drama']);
});

test('pressing Enter adds a typed chip that does not match any suggestion', async () => {
    setUpDom([]);
    global.fetch = jest.fn(() => Promise.resolve(jsonResponse({ labels: [{ id: 1, name: 'Sci-Fi' }] })));
    loadAnimeDetailModule();

    openEditor();
    typeInput('Brand New Label');
    await flushMicrotasks();
    pressKey('Enter');

    const chipTexts = Array.from(document.querySelectorAll('[data-labels-chips] li span')).map((el) => el.textContent);
    expect(chipTexts).toEqual(['Brand New Label']);
    expect(document.querySelector('[data-labels-input]').value).toBe('');
});

test('clicking a chip\'s remove button removes just that chip', async () => {
    setUpDom([]);
    global.fetch = jest.fn(() => Promise.resolve(jsonResponse({ labels: [] })));
    loadAnimeDetailModule();

    openEditor();
    typeInput('First');
    pressKey('Enter');
    typeInput('Second');
    pressKey('Enter');

    const chips = document.querySelectorAll('[data-labels-chips] li');
    expect(chips).toHaveLength(2);
    chips[0].querySelector('button').click();

    const remaining = Array.from(document.querySelectorAll('[data-labels-chips] li span')).map((el) => el.textContent);
    expect(remaining).toEqual(['Second']);
});

test('typing shows autocomplete suggestions from the fetched catalogue, excluding already-chipped labels', async () => {
    setUpDom([]);
    global.fetch = jest.fn(() => Promise.resolve(jsonResponse({
        labels: [{ id: 1, name: 'Sci-Fi' }, { id: 2, name: 'Sci-Horror' }, { id: 3, name: 'Drama' }],
    })));
    loadAnimeDetailModule();

    openEditor();
    typeInput('sci');
    await flushMicrotasks();

    const suggestions = Array.from(document.querySelectorAll('[data-labels-suggestions] li button')).map((el) => el.textContent);
    expect(suggestions).toEqual(['Sci-Fi', 'Sci-Horror']);
    expect(document.querySelector('[data-labels-suggestions]').hidden).toBe(false);
});

test('clicking a suggestion adds it as a chip', async () => {
    setUpDom([]);
    global.fetch = jest.fn(() => Promise.resolve(jsonResponse({ labels: [{ id: 1, name: 'Sci-Fi' }] })));
    loadAnimeDetailModule();

    openEditor();
    typeInput('sci');
    await flushMicrotasks();
    document.querySelector('[data-labels-suggestions] li button').click();

    const chipTexts = Array.from(document.querySelectorAll('[data-labels-chips] li span')).map((el) => el.textContent);
    expect(chipTexts).toEqual(['Sci-Fi']);
});

// The catalogue is fetched once and reused (issue #779): a personal collection's label set is
// small enough that server-side search per keystroke would be overkill.
test('the label catalogue is fetched only once across multiple keystrokes', async () => {
    setUpDom([]);
    global.fetch = jest.fn(() => Promise.resolve(jsonResponse({ labels: [{ id: 1, name: 'Sci-Fi' }] })));
    loadAnimeDetailModule();

    openEditor();
    typeInput('s');
    await flushMicrotasks();
    typeInput('sc');
    await flushMicrotasks();
    typeInput('sci');
    await flushMicrotasks();

    expect(global.fetch).toHaveBeenCalledTimes(1);
    expect(global.fetch).toHaveBeenCalledWith('/labels');
});

test('cancelling the editor discards unsaved chip changes', () => {
    setUpDom([{ id: 1, name: 'Sci-Fi' }]);
    global.fetch = jest.fn(() => Promise.resolve(jsonResponse({ labels: [] })));
    loadAnimeDetailModule();

    openEditor();
    typeInput('Extra');
    pressKey('Enter');
    document.querySelector('[data-labels-cancel]').click();

    expect(document.querySelector('[data-labels-editor]').hidden).toBe(true);
    const viewTexts = Array.from(document.querySelectorAll('[data-labels-view] li')).map((el) => el.textContent);
    expect(viewTexts).toEqual(['Sci-Fi']);
});

test('saving posts the chip list, including a typed name still sitting in the input, to the update URL', async () => {
    setUpDom([{ id: 1, name: 'Sci-Fi' }]);
    global.fetch = jest.fn(() => Promise.resolve(jsonResponse({ labels: [{ id: 1, name: 'Sci-Fi' }, { id: 2, name: 'Drama' }] })));
    loadAnimeDetailModule();

    openEditor();
    typeInput('Drama');
    document.querySelector('[data-labels-save]').click();
    await flushMicrotasks();

    expect(global.fetch).toHaveBeenCalledWith('/anime/1/labels', expect.objectContaining({
        method: 'POST',
        body:   JSON.stringify({ token: 'csrf-token', names: ['Sci-Fi', 'Drama'] }),
    }));
    const viewTexts = Array.from(document.querySelectorAll('[data-labels-view] li')).map((el) => el.textContent);
    expect(viewTexts).toEqual(['Sci-Fi', 'Drama']);
});
