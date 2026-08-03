/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

'use strict';

const handlers = {};

const mockWebContents = {
    on: jest.fn((event, handler) => { handlers[event] = handler; }),
    setWindowOpenHandler: jest.fn((handler) => { handlers.windowOpen = handler; }),
};

jest.mock('electron', () => ({
    BrowserWindow: jest.fn().mockImplementation(() => ({
        webContents: mockWebContents,
        loadURL: jest.fn(),
        on: jest.fn(),
    })),
    shell: { openExternal: jest.fn() },
}));

const { shell } = require('electron');
const { createWindow } = require('../../native/window');

const PORT = 8123;

beforeEach(() => {
    jest.clearAllMocks();
    createWindow(PORT);
});

test('will-navigate to the local backend origin is left untouched', () => {
    const event = { preventDefault: jest.fn() };

    handlers['will-navigate'](event, `http://127.0.0.1:${PORT}/settings`);

    expect(event.preventDefault).not.toHaveBeenCalled();
    expect(shell.openExternal).not.toHaveBeenCalled();
});

test('will-navigate to an external origin is opened in the system browser', () => {
    const event = { preventDefault: jest.fn() };

    handlers['will-navigate'](event, 'https://provider.example/oauth/authorize');

    expect(event.preventDefault).toHaveBeenCalled();
    expect(shell.openExternal).toHaveBeenCalledWith('https://provider.example/oauth/authorize');
});

test('will-redirect to an external origin is opened in the system browser', () => {
    const event = { preventDefault: jest.fn() };

    handlers['will-redirect'](event, 'https://provider.example/oauth/callback');

    expect(event.preventDefault).toHaveBeenCalled();
    expect(shell.openExternal).toHaveBeenCalledWith('https://provider.example/oauth/callback');
});

test('will-redirect within the local backend origin is left untouched', () => {
    const event = { preventDefault: jest.fn() };

    handlers['will-redirect'](event, `http://127.0.0.1:${PORT}/plugins/anilist/callback`);

    expect(event.preventDefault).not.toHaveBeenCalled();
    expect(shell.openExternal).not.toHaveBeenCalled();
});

test('a new window request for an external origin opens the system browser and denies the window', () => {
    const result = handlers.windowOpen({ url: 'https://provider.example/oauth/authorize' });

    expect(shell.openExternal).toHaveBeenCalledWith('https://provider.example/oauth/authorize');
    expect(result).toEqual({ action: 'deny' });
});

test('a new window request for the local backend origin is denied without opening the system browser', () => {
    const result = handlers.windowOpen({ url: `http://127.0.0.1:${PORT}/help` });

    expect(shell.openExternal).not.toHaveBeenCalled();
    expect(result).toEqual({ action: 'deny' });
});

test('will-navigate to a file: URL is blocked instead of being handed to shell.openExternal', () => {
    const event = { preventDefault: jest.fn() };

    handlers['will-navigate'](event, 'file:///etc/passwd');

    expect(event.preventDefault).toHaveBeenCalled();
    expect(shell.openExternal).not.toHaveBeenCalled();
});

test('will-redirect to a javascript: URL is blocked instead of being handed to shell.openExternal', () => {
    const event = { preventDefault: jest.fn() };

    handlers['will-redirect'](event, 'javascript:alert(1)');

    expect(event.preventDefault).toHaveBeenCalled();
    expect(shell.openExternal).not.toHaveBeenCalled();
});

test('a new window request for an smb: URL is denied without opening the system browser', () => {
    const result = handlers.windowOpen({ url: 'smb://attacker.example/share' });

    expect(shell.openExternal).not.toHaveBeenCalled();
    expect(result).toEqual({ action: 'deny' });
});
