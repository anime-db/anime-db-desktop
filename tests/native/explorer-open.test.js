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
    parseExplorerOpenArg, buildExplorerOpenUrl, createExplorerOpen, SHELL_EXTRA_HEADERS,
} = require('../../native/explorer-open');

describe('parseExplorerOpenArg', () => {
    test('returns the path of an ordinary folder', () => {
        expect(parseExplorerOpenArg(['AnimeDB.exe', '--explorer-open=C:\\Video\\My Anime'])).toBe('C:\\Video\\My Anime');
    });

    test('restores a drive root mangled by CommandLineToArgvW', () => {
        expect(parseExplorerOpenArg(['AnimeDB.exe', '--explorer-open=D:"'])).toBe('D:\\');
    });

    test('returns null without the switch or with an empty value', () => {
        expect(parseExplorerOpenArg(['AnimeDB.exe'])).toBeNull();
        expect(parseExplorerOpenArg(['AnimeDB.exe', '--explorer-open='])).toBeNull();
    });
});

describe('createExplorerOpen', () => {
    const setup = (port = 8000) => {
        const state = { port };
        const win = { isDestroyed: () => false, loadURL: jest.fn() };
        const nav = createExplorerOpen({ getPort: () => state.port, getWindow: () => state.win });
        state.win = win;
        return { state, win, nav };
    };

    test('a path requested before the window has loaded is kept and opened afterwards', () => {
        const { win, nav } = setup();

        nav.request('C:\\Video');
        expect(win.loadURL).not.toHaveBeenCalled();

        nav.markReady();
        expect(win.loadURL).toHaveBeenCalledTimes(1);
        expect(win.loadURL).toHaveBeenCalledWith(
            'http://127.0.0.1:8000/explorer/open?path=C%3A%5CVideo',
            { extraHeaders: 'X-AnimeDB-Shell: 1\n' },
        );
    });

    test('a path requested while there is no window yet is not lost', () => {
        const { state, win, nav } = setup();
        state.win = null;

        nav.request('C:\\Video');
        state.win = win;
        nav.markReady();

        expect(win.loadURL).toHaveBeenCalledTimes(1);
    });

    test('the URL uses the port that is live at navigation time', () => {
        const { state, win, nav } = setup(8000);
        nav.markReady();

        state.port = 9123; // reloadForPlugin() restarted the backend on another port
        nav.request('D:\\');

        expect(win.loadURL.mock.calls[0][0]).toBe(buildExplorerOpenUrl(9123, 'D:\\'));
        expect(win.loadURL.mock.calls[0][1]).toEqual({ extraHeaders: SHELL_EXTRA_HEADERS });
    });

    test('nothing is navigated while the backend is down, and a path is opened once', () => {
        const { state, win, nav } = setup(null);
        nav.markReady();
        nav.request('C:\\a');
        expect(win.loadURL).not.toHaveBeenCalled();

        state.port = 8000;
        nav.request('C:\\b');
        nav.markReady();
        expect(win.loadURL).toHaveBeenCalledTimes(1);
    });
});
