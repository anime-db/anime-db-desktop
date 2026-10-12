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

const { createNotificationDelivery, createShownTracker, UNREAD_COUNT_CHANNEL } = require('../../native/notifications');

function setup({ focused = false, visible = true, enabled = true, supported = true } = {}) {
    const win = {
        isDestroyed: jest.fn(() => false),
        isVisible:   jest.fn(() => visible),
        isMinimized: jest.fn(() => false),
        isFocused:   jest.fn(() => focused),
        webContents: { send: jest.fn() },
    };
    const toasts = [];
    const Notification = jest.fn(function (options) {
        this.options = options;
        this.handlers = {};
        this.on = (event, cb) => { this.handlers[event] = cb; };
        this.show = jest.fn();
        toasts.push(this);
    });
    Notification.isSupported = jest.fn(() => supported);
    const onToastClick = jest.fn();
    const delivery = createNotificationDelivery({
        getWindow: () => win,
        Notification,
        isSystemNotificationsEnabled: () => enabled,
        onToastClick,
    });

    return { win, toasts, Notification, onToastClick, delivery };
}

const event = (over = {}) => ({
    id: 1, occurrences: 1, kind: 'k', severity: 'info', title: 'T', body: 'B', url: '/x', unread_count: 3, ...over,
});

describe('notification delivery', () => {
    test('replayed (id, occurrences) pair shows neither a second toast nor a second popup, counter still sent', () => {
        const { delivery, toasts, win } = setup();
        delivery.handle(event());
        delivery.handle(event());

        expect(toasts).toHaveLength(1);
        expect(win.webContents.send.mock.calls.filter(([c]) => c === UNREAD_COUNT_CHANNEL)).toHaveLength(2);
        expect(win.webContents.send).not.toHaveBeenCalledWith('app-notification', expect.anything());
    });

    test('replayed pair does not produce a second popup while focused', () => {
        const { delivery, win } = setup({ focused: true });
        delivery.handle(event());
        delivery.handle(event());

        expect(win.webContents.send.mock.calls.filter(([c]) => c === 'app-notification')).toHaveLength(1);
    });

    test('same id with grown occurrences is shown again', () => {
        const { delivery, toasts } = setup();
        delivery.handle(event());
        delivery.handle(event({ occurrences: 2 }));

        expect(toasts).toHaveLength(2);
    });

    test('focused visible window gets an in-app popup and no toast', () => {
        const { delivery, toasts, win } = setup({ focused: true });
        delivery.handle(event());

        expect(toasts).toHaveLength(0);
        expect(win.webContents.send).toHaveBeenCalledWith('app-notification', expect.objectContaining({ title: 'T', message: 'B' }));
        expect(win.webContents.send).toHaveBeenCalledWith(UNREAD_COUNT_CHANNEL, 3);
    });

    test('hidden window gets a toast, counter is still sent, no popup', () => {
        const { delivery, toasts, win } = setup({ visible: false });
        delivery.handle(event());

        expect(toasts[0].options).toEqual({ title: 'T', body: 'B' });
        expect(toasts[0].show).toHaveBeenCalled();
        expect(win.webContents.send).toHaveBeenCalledWith(UNREAD_COUNT_CHANNEL, 3);
        expect(win.webContents.send).not.toHaveBeenCalledWith('app-notification', expect.anything());
    });

    test('disabled setting suppresses the toast but not the popup', () => {
        const hidden = setup({ enabled: false });
        hidden.delivery.handle(event());
        expect(hidden.toasts).toHaveLength(0);

        const focused = setup({ enabled: false, focused: true });
        focused.delivery.handle(event());
        expect(focused.win.webContents.send).toHaveBeenCalledWith('app-notification', expect.anything());
    });

    test('toast click passes the url, or null when there is none', () => {
        const { delivery, toasts, onToastClick } = setup();
        delivery.handle(event());
        delivery.handle(event({ id: 2, url: null }));
        toasts[0].handlers.click();
        toasts[1].handlers.click();

        expect(onToastClick).toHaveBeenNthCalledWith(1, '/x');
        expect(onToastClick).toHaveBeenNthCalledWith(2, null);
    });
});

describe('shown tracker', () => {
    test('evicts the oldest pair beyond the limit', () => {
        const tracker = createShownTracker(2);
        tracker.markShown(1, 1);
        tracker.markShown(2, 1);
        tracker.markShown(3, 1);

        expect(tracker.markShown(1, 1)).toBe(true);
    });
});
