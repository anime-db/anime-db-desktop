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
    BrowserWindow: jest.fn().mockImplementation(() => ({ loadFile: jest.fn() })),
    nativeTheme: { shouldUseDarkColors: false },
}));

jest.mock('../../native/config', () => ({
    ...jest.requireActual('../../native/config'),
    getLocale: jest.fn(),
}));

jest.mock('../../native/paths', () => ({
    getNativeTranslationsDir:        () => require('path').join(__dirname, '../../native/translations'),
    getNativeTranslationsOverlayDir: () => '/fake/does-not-exist/native-translations',
}));

const { BrowserWindow } = require('electron');
const { getLocale } = require('../../native/config');
const { createSplash } = require('../../native/window/splash');

// The splash window has no channel other than webPreferences.additionalArguments to hand
// splash.html the resolved locale and direction before its first paint (issue #404); issue #450
// adds "--splash-dir" alongside the existing "--splash-locale", resolved from the same static
// core table App\Service\LocaleDirection uses on the PHP side, independently of whether a
// translation plugin is installed.
describe('createSplash()', () => {
    beforeEach(() => {
        jest.clearAllMocks();
    });

    test.each([
        ['ru', 'ltr'],
        ['en', 'ltr'],
        ['ar', 'rtl'],
        ['ar-EG', 'rtl'],
    ])('locale "%s" resolves to the expected "--splash-dir"', (locale, expectedDir) => {
        getLocale.mockReturnValue(locale);

        createSplash();

        const { additionalArguments } = BrowserWindow.mock.calls[0][0].webPreferences;
        expect(additionalArguments).toContain(`--splash-locale=${encodeURIComponent(locale)}`);
        expect(additionalArguments).toContain(`--splash-dir=${expectedDir}`);
    });
});
