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

// Keyboard behaviour of the anime card (issue #1010): Escape in the in-place edit forms, the
// labels editor and the "Files" menu, and where focus lands after the card re-renders.
require('../../app/assets/js/controller.js');
require('../../app/assets/js/focus-restore.js');

function mountControls(root = document.body) {
    root.dispatchEvent(new CustomEvent('htmx:load', { bubbles: true, detail: { elt: root } }));
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

function press(target, key) {
    const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true });
    target.dispatchEvent(event);

    return event;
}

beforeEach(() => {
    jest.resetModules();
});

afterEach(() => {
    delete global.fetch;
    delete window.animeDb;
    delete window.htmx;
});

describe('in-place edit forms', () => {
    function setUpForm() {
        document.body.innerHTML = `
            <section id="anime-editable-1">
                <form class="anime-detail__inline-form" data-focus-return="anime-progress-value-1">
                    <input type="number" name="watched_episodes" autofocus>
                    <button type="submit">Save</button>
                    <button type="button" data-inline-cancel>Cancel</button>
                </form>
            </section>
        `;
        loadAnimeDetailModule();
    }

    test('Escape in the open form presses its Cancel button', () => {
        setUpForm();
        const cancel = document.querySelector('[data-inline-cancel]');
        const onCancel = jest.fn();
        cancel.addEventListener('click', onCancel);

        const event = press(document.querySelector('input'), 'Escape');

        expect(onCancel).toHaveBeenCalledTimes(1);
        expect(event.defaultPrevented).toBe(true);
    });

    test('Escape elsewhere does nothing', () => {
        setUpForm();
        const onCancel = jest.fn();
        document.querySelector('[data-inline-cancel]').addEventListener('click', onCancel);

        press(document.body, 'Escape');

        expect(onCancel).not.toHaveBeenCalled();
    });

    test('after the swap, focus returns to the value button named by the form', () => {
        setUpForm();
        document.querySelector('input').focus();

        document.dispatchEvent(new CustomEvent('htmx:beforeSwap'));
        document.getElementById('anime-editable-1').innerHTML = '<button id="anime-progress-value-1">1 / 12</button>';
        document.dispatchEvent(new CustomEvent('htmx:afterSettle'));

        expect(document.activeElement).toBe(document.getElementById('anime-progress-value-1'));
    });

    test('the first present id wins, so notes return to Add once they were emptied', () => {
        document.body.innerHTML = `
            <section id="anime-notes-1">
                <form class="anime-detail__inline-form" data-focus-return="anime-notes-edit-1 anime-notes-add-1">
                    <textarea autofocus></textarea>
                </form>
            </section>
            <div id="slot"></div>
        `;
        loadAnimeDetailModule();
        document.querySelector('textarea').focus();

        document.dispatchEvent(new CustomEvent('htmx:beforeSwap'));
        document.getElementById('anime-notes-1').innerHTML = '';
        document.getElementById('slot').innerHTML = '<button id="anime-notes-add-1">Add</button>';
        document.dispatchEvent(new CustomEvent('htmx:afterSettle'));

        expect(document.activeElement).toBe(document.getElementById('anime-notes-add-1'));
    });
});

