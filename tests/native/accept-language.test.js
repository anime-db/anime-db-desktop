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
    app:     { whenReady: jest.fn(() => Promise.resolve()) },
    session: { defaultSession: { webRequest: { onBeforeSendHeaders: jest.fn() } } },
}));

jest.mock('../../native/config', () => ({
    getOrCreateLocale: jest.fn(),
    getLocale:         jest.fn(() => 'ru'),
}));

const { session } = require('electron');
const { getOrCreateLocale, getLocale } = require('../../native/config');

let acceptLanguage;

beforeAll(async () => {
    acceptLanguage = require('../../native/accept-language');
    // flush the app.whenReady().then(...) microtask queued at module load.
    await Promise.resolve();
    await Promise.resolve();
});

test('ensures a locale exists in config.json once the app is ready', () => {
    expect(getOrCreateLocale).toHaveBeenCalled();
});

test('registers an Accept-Language override on the default session', () => {
    expect(session.defaultSession.webRequest.onBeforeSendHeaders)
        .toHaveBeenCalledWith(expect.any(Function));
});

describe('overrideAcceptLanguage', () => {
    test('sets the Accept-Language header to the configured locale and forwards the request', () => {
        getLocale.mockReturnValue('en');
        const details = { requestHeaders: { 'User-Agent': 'test' } };
        const callback = jest.fn();

        acceptLanguage.overrideAcceptLanguage(details, callback);

        expect(details.requestHeaders['Accept-Language']).toBe('en');
        expect(callback).toHaveBeenCalledWith({ requestHeaders: details.requestHeaders });
    });

    test('reads the locale fresh on every call, so a config change applies without a restart', () => {
        getLocale.mockReturnValueOnce('ru').mockReturnValueOnce('en');
        const callback = jest.fn();

        const first = { requestHeaders: {} };
        acceptLanguage.overrideAcceptLanguage(first, callback);
        expect(first.requestHeaders['Accept-Language']).toBe('ru');

        const second = { requestHeaders: {} };
        acceptLanguage.overrideAcceptLanguage(second, callback);
        expect(second.requestHeaders['Accept-Language']).toBe('en');
    });
});
