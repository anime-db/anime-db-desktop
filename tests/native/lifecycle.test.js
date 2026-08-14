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

jest.mock('electron', () => ({
    app: {
        requestSingleInstanceLock: jest.fn(() => true),
        on:         jest.fn(),
        whenReady:  jest.fn(() => Promise.resolve()),
        quit:       jest.fn(),
        exit:       jest.fn(),
    },
    dialog:  { showErrorBox: jest.fn() },
    session: { defaultSession: {} },
}));
jest.mock('../../native/protocols/app-media', () => ({}));
jest.mock('../../native/accept-language', () => ({}));
jest.mock('../../native/shell', () => ({}));
jest.mock('../../native/dialog', () => ({}));
jest.mock('../../native/supervisor', () => ({
    start:    jest.fn(() => Promise.resolve({ frankenphpPort: 8000, wsPort: 8001 })),
    stop:     jest.fn(() => Promise.resolve()),
    killSync: jest.fn(),
    events:   { on: jest.fn() },
}));
jest.mock('../../native/window', () => ({ createWindow: jest.fn() }));
jest.mock('../../native/window/splash', () => ({ createSplash: jest.fn() }));
jest.mock('../../native/tray', () => ({ create: jest.fn(), setState: jest.fn() }));
jest.mock('../../native/ws-client', () => ({ connect: jest.fn(), disconnect: jest.fn(), on: jest.fn() }));
jest.mock('../../native/proxy', () => ({
    registerProxyAuthHandler: jest.fn(),
    applyProxy:                jest.fn(() => Promise.resolve()),
    PROXY_CHANGED_EVENT:       'proxy.changed',
}));
jest.mock('../../native/firewall', () => ({
    applyIncomingConnections:      jest.fn(() => Promise.resolve()),
    FIREWALL_RULE_CHANGED_EVENT:   'firewall.rule.changed',
}));

/**
 * Loads a fresh copy of lifecycle/index.js and every mocked dependency it pulls in, recording the
 * handlers it registers via app.on()/process.on() instead of letting them actually attach to the
 * real process — the module registers its startup logic as a side effect of being required, so
 * each test needs its own isolated instance of both the module and its mocks.
 *
 * @returns {object}
 */
function loadLifecycle() {
    jest.resetModules();

    const appHandlers     = {};
    const processHandlers = {};

    jest.spyOn(process, 'on').mockImplementation((event, handler) => {
        processHandlers[event] = handler;
        return process;
    });

    const { app, dialog }  = require('electron');
    app.on.mockImplementation((event, handler) => { appHandlers[event] = handler; });

    const supervisor  = require('../../native/supervisor');
    const { createWindow } = require('../../native/window');
    const { createSplash } = require('../../native/window/splash');
    const tray        = require('../../native/tray');
    const wsClient     = require('../../native/ws-client');
    const proxy        = require('../../native/proxy');
    const firewall      = require('../../native/firewall');

    const fakeWindow = {
        isMinimized: jest.fn(() => false),
        show:        jest.fn(),
        hide:        jest.fn(),
        focus:       jest.fn(),
        restore:     jest.fn(),
        on:          jest.fn(),
    };
    createWindow.mockReturnValue(fakeWindow);

    const fakeSplash = {
        once:         jest.fn((event, cb) => cb()),
        show:         jest.fn(),
        close:        jest.fn(),
        isDestroyed:  jest.fn(() => false),
        webContents:  { send: jest.fn() },
    };
    createSplash.mockReturnValue(fakeSplash);

    require('../../native/lifecycle');

    return {
        app, dialog, supervisor, createWindow, createSplash, tray, wsClient, proxy, firewall,
        appHandlers, processHandlers, fakeWindow, fakeSplash,
    };
}

afterEach(() => {
    jest.restoreAllMocks();
});

describe('single-instance lock', () => {
    test('a second instance quits immediately without registering startup handlers', () => {
        jest.resetModules();
        jest.spyOn(process, 'on').mockImplementation(() => process);

        const { app } = require('electron');
        app.requestSingleInstanceLock.mockReturnValue(false);
        const { registerProxyAuthHandler } = require('../../native/proxy');

        require('../../native/lifecycle');

        expect(app.quit).toHaveBeenCalledTimes(1);
        expect(app.on).not.toHaveBeenCalledWith('second-instance', expect.any(Function));
        expect(registerProxyAuthHandler).not.toHaveBeenCalled();
    });

    test('the primary instance registers a second-instance handler and starts the supervisor', async () => {
        const { supervisor, proxy } = loadLifecycle();

        expect(proxy.registerProxyAuthHandler).toHaveBeenCalledTimes(1);
        await new Promise((r) => setTimeout(r, 500));
        expect(supervisor.start).toHaveBeenCalledTimes(1);
    });

    test('a second launch attempt shows, restores and focuses the existing window', async () => {
        const { appHandlers, fakeWindow } = loadLifecycle();

        await new Promise((r) => setTimeout(r, 500));

        fakeWindow.isMinimized.mockReturnValue(true);
        appHandlers['second-instance']();

        expect(fakeWindow.restore).toHaveBeenCalledTimes(1);
        expect(fakeWindow.show).toHaveBeenCalledTimes(1);
        expect(fakeWindow.focus).toHaveBeenCalledTimes(1);
    });

    test('a second-instance signal before the window exists is a no-op, not a crash', () => {
        const { appHandlers, fakeWindow } = loadLifecycle();

        expect(() => appHandlers['second-instance']()).not.toThrow();
        expect(fakeWindow.show).not.toHaveBeenCalled();
    });
});

describe('abnormal-exit cleanup', () => {
    test('process exit runs a synchronous best-effort kill of all child processes', () => {
        const { processHandlers, supervisor } = loadLifecycle();

        processHandlers.exit();

        expect(supervisor.killSync).toHaveBeenCalledTimes(1);
    });

    test('SIGTERM/SIGINT trigger the same graceful quit path as the tray "Выход" action', () => {
        const { processHandlers, app } = loadLifecycle();

        processHandlers.SIGTERM();
        expect(app.quit).toHaveBeenCalledTimes(1);

        processHandlers.SIGINT();
        expect(app.quit).toHaveBeenCalledTimes(2);
    });

    test('an uncaught exception force-kills children and exits without waiting for graceful stop()', () => {
        const { processHandlers, supervisor, app } = loadLifecycle();

        processHandlers.uncaughtException(new Error('boom'));

        expect(supervisor.killSync).toHaveBeenCalledTimes(1);
        expect(app.exit).toHaveBeenCalledWith(1);
    });
});
