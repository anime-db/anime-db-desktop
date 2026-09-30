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

const fs = require('fs');
const path = require('path');

jest.mock('../../native/config', () => ({
    getThemePreference: jest.fn(),
}));

const updatedHandlers = [];

jest.mock('electron', () => ({
    nativeTheme: {
        themeSource: 'system',
        shouldUseDarkColors: false,
        on: jest.fn((event, handler) => {
            if (event === 'updated') updatedHandlers.push(handler);
        }),
    },
}));

const { getThemePreference } = require('../../native/config');
const { nativeTheme } = require('electron');
const {
    THEME_CHANGED_EVENT,
    getBackgroundColor,
    applyThemeSource,
    registerThemeUpdateHandler,
} = require('../../native/theme');

beforeEach(() => {
    jest.clearAllMocks();
    updatedHandlers.length = 0;
    nativeTheme.themeSource = 'system';
    nativeTheme.shouldUseDarkColors = false;
});

describe('getBackgroundColor', () => {
    test('returns the dark body-bg for shouldUseDarkColors: true', () => {
        expect(getBackgroundColor(true)).toBe('#212529');
    });

    test('returns the light body-bg for shouldUseDarkColors: false', () => {
        expect(getBackgroundColor(false)).toBe('#ffffff');
    });
});

describe('applyThemeSource', () => {
    test('reads themePreference from config.json and assigns it to nativeTheme.themeSource', () => {
        getThemePreference.mockReturnValue('dark');

        applyThemeSource();

        expect(nativeTheme.themeSource).toBe('dark');
    });

    test('assigns whatever getThemePreference() returns, including its "system" fallback value', () => {
        nativeTheme.themeSource = 'dark';
        getThemePreference.mockReturnValue('system');

        applyThemeSource();

        expect(nativeTheme.themeSource).toBe('system');
    });
});

describe('registerThemeUpdateHandler', () => {
    test('the "updated" handler calls setBackgroundColor on every live window with the current color', () => {
        const liveWindow = { isDestroyed: jest.fn(() => false), setBackgroundColor: jest.fn() };
        const destroyedWindow = { isDestroyed: jest.fn(() => true), setBackgroundColor: jest.fn() };
        nativeTheme.shouldUseDarkColors = true;

        registerThemeUpdateHandler(() => [liveWindow, destroyedWindow]);
        updatedHandlers[0]();

        expect(liveWindow.setBackgroundColor).toHaveBeenCalledWith('#212529');
        expect(destroyedWindow.setBackgroundColor).not.toHaveBeenCalled();
    });

    test('reflects the light color when shouldUseDarkColors is false', () => {
        const liveWindow = { isDestroyed: jest.fn(() => false), setBackgroundColor: jest.fn() };
        nativeTheme.shouldUseDarkColors = false;

        registerThemeUpdateHandler(() => [liveWindow]);
        updatedHandlers[0]();

        expect(liveWindow.setBackgroundColor).toHaveBeenCalledWith('#ffffff');
    });
});

// PHP and native/theme.js each declare the event name as their own literal (they run in separate
// processes and cannot share a constant), so nothing stops the two from drifting apart the way
// PROXY_CHANGED_EVENT briefly did (issue #336). This test closes that gap by reading
// SettingsController's PHP constant straight out of its source and comparing it against
// THEME_CHANGED_EVENT actually used by lifecycle/index.js to trigger applyThemeSource().
describe('THEME_CHANGED_EVENT contract with the PHP side', () => {
    test('matches App\\Controller\\SettingsController::THEME_CHANGED_EVENT', () => {
        const phpControllerSource = fs.readFileSync(
            path.join(__dirname, '../../app/src/Controller/SettingsController.php'),
            'utf8',
        );

        const match = phpControllerSource.match(/public const THEME_CHANGED_EVENT = '([^']+)';/);

        expect(match).not.toBeNull();
        expect(THEME_CHANGED_EVENT).toBe(match[1]);
    });
});
