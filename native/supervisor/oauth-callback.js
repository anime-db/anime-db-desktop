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
const { getLocale } = require('../config');
const i18n = require('../i18n');

/**
 * Fixed loopback port this listener binds to (issue #871). An OAuth provider that compares
 * `redirect_uri` byte-for-byte, port included (e.g. MyAnimeList — RFC 8252 §7.3 does not require
 * it to relax this), can only ever be registered against one fixed value. FrankenPHP's own HTTP
 * port is found dynamically (native/supervisor/port.js#findFreePort) and changes across restarts,
 * so it cannot be that fixed value — this listener is the stable address instead, redirecting to
 * whatever port FrankenPHP currently has.
 *
 * @type {number}
 */
const OAUTH_FIXED_PORT = 41813;

let server = null;
let stopping = false;
let getCurrentFrankenphpPort = null;

/**
 * @param {import('http').IncomingMessage} req
 * @param {import('http').ServerResponse} res
 */
function handleRequest(req, res) {
    try {
        let url;
        try {
            url = new URL(req.url, 'http://127.0.0.1');
        } catch {
            // A raw request-target that Node's HTTP parser accepts but the WHATWG URL parser
            // rejects (e.g. an unparsable authority after a "//" prefix) must not crash this
            // listener — it is reachable by any local process on this fixed, well-known port.
            res.writeHead(400);
            res.end();
            return;
        }

        if (req.method !== 'GET' || !url.pathname.startsWith('/oauth/')) {
            res.writeHead(404);
            res.end();
            return;
        }

        const port = getCurrentFrankenphpPort ? getCurrentFrankenphpPort() : null;
        if (!port) {
            const locale = getLocale();
            const body = i18n.t('oauth_callback.restarting', locale);
            res.writeHead(503, { 'Content-Type': 'text/plain; charset=utf-8' });
            res.end(body);
            return;
        }

        // Only a redirect to the port FrankenPHP currently has — read via
        // getCurrentFrankenphpPort() at request time, never cached — not a proxy: no
        // body/headers from FrankenPHP ever pass through this response (issue #871).
        res.writeHead(302, { Location: `http://127.0.0.1:${port}${url.pathname}${url.search}` });
        res.end();
    } catch (error) {
        console.error('[oauth-callback] request handler error:', error.message);
        if (!res.headersSent) {
            res.writeHead(400);
        }
        res.end();
    }
}

/**
 * Binds the fixed-port listener. Must be called before any PHP process of the session starts
 * (see supervisor/index.js#start) — `$_SERVER['OAUTH_CALLBACK_ORIGIN']` is fixed at PHP process
 * start, so the origin it resolves to must be known and stable before that point.
 *
 * @param {() => (number | null)} portProvider  returns the port FrankenPHP currently listens on,
 *   or a falsy value while it is down (startup/restart) — called fresh on every request, never
 *   cached here.
 * @returns {Promise<{ ok: true } | { ok: false, error: Error }>}  ok:false on a bind failure
 *   (e.g. EADDRINUSE) — the caller falls back to the pre-#871 behavior and must not throw.
 */
function start(portProvider) {
    stopping = false;

    return new Promise((resolve) => {
        const srv = http.createServer(handleRequest);

        srv.once('error', (error) => resolve({ ok: false, error }));

        srv.listen(OAUTH_FIXED_PORT, '127.0.0.1', () => {
            srv.removeAllListeners('error');

            // Only set on a successful bind — an overlapping failed start() call (e.g. called
            // twice by mistake) must not clobber the live server's port provider with one tied to
            // a bind attempt that never actually took over the socket.
            getCurrentFrankenphpPort = portProvider;

            // Logged, not swallowed (issue #871 acceptance criterion): these fire only after a
            // successful bind above — stop()/killSync() set `stopping` first, so the close they
            // themselves trigger is not reported as unexpected.
            srv.on('error', (error) => {
                console.error('[oauth-callback] socket error:', error.message);
            });
            srv.on('close', () => {
                if (!stopping) {
                    console.error('[oauth-callback] listener closed unexpectedly');
                }
            });

            server = srv;
            resolve({ ok: true });
        });
    });
}

/**
 * Graceful shutdown, mirrors the other supervisor modules' stop().
 *
 * @returns {Promise<void>}
 */
function stop() {
    stopping = true;
    if (!server) return Promise.resolve();

    const srv = server;
    server = null;

    return new Promise((resolve) => srv.close(() => resolve()));
}

/**
 * Best-effort synchronous close for the same abnormal-exit path as the other supervisor modules'
 * killSync() (see supervisor/index.js#killSync).
 */
function killSync() {
    stopping = true;
    if (!server) return;

    try {
        server.close();
    } catch {
        // уже закрыт
    }
    server = null;
}

module.exports = { OAUTH_FIXED_PORT, start, stop, killSync };
