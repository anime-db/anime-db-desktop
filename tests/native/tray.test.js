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

const mockTray = {
    setToolTip:      jest.fn(),
    setContextMenu:  jest.fn(),
    setImage:        jest.fn(),
    on:              jest.fn(),
};

jest.mock('electron', () => ({
    Tray:        jest.fn(() => mockTray),
    Menu:        { buildFromTemplate: jest.fn((template) => template) },
    nativeImage: { createFromPath: jest.fn(() => 'fake-icon') },
    app:         { getLocale: jest.fn(() => 'ru-RU') },
}));
jest.mock('../../native/paths', () => ({
    getUserDataDir:                  jest.fn(() => '/fake/userData-does-not-exist'),
    getNativeTranslationsDir:        jest.fn(() => require('path').join(__dirname, '../../native/translations')),
    getNativeTranslationsOverlayDir: jest.fn(() => '/fake/userData-does-not-exist/native-translations'),
}));

const { app } = require('electron');
const tray = require('../../native/tray');

const fakeWindow = { show: jest.fn() };

beforeEach(() => {
    jest.clearAllMocks();
});

test('the tray menu is built in Russian when getLocale() resolves to "ru"', () => {
    app.getLocale.mockReturnValue('ru-RU');
    const onQuit = jest.fn();

    tray.create(fakeWindow, onQuit);

    const [menuTemplate] = mockTray.setContextMenu.mock.calls[0];
    const labels = menuTemplate.filter((item) => item.label).map((item) => item.label);

    expect(labels).toEqual(['Открыть', 'Выход']);
});

test('the tray menu is built in English when getLocale() resolves to "en"', () => {
    app.getLocale.mockReturnValue('en-US');
    const onQuit = jest.fn();

    tray.create(fakeWindow, onQuit);

    const [menuTemplate] = mockTray.setContextMenu.mock.calls[0];
    const labels = menuTemplate.filter((item) => item.label).map((item) => item.label);

    expect(labels).toEqual(['Open', 'Quit']);
});

test('the "Open" entry shows the main window and the "Quit" entry runs onQuit', () => {
    app.getLocale.mockReturnValue('en-US');
    const onQuit = jest.fn();

    tray.create(fakeWindow, onQuit);

    const [menuTemplate] = mockTray.setContextMenu.mock.calls[0];
    menuTemplate.find((item) => item.label === 'Open').click();
    menuTemplate.find((item) => item.label === 'Quit').click();

    expect(fakeWindow.show).toHaveBeenCalledTimes(1);
    expect(onQuit).toHaveBeenCalledTimes(1);
});
