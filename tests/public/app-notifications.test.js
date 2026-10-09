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

// Loads the real app-notifications.js against a DOM shaped like base.html.twig's notifications
// container and <template> (issue #417). window.animeDb.onNotification() is replaced by a spy that
// captures the showNotification callback the module registers, the same way scan.js's
// window.ScanWatcher.watch is driven in storage-scan.test.js.
//
// controller.js is required once at file scope, not inside loadAppNotificationsModule() — see
// controller.test.js for why a fresh require() per test would leak document-level listeners.
require('../../app/assets/js/controller.js');

function mountControls(root = document.body) {
    root.dispatchEvent(new CustomEvent('htmx:load', { bubbles: true, detail: { elt: root } }));
}

function setUpDom() {
    document.body.innerHTML = `
        <div id="app-notifications" data-control="app-notifications" data-request-error="Action failed" aria-live="polite"></div>
        <template id="app-notification-template">
            <div class="app-notification" role="alert">
                <div class="app-notification__text">
                    <strong class="app-notification__title"></strong>
                    <p class="app-notification__message"></p>
                </div>
                <button type="button" class="app-notification__close">&times;</button>
            </div>
        </template>
    `;
}

function loadAppNotificationsModule() {
    jest.isolateModules(() => {
        require('../../app/assets/js/app-notifications.js');
    });
    mountControls();
}

beforeEach(() => {
    jest.resetModules();
    setUpDom();
});

afterEach(() => {
    delete window.animeDb;
});

test('a notification delivered via onNotification() renders its title and message in the container', () => {
    let deliver;
    window.animeDb = { onNotification: jest.fn((callback) => { deliver = callback; }) };
    loadAppNotificationsModule();

    deliver({ title: 'Plugin failed', message: 'The plugin could not be activated.' });

    const container = document.getElementById('app-notifications');
    expect(container.children).toHaveLength(1);
    expect(container.querySelector('.app-notification__title').textContent).toBe('Plugin failed');
    expect(container.querySelector('.app-notification__message').textContent)
        .toBe('The plugin could not be activated.');
});

test('several notifications stack up as separate elements', () => {
    let deliver;
    window.animeDb = { onNotification: jest.fn((callback) => { deliver = callback; }) };
    loadAppNotificationsModule();

    deliver({ title: 'First', message: 'One' });
    deliver({ title: 'Second', message: 'Two' });

    expect(document.getElementById('app-notifications').children).toHaveLength(2);
});

test('clicking the close button removes only that notification', () => {
    let deliver;
    window.animeDb = { onNotification: jest.fn((callback) => { deliver = callback; }) };
    loadAppNotificationsModule();

    deliver({ title: 'First', message: 'One' });
    deliver({ title: 'Second', message: 'Two' });
    const container = document.getElementById('app-notifications');
    container.children[0].querySelector('.app-notification__close').click();

    expect(container.children).toHaveLength(1);
    expect(container.querySelector('.app-notification__title').textContent).toBe('Second');
});

// Nothing here can deliver a notification, so "children stays empty" alone would pass under any
// implementation of the guard. What actually distinguishes a correct guard from a missing one is
// that mounting must not throw: controller.js's mountNode() catches mount errors, so an assert
// on console.error is what catches a guard that was dropped or narrowed.
test('outside Electron, mounting is a no-op and does not throw', () => {
    const consoleError = jest.spyOn(console, 'error').mockImplementation(() => {});

    loadAppNotificationsModule();

    expect(document.getElementById('app-notifications').children).toHaveLength(0);
    expect(consoleError).not.toHaveBeenCalled();

    consoleError.mockRestore();
});

test('when window.animeDb has no onNotification, mounting is a no-op and does not throw', () => {
    const consoleError = jest.spyOn(console, 'error').mockImplementation(() => {});
    window.animeDb = {};

    loadAppNotificationsModule();

    expect(document.getElementById('app-notifications').children).toHaveLength(0);
    expect(consoleError).not.toHaveBeenCalled();

    consoleError.mockRestore();
});

test('without the notification template, mounting is a no-op and never calls onNotification', () => {
    document.getElementById('app-notification-template').remove();
    const onNotification = jest.fn();
    window.animeDb = { onNotification };

    loadAppNotificationsModule();

    expect(document.getElementById('app-notifications').children).toHaveLength(0);
    expect(onNotification).not.toHaveBeenCalled();
});

test.each(['htmx:responseError', 'htmx:sendError'])('%s renders the generic failure notification', (eventName) => {
    loadAppNotificationsModule();

    document.body.dispatchEvent(new CustomEvent(eventName, { bubbles: true }));

    const container = document.getElementById('app-notifications');
    expect(container.children).toHaveLength(1);
    expect(container.querySelector('.app-notification__title').textContent).toBe('Action failed');
    expect(container.querySelector('.app-notification__message')).toBeNull();
});