describe('labels editor', () => {
    function setUpLabels(labels = [{ id: 1, name: 'One' }, { id: 2, name: 'Two' }, { id: 3, name: 'Three' }]) {
        document.body.innerHTML = `
            <section data-control="labels-widget" data-labels='${JSON.stringify(labels)}'
                     data-update-url="/u" data-search-url="/s" data-csrf-token="t">
                <button type="button" data-labels-edit>Edit</button>
                <template id="icon-x-lg"><svg></svg></template>
                <ul data-labels-view></ul>
                <div data-labels-editor hidden>
                    <ul data-labels-chips></ul>
                    <input type="text" data-labels-input>
                    <ul data-labels-suggestions hidden></ul>
                    <p data-labels-error hidden></p>
                    <button type="button" data-labels-save>Save</button>
                    <button type="button" data-labels-cancel>Cancel</button>
                </div>
            </section>
        `;
        loadAnimeDetailModule();
        document.querySelector('[data-labels-edit]').click();
    }

    const input = () => document.querySelector('[data-labels-input]');
    const editor = () => document.querySelector('[data-labels-editor]');

    test('Escape closes the editor and focus returns to the edit button', () => {
        setUpLabels();
        input().focus();

        press(input(), 'Escape');

        expect(editor().hidden).toBe(true);
        expect(document.activeElement).toBe(document.querySelector('[data-labels-edit]'));
    });

    test('Escape with the suggestion list open closes only the list', () => {
        setUpLabels();
        const suggestions = document.querySelector('[data-labels-suggestions]');
        suggestions.hidden = false;
        suggestions.innerHTML = '<li><button type="button">Opt</button></li>';
        input().focus();

        press(input(), 'Escape');

        expect(suggestions.hidden).toBe(true);
        expect(editor().hidden).toBe(false);
        expect(document.activeElement).toBe(input());
    });

    test('removing a chip with the button moves focus to the next chip, the previous one at the end, then the input', () => {
        setUpLabels();
        const removeButtons = () => Array.from(document.querySelectorAll('.anime-detail__labels-chip-remove'));

        removeButtons()[1].focus();
        removeButtons()[1].click();
        expect(document.activeElement).toBe(removeButtons()[1]);
        expect(document.activeElement.getAttribute('data-focus-key')).toBe('chip-remove:Three');

        removeButtons()[1].focus();
        removeButtons()[1].click();
        expect(document.activeElement).toBe(removeButtons()[0]);

        removeButtons()[0].focus();
        removeButtons()[0].click();
        expect(removeButtons()).toHaveLength(0);
        expect(document.activeElement).toBe(input());
    });
});

describe('"Files" block', () => {
    function setUpFiles(inner = '') {
        document.body.innerHTML = `
            <section id="anime-files-1" data-control="files-link" data-token="t" data-link-url="/l" data-unlink-url="/ul"
                     data-request-failed="failed" data-video-extensions="mkv" data-video-filter-name="Video">
                <details class="anime-detail__files-menu">
                    <summary>Link…</summary>
                    <button type="button" data-files-pick="folder">Folder</button>
                </details>
                ${inner}
            </section>
        `;
        window.animeDb = { pickFolder: jest.fn(() => Promise.resolve('/media/a')), pickFile: jest.fn() };
        loadAnimeDetailModule();
    }

    test('Escape closes the menu and focus returns to its summary', () => {
        setUpFiles();
        const menu = document.querySelector('details');
        menu.open = true;
        document.querySelector('[data-files-pick]').focus();

        press(document.querySelector('[data-files-pick]'), 'Escape');

        expect(menu.open).toBe(false);
        expect(document.activeElement).toBe(menu.querySelector('summary'));
    });

    function mockSwap(html) {
        window.htmx = {
            swap: jest.fn((root, markup) => {
                root.outerHTML = markup;
            }),
        };
        global.fetch = jest.fn(() => Promise.resolve({ ok: true, status: 200, text: () => Promise.resolve(html) }));
    }

    async function pickFolder() {
        document.querySelector('[data-files-pick]').click();
        await flushMicrotasks();
    }

    test('after linking, focus goes to the menu of the new block', async () => {
        setUpFiles();
        mockSwap('<section id="anime-files-1"><details class="anime-detail__files-menu"><summary>Change…</summary></details></section>');

        await pickFolder();

        expect(document.activeElement).toBe(document.querySelector('.anime-detail__files-menu > summary'));
    });

    test('an error message in the new block receives focus instead', async () => {
        setUpFiles();
        mockSwap('<section id="anime-files-1"><p class="anime-detail__files-message anime-detail__files-message--error" role="alert">No</p>'
            + '<details class="anime-detail__files-menu"><summary>Link…</summary></details></section>');

        await pickFolder();

        expect(document.activeElement).toBe(document.querySelector('.anime-detail__files-message--error'));
    });
});
