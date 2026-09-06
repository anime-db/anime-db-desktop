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
    session: { defaultSession: { webRequest: { onHeadersReceived: jest.fn() } } },
}));

const { session } = require('electron');

let csp;

beforeAll(async () => {
    csp = require('../../native/content-security-policy');
    // flush the app.whenReady().then(...) microtask queued at module load.
    await Promise.resolve();
    await Promise.resolve();
});

test('registers a Content-Security-Policy handler on the default session', () => {
    expect(session.defaultSession.webRequest.onHeadersReceived)
        .toHaveBeenCalledWith(expect.any(Function));
});

describe('addContentSecurityPolicy', () => {
    test('sets the policy on a response from the local backend origin', () => {
        const details = { url: 'http://127.0.0.1:8123/anime/1', responseHeaders: { 'Content-Type': ['text/html'] } };
        const callback = jest.fn();

        csp.addContentSecurityPolicy(details, callback);

        expect(callback).toHaveBeenCalledWith({
            responseHeaders: {
                'Content-Type': ['text/html'],
                'Content-Security-Policy': [csp.CONTENT_SECURITY_POLICY],
            },
        });
    });

    test('the policy forbids unsafe-inline script and style, matching the files-only plugin JS/CSS decision', () => {
        expect(csp.CONTENT_SECURITY_POLICY).toMatch(/script-src 'self'/);
        expect(csp.CONTENT_SECURITY_POLICY).toMatch(/style-src 'self'/);
        expect(csp.CONTENT_SECURITY_POLICY).not.toMatch(/unsafe-inline/);
    });

    test('img-src allows same-origin, app-media: and https:, covering covers/gallery/plugin assets and source favicons', () => {
        expect(csp.CONTENT_SECURITY_POLICY).toMatch(/img-src 'self' app-media: https:/);
    });

    test('connect-src allows ws: for the scan-progress WebSocket', () => {
        expect(csp.CONTENT_SECURITY_POLICY).toMatch(/connect-src 'self' ws:/);
    });

    test('leaves a response from a plugin asset served on the same local backend origin unchanged in policy but still protected', () => {
        const details = {
            url: 'http://127.0.0.1:8123/plugin/animedb-shikimori/asset/abc123/assets/carousel.css',
            responseHeaders: { 'Content-Type': ['text/css'] },
        };
        const callback = jest.fn();

        csp.addContentSecurityPolicy(details, callback);

        const response = callback.mock.calls[0][0];
        expect(response.responseHeaders['Content-Security-Policy']).toEqual([csp.CONTENT_SECURITY_POLICY]);
    });

    test('does not add the header to a response outside the local backend origin (e.g. the splash window file:// load)', () => {
        const details = { url: 'file:///app/native/splash/splash.html', responseHeaders: { 'Content-Type': ['text/html'] } };
        const callback = jest.fn();

        csp.addContentSecurityPolicy(details, callback);

        expect(callback).toHaveBeenCalledWith({ responseHeaders: { 'Content-Type': ['text/html'] } });
    });

    test('does not add the header to a response from an external https origin (e.g. a favicon fetched cross-origin)', () => {
        const details = { url: 'https://provider.example/favicon.ico', responseHeaders: {} };
        const callback = jest.fn();

        csp.addContentSecurityPolicy(details, callback);

        expect(callback).toHaveBeenCalledWith({ responseHeaders: {} });
    });

    test('handles a malformed URL without throwing', () => {
        const details = { url: 'not a url', responseHeaders: {} };
        const callback = jest.fn();

        expect(() => csp.addContentSecurityPolicy(details, callback)).not.toThrow();
        expect(callback).toHaveBeenCalledWith({ responseHeaders: {} });
    });
});
