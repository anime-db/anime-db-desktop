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

const http = require('http');

const mockGetLocale = jest.fn(() => 'en');
jest.mock('../../native/config', () => ({
    ...jest.requireActual('../../native/config'),
    getLocale: () => mockGetLocale(),
}));
// Real i18n/translations, same approach as tests/native/tray.test.js — only the on-disk location
// is faked, so t() resolves actual catalog text instead of needing its own mock.
jest.mock('../../native/paths', () => ({
    getNativeTranslationsDir:        jest.fn(() => require('path').join(__dirname, '../../native/translations')),
    getNativeTranslationsOverlayDir: jest.fn(() => '/fake/userData-does-not-exist/native-translations'),
}));

const oauthCallback = require('../../native/supervisor/oauth-callback');
const { t } = require('../../native/i18n');

/**
 * @param {{ method?: string, path?: string }} [options]
 * @returns {Promise<{ statusCode: number, headers: import('http').IncomingHttpHeaders, body: string }>}
 */
function request({ method = 'GET', path: reqPath = '/oauth/myanimelist?code=a&state=b' } = {}) {
    return new Promise((resolve, reject) => {
        // Connection: close (and agent: false, so the request doesn't borrow a shared keep-alive
        // agent either) — otherwise the socket stays open after the response and server.close()
        // in afterEach's oauthCallback.stop() never fires its callback, hanging the test run.
        const req = http.request({
            method,
            hostname: '127.0.0.1',
            port: oauthCallback.OAUTH_FIXED_PORT,
            path: reqPath,
            agent: false,
            headers: { Connection: 'close' },
        }, (res) => {
            let body = '';
            res.on('data', (chunk) => { body += chunk; });
            res.on('end', () => resolve({ statusCode: res.statusCode, headers: res.headers, body }));
        });
        req.on('error', reject);
        req.end();
    });
}

