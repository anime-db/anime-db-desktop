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

const { app } = require('electron');
const { getProxySettings } = require('./config');

/**
 * Chromium's proxyBypassRules understands "<local>" and "<-loopback>" tokens, but neither matches
 * the IP literal "127.0.0.1" the app's own UI is loaded from (see native/window/index.js). Without
 * an explicit loopback bypass, turning the proxy on with a dead/unreachable address would route the
 * app's own local backend through it too and the window would go black.
 *
 * @type {string}
 */
const PROXY_BYPASS_RULES = '127.0.0.1;::1;localhost';

/**
 * Builds the argument for session.setProxy() from the "proxy" key of config.json
 * (see App\Service\ProxyConfigProvider on the PHP side). `{ mode: 'direct' }` clears any
 * previously configured proxy, which is what ProxyMode::None on the PHP side maps to.
 *
 * @param {Record<string, unknown> | null} proxy
 * @returns {Electron.ProxyConfig}
 */
function buildProxyConfig(proxy) {
    if (!proxy || proxy.mode !== 'manual' || !proxy.host || !proxy.port) {
        return { mode: 'direct' };
    }

    const scheme = proxy.protocol === 'socks5' ? 'socks5' : 'http';

    return {
        proxyRules:       `${scheme}://${proxy.host}:${proxy.port}`,
        proxyBypassRules: PROXY_BYPASS_RULES,
    };
}

// Proxy auth challenges already answered once in this run, keyed by "host:port". Cleared whenever
// applyProxy() re-reads the setting, so a credential change is retried rather than stuck behind a
// stale failure.
const attemptedProxyChallenges = new Set();

/**
 * Reads the current proxy setting from config.json and applies it to the given session. Call at
 * app startup and again whenever the backend notifies of a settings change (backend-event
 * "proxy.changed" over /ws).
 *
 * @param {Electron.Session} session
 */
async function applyProxy(session) {
    attemptedProxyChallenges.clear();
    await session.setProxy(buildProxyConfig(getProxySettings()));
}

/**
 * True only when `authInfo` is a proxy auth challenge from the currently configured proxy's own
 * host/port. An ordinary HTTP 401 from a remote site (authInfo.isProxy === false) or a challenge
 * from a different host (e.g. a second hop) must never receive the proxy's stored credentials.
 *
 * @param {Electron.AuthInfo} authInfo
 * @param {Record<string, unknown> | null} proxy
 * @returns {boolean}
 */
function isConfiguredProxyChallenge(authInfo, proxy) {
    return Boolean(
        authInfo.isProxy
        && proxy
        && proxy.mode === 'manual'
        && authInfo.host === proxy.host
        && authInfo.port === proxy.port,
    );
}

/**
 * Registers the app-wide "login" handler that supplies the configured proxy's credentials for its
 * own auth challenges only. A challenge is answered with credentials at most once per host:port —
 * if they're wrong, the retry challenge that follows is cancelled instead of resubmitted, so a bad
 * password fails the request instead of looping forever.
 */
function registerProxyAuthHandler() {
    app.on('login', (event, webContents, details, authInfo, callback) => {
        const proxy = getProxySettings();

        if (!isConfiguredProxyChallenge(authInfo, proxy)) {
            return;
        }

        event.preventDefault();

        const key = `${authInfo.host}:${authInfo.port}`;
        if (attemptedProxyChallenges.has(key)) {
            callback();
            return;
        }
        attemptedProxyChallenges.add(key);

        callback(proxy.username || '', proxy.password || '');
    });
}

module.exports = {
    PROXY_BYPASS_RULES,
    buildProxyConfig,
    applyProxy,
    isConfiguredProxyChallenge,
    registerProxyAuthHandler,
};
