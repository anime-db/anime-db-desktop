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

// Loads the real nav-add-menu.js against a DOM shaped like base.html.twig's "Add" menu (issue
// #834). bootstrap.bundle.min.js itself is not loaded here — only its one static method this
// module calls, Dropdown.getOrCreateInstance().update(), is stubbed, since the point under test
// is the lazy-fetch contract, not Bootstrap's own dropdown behavior.
require('../../app/assets/js/controller.js');

function mountControls(root = document.body) {
    root.dispatchEvent(new CustomEvent('htmx:load', { bubbles: true, detail: { elt: root } }));
}

function setUpDom() {
    document.body.innerHTML = `
        <div class="dropdown" data-control="nav-add-menu" data-scan-section-url="/nav/add-menu/scan-section">
            <button type="button" data-bs-toggle="dropdown">Add</button>
            <ul class="dropdown-menu">
                <li data-nav-scan-section>loading…</li>
            </ul>
        </div>
    `;
}

function loadNavAddMenuModule() {
    jest.isolateModules(() => {
        require('../../app/assets/js/nav-add-menu.js');
    });
    mountControls();
}

async function flushMicrotasks() {
    for (let i = 0; i < 10; i += 1) {
        await Promise.resolve();
    }
}

function showDropdown() {
    document.querySelector('[data-bs-toggle="dropdown"]').dispatchEvent(new Event('show.bs.dropdown'));
}

beforeEach(() => {
    jest.resetModules();
    window.bootstrap = { Dropdown: { getOrCreateInstance: jest.fn(() => ({ update: jest.fn() })) } };
});

afterEach(() => {
    delete global.fetch;
    delete window.bootstrap;
});

test('opening the menu for the first time on the page fetches the scan section', async () => {
    global.fetch = jest.fn(() => Promise.resolve({ ok: true, text: () => Promise.resolve('<li>Scan "Main"</li>') }));
    setUpDom();
    loadNavAddMenuModule();

    showDropdown();
    await flushMicrotasks();

    expect(global.fetch).toHaveBeenCalledTimes(1);
    expect(global.fetch).toHaveBeenCalledWith('/nav/add-menu/scan-section');
    expect(document.querySelector('.dropdown-menu').textContent).toContain('Scan "Main"');
});

test('reopening the menu on the same page does not fetch the scan section again', async () => {
    global.fetch = jest.fn(() => Promise.resolve({ ok: true, text: () => Promise.resolve('<li>Scan "Main"</li>') }));
    setUpDom();
    loadNavAddMenuModule();

    showDropdown();
    await flushMicrotasks();
    showDropdown();
    await flushMicrotasks();

    expect(global.fetch).toHaveBeenCalledTimes(1);
});

test('the dropdown is repositioned once the scan section has loaded', async () => {
    global.fetch = jest.fn(() => Promise.resolve({ ok: true, text: () => Promise.resolve('<li>Scan "Main"</li>') }));
    setUpDom();
    loadNavAddMenuModule();

    showDropdown();
    await flushMicrotasks();

    expect(window.bootstrap.Dropdown.getOrCreateInstance).toHaveBeenCalledWith(document.querySelector('[data-bs-toggle="dropdown"]'));
});

test('a failed fetch clears the loading placeholder instead of leaving the spinner stuck', async () => {
    global.fetch = jest.fn(() => Promise.resolve({ ok: false, status: 500 }));
    setUpDom();
    loadNavAddMenuModule();

    showDropdown();
    await flushMicrotasks();

    expect(document.querySelector('[data-nav-scan-section]')).toBeNull();
});