describe('oauth-callback', () => {
    afterEach(async () => {
        await oauthCallback.stop();
        jest.restoreAllMocks();
        mockGetLocale.mockReturnValue('en');
    });

    test('binds on 127.0.0.1:41813 (OAUTH_FIXED_PORT)', async () => {
        expect(oauthCallback.OAUTH_FIXED_PORT).toBe(41813);

        const result = await oauthCallback.start(() => 8000);

        expect(result).toEqual({ ok: true });
    });

    test('GET /oauth/myanimelist?code=a&state=b redirects (302) to the current FrankenPHP port, same path and query', async () => {
        await oauthCallback.start(() => 8000);

        const res = await request({ path: '/oauth/myanimelist?code=a&state=b' });

        expect(res.statusCode).toBe(302);
        expect(res.headers.location).toBe('http://127.0.0.1:8000/oauth/myanimelist?code=a&state=b');
    });

    // Proves the port is read fresh on every request rather than captured once at bind/start time
    // — a plugin restart (reloadForPlugin) can land FrankenPHP on a different port mid-session.
    test('redirects to the new FrankenPHP port after it changes, without re-binding', async () => {
        let port = 8000;
        await oauthCallback.start(() => port);

        const first = await request({ path: '/oauth/myanimelist?code=a&state=b' });
        expect(first.headers.location).toBe('http://127.0.0.1:8000/oauth/myanimelist?code=a&state=b');

        port = 8123;
        const second = await request({ path: '/oauth/myanimelist?code=a&state=b' });
        expect(second.headers.location).toBe('http://127.0.0.1:8123/oauth/myanimelist?code=a&state=b');
    });

    test('responds 503 when FrankenPHP is not up (port provider returns null)', async () => {
        await oauthCallback.start(() => null);

        const res = await request({ path: '/oauth/myanimelist?code=a&state=b' });

        expect(res.statusCode).toBe(503);
    });

    test('the 503 body comes from native/i18n in the current locale', async () => {
        mockGetLocale.mockReturnValue('ru');
        await oauthCallback.start(() => null);

        const res = await request({ path: '/oauth/myanimelist' });

        expect(res.body).toBe(t('oauth_callback.restarting', 'ru'));
        expect(res.body).not.toBe('');
    });

    test('the 503 body falls back to English when the current locale has no catalog', async () => {
        mockGetLocale.mockReturnValue('xx-not-a-real-locale');
        await oauthCallback.start(() => null);

        const res = await request({ path: '/oauth/myanimelist' });

        expect(res.body).toBe(t('oauth_callback.restarting', 'en'));
    });

    test.each([
        ['POST', '/oauth/myanimelist'],
        ['GET', '/'],
        ['GET', '/health'],
    ])('%s %s is 404', async (method, reqPath) => {
        await oauthCallback.start(() => 8000);

        const res = await request({ method, path: reqPath });

        expect(res.statusCode).toBe(404);
    });

    // Not a proxy (issue #871 acceptance criterion): the response is a bare redirect this module
    // builds itself — it never contacts FrankenPHP, so there is no body/header from it to leak
    // (no Set-Cookie, no custom headers FrankenPHP might have set, nothing beyond the plain HTTP
    // response Node itself adds).
    test('the redirect response carries no body and only the Location header this module set', async () => {
        await oauthCallback.start(() => 8000);

        const res = await request({ path: '/oauth/shikimori?code=x' });

        expect(res.body).toBe('');
        expect(res.headers.location).toBe('http://127.0.0.1:8000/oauth/shikimori?code=x');
        expect(res.headers['set-cookie']).toBeUndefined();
        const allowedHeaders = ['connection', 'content-length', 'date', 'location', 'transfer-encoding'];
        for (const name of Object.keys(res.headers)) {
            expect(allowedHeaders).toContain(name);
        }
    });

    test('logs an unexpected server error after a successful bind, instead of swallowing it', async () => {
        const createServerSpy = jest.spyOn(http, 'createServer');
        const errorSpy = jest.spyOn(console, 'error').mockImplementation(() => {});

        await oauthCallback.start(() => 8000);
        const server = createServerSpy.mock.results[0].value;

        server.emit('error', new Error('EPIPE'));

        expect(errorSpy).toHaveBeenCalledWith(expect.stringContaining('[oauth-callback]'), 'EPIPE');
    });

    test('logs an unexpected listener close, instead of swallowing it', async () => {
        const createServerSpy = jest.spyOn(http, 'createServer');
        const errorSpy = jest.spyOn(console, 'error').mockImplementation(() => {});

        await oauthCallback.start(() => 8000);
        const server = createServerSpy.mock.results[0].value;

        server.emit('close');

        expect(errorSpy).toHaveBeenCalledWith(expect.stringContaining('[oauth-callback]'));
    });

    test('does not log a close as unexpected when stop() triggers it', async () => {
        const errorSpy = jest.spyOn(console, 'error').mockImplementation(() => {});
        await oauthCallback.start(() => 8000);

        await oauthCallback.stop();

        expect(errorSpy).not.toHaveBeenCalled();
    });

    test('stop() frees the port so a later start() can bind again', async () => {
        await oauthCallback.start(() => 8000);
        await oauthCallback.stop();

        const result = await oauthCallback.start(() => 8000);

        expect(result).toEqual({ ok: true });
    });

    test('a second bind attempt while already bound resolves ok:false instead of throwing', async () => {
        await oauthCallback.start(() => 8000);

        const second = await oauthCallback.start(() => 8000);

        expect(second.ok).toBe(false);
        // Not `toBeInstanceOf(Error)`: Node's own EADDRINUSE error and this test file's `Error`
        // global are different realms under jest-environment-node, so a real Error instance
        // legitimately fails that check here — assert on its shape instead.
        expect(second.error.code).toBe('EADDRINUSE');
        expect(typeof second.error.message).toBe('string');
    });
});
