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

const {
    createNotificationDelivery, createShownTracker, createToastClickHandler, applyAppUserModelId, APP_USER_MODEL_ID, UNREAD_COUNT_CHANNEL,
} = require('../../native/notifications');

function setup({ focused = false, visible = true, minimized = false, enabled = true, supported = true, noWindow = false } = {}) {
    const win = {
        isDestroyed: jest.fn(() => false),
        isVisible:   jest.fn(() => visible),
        isMinimized: jest.fn(() => minimized),
        isFocused:   jest.fn(() => focused),
        webContents: { send: jest.fn() },
    };
    const toasts = [];
    const Notification = jest.fn(function (options) {
        this.options = options;
        this.handlers = {};
        this.on = (event, cb) => { this.handlers[event] = cb; };
        this.show = jest.fn();
        this.close = jest.fn();
        toasts.push(this);
    });
    Notification.isSupported = jest.fn(() => supported);
    const onToastClick = jest.fn();
    const delivery = createNotificationDelivery({
        getWindow: () => (noWindow ? null : win),
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

    test('focused but minimized window gets a toast, not a popup', () => {
        const { delivery, toasts, win } = setup({ focused: true, minimized: true });
        delivery.handle(event());

        expect(toasts).toHaveLength(1);
        expect(win.webContents.send).not.toHaveBeenCalledWith('app-notification', expect.anything());
    });

    test('no window: toast is shown, nothing is sent, no exception', () => {
        const { delivery, toasts, win } = setup({ noWindow: true });
        expect(() => delivery.handle(event())).not.toThrow();

        expect(toasts).toHaveLength(1);
        expect(win.webContents.send).not.toHaveBeenCalled();
    });

    test('unsupported notifications: no toast, no exception', () => {
        const { delivery, toasts, Notification } = setup({ supported: false });
        expect(() => delivery.handle(event())).not.toThrow();

        expect(toasts).toHaveLength(0);
        expect(Notification).not.toHaveBeenCalled();
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

describe('live toast retention', () => {
    test('a click after close still reaches onToastClick', () => {
        const { delivery, toasts, onToastClick } = setup();
        delivery.handle(event());
        toasts[0].handlers.close?.();
        toasts[0].handlers.click();

        expect(onToastClick).toHaveBeenCalledWith('/x');
    });

    test('close does not release the toast: closeAll still closes it', () => {
        const { delivery, toasts } = setup();
        delivery.handle(event());
        toasts[0].handlers.close?.();
        delivery.closeAll();

        expect(toasts[0].close).toHaveBeenCalledTimes(1);
    });

    test.each(['click', 'failed'])('%s releases the toast', (name) => {
        const { delivery, toasts } = setup();
        jest.spyOn(console, 'error').mockImplementation(() => {});
        delivery.handle(event());
        toasts[0].handlers[name]({}, new Error('x'));
        delivery.closeAll();

        expect(toasts[0].close).not.toHaveBeenCalled();
    });

    test('failed logs the error', () => {
        const { delivery, toasts } = setup();
        const spy = jest.spyOn(console, 'error').mockImplementation(() => {});
        delivery.handle(event());
        const err = new Error('boom');
        toasts[0].handlers.failed({}, err);

        expect(spy).toHaveBeenCalledWith('[notifications] toast failed:', err);
        spy.mockRestore();
    });

    test('the 51st toast evicts the oldest without closing it', () => {
        const { delivery, toasts } = setup();
        for (let i = 1; i <= 51; i++) delivery.handle(event({ id: i }));
        delivery.closeAll();

        expect(toasts[0].close).not.toHaveBeenCalled();
        for (let i = 1; i < 51; i++) expect(toasts[i].close).toHaveBeenCalledTimes(1);
    });

    test('a grown occurrences count closes the previous toast before showing the new one', () => {
        const { delivery, toasts } = setup();
        delivery.handle(event());
        delivery.handle(event({ occurrences: 2 }));

        expect(toasts).toHaveLength(2);
        expect(toasts[0].close).toHaveBeenCalledTimes(1);
        expect(toasts[0].close.mock.invocationCallOrder[0]).toBeLessThan(toasts[1].show.mock.invocationCallOrder[0]);
        delivery.closeAll();
        expect(toasts[1].close).toHaveBeenCalledTimes(1);
        expect(toasts[0].close).toHaveBeenCalledTimes(1);
    });

    test('a replayed pair neither closes nor recreates the toast', () => {
        const { delivery, toasts } = setup();
        delivery.handle(event());
        delivery.handle(event());

        expect(toasts).toHaveLength(1);
        expect(toasts[0].close).not.toHaveBeenCalled();
    });

    test('closeAll closes every toast and empties the map', () => {
        const { delivery, toasts } = setup();
        delivery.handle(event({ id: 1 }));
        delivery.handle(event({ id: 2 }));
        delivery.closeAll();
        delivery.closeAll();

        expect(toasts[0].close).toHaveBeenCalledTimes(1);
        expect(toasts[1].close).toHaveBeenCalledTimes(1);
    });

    test('late click/failed of a replaced toast keep the new toast', () => {
        const { delivery, toasts } = setup();
        jest.spyOn(console, 'error').mockImplementation(() => {});
        delivery.handle(event());
        delivery.handle(event({ occurrences: 2 }));
        toasts[0].handlers.click();
        toasts[0].handlers.failed({}, new Error('x'));
        delivery.closeAll();

        expect(toasts[1].close).toHaveBeenCalledTimes(1);
    });

    test('a replaced toast becomes the newest in the eviction order', () => {
        const { delivery, toasts } = setup();
        for (let i = 1; i <= 50; i++) delivery.handle(event({ id: i }));
        delivery.handle(event({ id: 1, occurrences: 2 }));
        delivery.handle(event({ id: 51 }));
        toasts.forEach((t) => t.close.mockClear());
        delivery.closeAll();

        const closed = toasts.filter((t) => t.close.mock.calls.length > 0);
        expect(closed).toHaveLength(50);
        expect(toasts[1].close).not.toHaveBeenCalled(); // id 2 evicted
        expect(toasts[50].close).toHaveBeenCalledTimes(1); // replacement of id 1 survives
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

describe('toast click handler', () => {
    const BASE = 'http://127.0.0.1:8000';

    function setupClick({ destroyed = false, minimized = false } = {}) {
        const win = {
            isDestroyed: jest.fn(() => destroyed),
            isMinimized: jest.fn(() => minimized),
            restore:     jest.fn(),
            show:        jest.fn(),
            focus:       jest.fn(),
            loadURL:     jest.fn(),
        };
        const click = createToastClickHandler({ getWindow: () => win, getBaseUrl: () => BASE });

        return { win, click };
    }

    test('a relative path is opened on the local backend', () => {
        const { win, click } = setupClick();
        click('/anime/1');
        expect(win.loadURL).toHaveBeenCalledWith(`${BASE}/anime/1`);
        expect(win.show).toHaveBeenCalled();
        expect(win.focus).toHaveBeenCalled();
    });

    test('an absolute url on the same origin is opened', () => {
        const { win, click } = setupClick();
        click(`${BASE}/anime/2?x=1`);
        expect(win.loadURL).toHaveBeenCalledWith(`${BASE}/anime/2?x=1`);
    });

    test.each([
        'https://evil.example/',
        '//evil.example/x',
        'javascript:alert(1)',
        'file:///C:/Windows/win.ini',
        'http://127.0.0.1:9999/',
        'http://localhost:8000/',
    ])('foreign url %s is not loaded, the window is still shown and focused', (url) => {
        const { win, click } = setupClick();
        click(url);
        expect(win.loadURL).not.toHaveBeenCalled();
        expect(win.show).toHaveBeenCalled();
        expect(win.focus).toHaveBeenCalled();
    });

    test('an invalid url does not throw and is not loaded', () => {
        const { win, click } = setupClick();
        expect(() => click('http://')).not.toThrow();
        expect(win.loadURL).not.toHaveBeenCalled();
    });

    test('a null url only shows and focuses the window, restoring it when minimized', () => {
        const { win, click } = setupClick({ minimized: true });
        click(null);
        expect(win.restore).toHaveBeenCalled();
        expect(win.show).toHaveBeenCalled();
        expect(win.focus).toHaveBeenCalled();
        expect(win.loadURL).not.toHaveBeenCalled();
    });

    test('a destroyed window is left alone', () => {
        const { win, click } = setupClick({ destroyed: true });
        click('/anime/1');
        expect(win.show).not.toHaveBeenCalled();
        expect(win.loadURL).not.toHaveBeenCalled();
    });
});

describe('AppUserModelID', () => {
    const { build } = require('../../package.json');

    test('the constant matches build.appId of the installer', () => {
        expect(APP_USER_MODEL_ID).toBeTruthy();
        expect(APP_USER_MODEL_ID).toBe(build.appId);
    });

    test('win32 sets the id', () => {
        const app = { setAppUserModelId: jest.fn() };
        applyAppUserModelId(app, 'win32', APP_USER_MODEL_ID);
        expect(app.setAppUserModelId).toHaveBeenCalledWith(APP_USER_MODEL_ID);
    });

    test.each(['linux', 'darwin'])('%s does not set it', (platform) => {
        const app = { setAppUserModelId: jest.fn() };
        applyAppUserModelId(app, platform, APP_USER_MODEL_ID);
        expect(app.setAppUserModelId).not.toHaveBeenCalled();
    });
});
