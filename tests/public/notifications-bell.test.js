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

// Loads the real notifications-bell.js against a DOM shaped like the header bell of
// base.html.twig (issue #1025). window.animeDb.onUnreadCount() is replaced by a spy that captures
// the callback the module registers, as app-notifications.test.js does for onNotification().
require('../../app/assets/js/controller.js');

function mountControls(root = document.body) {
    root.dispatchEvent(new CustomEvent('htmx:load', { bubbles: true, detail: { elt: root } }));
}

function setUpDom(count = 2) {
    document.body.innerHTML = `
        <div id="app-notifications" data-control="app-notifications"></div>
        <template id="app-notification-template"><div class="app-notification"></div></template>
        <div class="app-nav__bell" data-control="notifications-bell" data-list-url="/notifications/recent"
             data-mark-read-url="/notifications/read" data-read-all-url="/notifications/read-all" data-csrf-token="tok"
             data-label="Notifications" data-label-unread="Notifications, unread: %count%">
            <button type="button" aria-label="Notifications"><span data-bell-badge${count === 0 ? ' hidden' : ''}>${count}</span></button>
            <div data-bell-list data-error="Load failed"></div>
        </div>
    `;
}

function loadModules() {
    jest.isolateModules(() => {
        require('../../app/assets/js/app-notifications.js');
        require('../../app/assets/js/notifications-bell.js');
    });
    mountControls();
}

const badge = () => document.querySelector('[data-bell-badge]');
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

beforeEach(() => {
    jest.resetModules();
    setUpDom();
});

afterEach(() => {
    delete window.animeDb;
    delete global.fetch;
});

test('unread_count from the IPC channel updates the badge without a toast or a request', () => {
    let deliver;
    const onNotification = jest.fn();
    window.animeDb = { onNotification, onUnreadCount: jest.fn((callback) => { deliver = callback; }) };
    global.fetch = jest.fn();
    loadModules();

    deliver(5);

    expect(badge().textContent).toBe('5');
    expect(badge().hidden).toBe(false);
    expect(document.getElementById('app-notifications').children).toHaveLength(0);
    expect(global.fetch).not.toHaveBeenCalled();
});

test('a zero unread_count hides the badge', () => {
    let deliver;
    window.animeDb = { onUnreadCount: jest.fn((callback) => { deliver = callback; }) };
    loadModules();

    deliver(0);

    expect(badge().hidden).toBe(true);
});

test('mounting without window.animeDb does not throw and keeps the rendered count', () => {
    loadModules();

    expect(badge().textContent).toBe('2');
    expect(badge().hidden).toBe(false);
});

test('opening the menu loads the list and marks exactly the shown unread records as read', async () => {
    const calls = [];
    global.fetch = jest.fn((url, options) => {
        calls.push([url, options]);
        if (url === '/notifications/recent') {
            return Promise.resolve({
                ok: true,
                text: () => Promise.resolve('<ul><li data-notification-id="7" data-notification-unread></li><li data-notification-id="8"></li><li data-notification-id="9" data-notification-unread></li></ul>'),
            });
        }

        return Promise.resolve({ ok: true, json: () => Promise.resolve({ unread_count: 1 }) });
    });
    loadModules();

    document.querySelector('[data-control="notifications-bell"]')
        .dispatchEvent(new CustomEvent('show.bs.dropdown', { bubbles: true }));
    await flush();
    await flush();

    const post = calls.find(([url]) => url === '/notifications/read');
    expect(post[1].method).toBe('POST');
    expect(post[1].body.getAll('ids[]')).toEqual(['7', '9']);
    expect(post[1].body.get('_token')).toBe('tok');
    expect(badge().textContent).toBe('1');
});

test('a failed list request shows the error text', async () => {
    global.fetch = jest.fn(() => Promise.resolve({ ok: false, status: 500 }));
    loadModules();

    document.querySelector('[data-control="notifications-bell"]')
        .dispatchEvent(new CustomEvent('show.bs.dropdown', { bubbles: true }));
    await flush();

    expect(document.querySelector('[data-bell-list]').textContent).toBe('Load failed');
});

const LIST_HTML = '<ul><li data-notification-id="7" data-notification-unread></li></ul>';

function openMenu() {
    document.querySelector('[data-control="notifications-bell"]')
        .dispatchEvent(new CustomEvent('show.bs.dropdown', { bubbles: true }));
}

test('a failed mark-read request keeps the loaded list', async () => {
    global.fetch = jest.fn((url) => {
        if (url === '/notifications/recent') {
            return Promise.resolve({ ok: true, text: () => Promise.resolve(LIST_HTML) });
        }

        return Promise.resolve({ ok: false, status: 400 });
    });
    loadModules();

    openMenu();
    await flush();
    await flush();
    await flush();

    const list = document.querySelector('[data-bell-list]');
    expect(list.querySelector('[data-notification-id="7"]')).not.toBeNull();
    expect(list.textContent).not.toBe('Load failed');
    expect(badge().textContent).toBe('2');
});

test('read all clears the unread marks and updates the badge', async () => {
    global.fetch = jest.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ unread_count: 0 }) }));
    loadModules();
    const list = document.querySelector('[data-bell-list]');
    list.innerHTML = '<button type="button" data-bell-read-all></button><li data-notification-unread class="notification-item--unread"></li>';

    list.querySelector('[data-bell-read-all]').click();
    await flush();
    await flush();

    expect(global.fetch.mock.calls[0][0]).toBe('/notifications/read-all');
    expect(badge().hidden).toBe(true);
    expect(list.querySelector('[data-notification-unread]')).toBeNull();
});

test('a failed read all keeps the list', async () => {
    global.fetch = jest.fn(() => Promise.resolve({ ok: false, status: 400 }));
    loadModules();
    const list = document.querySelector('[data-bell-list]');
    list.innerHTML = '<button type="button" data-bell-read-all></button><li data-notification-unread></li>';

    list.querySelector('[data-bell-read-all]').click();
    await flush();
    await flush();

    expect(list.querySelector('[data-notification-unread]')).not.toBeNull();
    expect(list.textContent).not.toBe('Load failed');
});

test('the button accessible name follows the unread count', () => {
    let deliver;
    window.animeDb = { onUnreadCount: jest.fn((callback) => { deliver = callback; }) };
    loadModules();
    const button = document.querySelector('button');

    deliver(3);
    expect(button.getAttribute('aria-label')).toBe('Notifications, unread: 3');

    deliver(0);
    expect(button.getAttribute('aria-label')).toBe('Notifications');
});
