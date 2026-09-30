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

const handlers = {};

const mockNavigationHistory = {
    canGoBack: jest.fn(() => false),
    goBack: jest.fn(),
    canGoForward: jest.fn(() => false),
    goForward: jest.fn(),
};

const mockWebContents = {
    on: jest.fn((event, handler) => { handlers[event] = handler; }),
    setWindowOpenHandler: jest.fn((handler) => { handlers.windowOpen = handler; }),
    navigationHistory: mockNavigationHistory,
};

let mockWorkAreaSize = { width: 1920, height: 1080 };

jest.mock('electron', () => ({
    BrowserWindow: jest.fn().mockImplementation(() => ({
        webContents: mockWebContents,
        loadURL: jest.fn(),
        on: jest.fn(),
    })),
    Menu: { setApplicationMenu: jest.fn() },
    screen: { getPrimaryDisplay: jest.fn(() => ({ workAreaSize: mockWorkAreaSize })) },
    shell: { openExternal: jest.fn() },
    nativeTheme: { shouldUseDarkColors: false },
}));

const { BrowserWindow, Menu, shell } = require('electron');
const { createWindow } = require('../../native/window');

const PORT = 8123;

beforeEach(() => {
    jest.clearAllMocks();
    mockNavigationHistory.canGoBack.mockReturnValue(false);
    mockNavigationHistory.canGoForward.mockReturnValue(false);
    mockWorkAreaSize = { width: 1920, height: 1080 };
    createWindow(PORT);
});

test('the window enforces a minimum size so the layout below it stays usable', () => {
    expect(BrowserWindow).toHaveBeenCalledWith(expect.objectContaining({
        minWidth: 1000,
        minHeight: 640,
    }));
});

test('the default window size is used when it fits the screen work area', () => {
    expect(BrowserWindow).toHaveBeenCalledWith(expect.objectContaining({
        width: 1200,
        height: 800,
    }));
});

test('the default window size is capped to the screen work area on smaller screens', () => {
    jest.clearAllMocks();
    mockWorkAreaSize = { width: 1024, height: 728 };

    createWindow(PORT);

    expect(BrowserWindow).toHaveBeenCalledWith(expect.objectContaining({
        width: 1024,
        height: 728,
    }));
});

test('the default Electron application menu is removed on window creation', () => {
    expect(Menu.setApplicationMenu).toHaveBeenCalledWith(null);
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

test('the browser-backward mouse button goes back when there is history to go back to', () => {
    mockNavigationHistory.canGoBack.mockReturnValue(true);

    handlers['app-command']({}, 'browser-backward');

    expect(mockNavigationHistory.goBack).toHaveBeenCalledTimes(1);
});

test('the browser-backward mouse button does nothing when there is no history to go back to', () => {
    handlers['app-command']({}, 'browser-backward');

    expect(mockNavigationHistory.goBack).not.toHaveBeenCalled();
});

test('the browser-forward mouse button goes forward when there is history to go forward to', () => {
    mockNavigationHistory.canGoForward.mockReturnValue(true);

    handlers['app-command']({}, 'browser-forward');

    expect(mockNavigationHistory.goForward).toHaveBeenCalledTimes(1);
});

test('the browser-forward mouse button does nothing when there is no history to go forward to', () => {
    handlers['app-command']({}, 'browser-forward');

    expect(mockNavigationHistory.goForward).not.toHaveBeenCalled();
});

test('an unrelated app-command is ignored', () => {
    mockNavigationHistory.canGoBack.mockReturnValue(true);
    mockNavigationHistory.canGoForward.mockReturnValue(true);

    handlers['app-command']({}, 'media-play-pause');

    expect(mockNavigationHistory.goBack).not.toHaveBeenCalled();
    expect(mockNavigationHistory.goForward).not.toHaveBeenCalled();
});

test('Alt+Left goes back when there is history to go back to', () => {
    mockNavigationHistory.canGoBack.mockReturnValue(true);
    const event = { preventDefault: jest.fn() };

    handlers['before-input-event'](event, { type: 'keyDown', key: 'ArrowLeft', alt: true, control: false, meta: false, shift: false });

    expect(event.preventDefault).toHaveBeenCalled();
    expect(mockNavigationHistory.goBack).toHaveBeenCalledTimes(1);
});

test('Alt+Left does nothing when there is no history to go back to', () => {
    const event = { preventDefault: jest.fn() };

    handlers['before-input-event'](event, { type: 'keyDown', key: 'ArrowLeft', alt: true, control: false, meta: false, shift: false });

    expect(event.preventDefault).not.toHaveBeenCalled();
    expect(mockNavigationHistory.goBack).not.toHaveBeenCalled();
});

test('Alt+Right goes forward when there is history to go forward to', () => {
    mockNavigationHistory.canGoForward.mockReturnValue(true);
    const event = { preventDefault: jest.fn() };

    handlers['before-input-event'](event, { type: 'keyDown', key: 'ArrowRight', alt: true, control: false, meta: false, shift: false });

    expect(event.preventDefault).toHaveBeenCalled();
    expect(mockNavigationHistory.goForward).toHaveBeenCalledTimes(1);
});

test('ArrowLeft without Alt is left untouched', () => {
    mockNavigationHistory.canGoBack.mockReturnValue(true);
    const event = { preventDefault: jest.fn() };

    handlers['before-input-event'](event, { type: 'keyDown', key: 'ArrowLeft', alt: false, control: false, meta: false, shift: false });

    expect(event.preventDefault).not.toHaveBeenCalled();
    expect(mockNavigationHistory.goBack).not.toHaveBeenCalled();
});

test('Alt+Left on keyUp is ignored, since the navigation already happened on keyDown', () => {
    mockNavigationHistory.canGoBack.mockReturnValue(true);
    const event = { preventDefault: jest.fn() };

    handlers['before-input-event'](event, { type: 'keyUp', key: 'ArrowLeft', alt: true, control: false, meta: false, shift: false });

    expect(event.preventDefault).not.toHaveBeenCalled();
    expect(mockNavigationHistory.goBack).not.toHaveBeenCalled();
});

test('Ctrl+Alt+Left is left untouched, since it collides with other shortcuts on some layouts', () => {
    mockNavigationHistory.canGoBack.mockReturnValue(true);
    const event = { preventDefault: jest.fn() };

    handlers['before-input-event'](event, { type: 'keyDown', key: 'ArrowLeft', alt: true, control: true, meta: false, shift: false });

    expect(event.preventDefault).not.toHaveBeenCalled();
    expect(mockNavigationHistory.goBack).not.toHaveBeenCalled();
});
