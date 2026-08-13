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
    getProxySettings: jest.fn(),
}));

const loginHandlers = [];

jest.mock('electron', () => ({
    app: {
        on: jest.fn((event, handler) => {
            if (event === 'login') loginHandlers.push(handler);
        }),
    },
}));

let { getProxySettings } = require('../../native/config');
const {
    PROXY_BYPASS_RULES,
    PROXY_CHANGED_EVENT,
    buildProxyConfig,
    applyProxy,
    isConfiguredProxyChallenge,
} = require('../../native/proxy');

beforeEach(() => {
    jest.clearAllMocks();
    loginHandlers.length = 0;
});

describe('buildProxyConfig', () => {
    test('returns direct mode when proxy is null', () => {
        expect(buildProxyConfig(null)).toEqual({ mode: 'direct' });
    });

    test('returns direct mode when mode is "none"', () => {
        expect(buildProxyConfig({ mode: 'none', host: '1.2.3.4', port: 8080 })).toEqual({ mode: 'direct' });
    });

    test('returns direct mode when host or port is missing despite manual mode', () => {
        expect(buildProxyConfig({ mode: 'manual', host: null, port: 8080 })).toEqual({ mode: 'direct' });
        expect(buildProxyConfig({ mode: 'manual', host: '1.2.3.4', port: null })).toEqual({ mode: 'direct' });
    });

    test('builds http proxyRules with an explicit loopback bypass', () => {
        const config = buildProxyConfig({ mode: 'manual', protocol: 'http', host: '1.2.3.4', port: 8080 });

        expect(config).toEqual({
            proxyRules:       'http://1.2.3.4:8080',
            proxyBypassRules: PROXY_BYPASS_RULES,
        });
    });

    test('builds socks5 proxyRules', () => {
        const config = buildProxyConfig({ mode: 'manual', protocol: 'socks5', host: '1.2.3.4', port: 1080 });

        expect(config.proxyRules).toBe('socks5://1.2.3.4:1080');
    });

    test('bypass rules explicitly list loopback literals, not the "<local>" or "<-loopback>" tokens', () => {
        expect(PROXY_BYPASS_RULES).toBe('127.0.0.1;::1;localhost');
        expect(PROXY_BYPASS_RULES).not.toMatch(/<local>|<-loopback>/);
    });
});

// PHP and native/proxy.js each declare the event name as their own literal (they run in separate
// processes and cannot share a constant), so nothing stops the two from drifting apart again the
// way they did before issue #336 ('proxy.updated' vs 'proxy.changed'). This test closes that gap
// by reading ProxyController's PHP constant straight out of its source and comparing it against
// PROXY_CHANGED_EVENT actually used by lifecycle/index.js to trigger applyProxy().
describe('PROXY_CHANGED_EVENT contract with the PHP side', () => {
    test('matches App\\Controller\\Settings\\ProxyController::PROXY_CHANGED_EVENT', () => {
        const phpControllerSource = fs.readFileSync(
            path.join(__dirname, '../../app/src/Controller/Settings/ProxyController.php'),
            'utf8',
        );

        const match = phpControllerSource.match(/public const PROXY_CHANGED_EVENT = '([^']+)';/);

        expect(match).not.toBeNull();
        expect(PROXY_CHANGED_EVENT).toBe(match[1]);
    });
});

describe('applyProxy', () => {
    test('reads config.json and applies it to the given session', async () => {
        getProxySettings.mockReturnValue({ mode: 'manual', protocol: 'http', host: '1.2.3.4', port: 8080 });
        const fakeSession = { setProxy: jest.fn().mockResolvedValue(undefined) };

        await applyProxy(fakeSession);

        expect(fakeSession.setProxy).toHaveBeenCalledWith({
            proxyRules:       'http://1.2.3.4:8080',
            proxyBypassRules: PROXY_BYPASS_RULES,
        });
    });

    test('clears the session proxy when mode is "none"', async () => {
        getProxySettings.mockReturnValue({ mode: 'none' });
        const fakeSession = { setProxy: jest.fn().mockResolvedValue(undefined) };

        await applyProxy(fakeSession);

        expect(fakeSession.setProxy).toHaveBeenCalledWith({ mode: 'direct' });
    });
});

describe('isConfiguredProxyChallenge', () => {
    const proxy = { mode: 'manual', protocol: 'http', host: '1.2.3.4', port: 8080 };

    test('true for a proxy challenge matching the configured host and port', () => {
        expect(isConfiguredProxyChallenge({ isProxy: true, host: '1.2.3.4', port: 8080 }, proxy)).toBe(true);
    });

    test('false when isProxy is false (ordinary site auth, e.g. HTTP 401)', () => {
        expect(isConfiguredProxyChallenge({ isProxy: false, host: '1.2.3.4', port: 8080 }, proxy)).toBe(false);
    });

    test('false when the challenge host does not match the configured proxy', () => {
        expect(isConfiguredProxyChallenge({ isProxy: true, host: 'evil.example', port: 8080 }, proxy)).toBe(false);
    });

    test('false when the challenge port does not match the configured proxy', () => {
        expect(isConfiguredProxyChallenge({ isProxy: true, host: '1.2.3.4', port: 9999 }, proxy)).toBe(false);
    });

    test('false when no proxy is configured', () => {
        expect(isConfiguredProxyChallenge({ isProxy: true, host: '1.2.3.4', port: 8080 }, null)).toBe(false);
    });

    test('false when the configured proxy mode is "none"', () => {
        expect(isConfiguredProxyChallenge(
            { isProxy: true, host: '1.2.3.4', port: 8080 },
            { mode: 'none', host: '1.2.3.4', port: 8080 },
        )).toBe(false);
    });
});

describe('registerProxyAuthHandler', () => {
    function fireLogin(authInfo, details = { pid: 1, url: 'https://example.com/' }) {
        const event = { preventDefault: jest.fn() };
        const callback = jest.fn();
        loginHandlers[0](event, /* webContents */ {}, details, authInfo, callback);
        return { event, callback };
    }

    // "already attempted" state lives inside the proxy module, keyed by request identity
    // (pid + url) plus host:port - reset it via a fresh module instance per test so tests reusing
    // the same request identity stay independent.
    let proxyModule;

    beforeEach(() => {
        jest.resetModules();
        loginHandlers.length = 0;
        ({ getProxySettings } = require('../../native/config'));
        proxyModule = require('../../native/proxy');
        proxyModule.registerProxyAuthHandler();
    });

    test('supplies the configured proxy credentials for a matching proxy challenge', () => {
        getProxySettings.mockReturnValue({
            mode: 'manual', protocol: 'http', host: '1.2.3.4', port: 8080, username: 'bob', password: 'secret',
        });

        const { event, callback } = fireLogin({ isProxy: true, host: '1.2.3.4', port: 8080 });

        expect(event.preventDefault).toHaveBeenCalled();
        expect(callback).toHaveBeenCalledWith('bob', 'secret');
    });

    test('does not intercept an ordinary site auth challenge (isProxy: false)', () => {
        getProxySettings.mockReturnValue({
            mode: 'manual', protocol: 'http', host: '1.2.3.4', port: 8080, username: 'bob', password: 'secret',
        });

        const { event, callback } = fireLogin({ isProxy: false, host: 'example.com', port: 443 });

        expect(event.preventDefault).not.toHaveBeenCalled();
        expect(callback).not.toHaveBeenCalled();
    });

    test('does not supply credentials to a proxy challenge from a different host:port', () => {
        getProxySettings.mockReturnValue({
            mode: 'manual', protocol: 'http', host: '1.2.3.4', port: 8080, username: 'bob', password: 'secret',
        });

        const { event, callback } = fireLogin({ isProxy: true, host: 'other-proxy.example', port: 3128 });

        expect(event.preventDefault).not.toHaveBeenCalled();
        expect(callback).not.toHaveBeenCalled();
    });

    test('cancels a retried challenge for the same request instead of looping on wrong credentials', () => {
        getProxySettings.mockReturnValue({
            mode: 'manual', protocol: 'http', host: '1.2.3.4', port: 8080, username: 'bob', password: 'wrong',
        });

        const authInfo = { isProxy: true, host: '1.2.3.4', port: 8080 };
        const details = { pid: 1, url: 'https://example.com/' };
        const first = fireLogin(authInfo, details);
        expect(first.callback).toHaveBeenCalledWith('bob', 'wrong');

        const second = fireLogin(authInfo, details);
        expect(second.event.preventDefault).toHaveBeenCalled();
        expect(second.callback).toHaveBeenCalledWith();
    });

    test('answers concurrent challenges for different requests through the same proxy independently', () => {
        getProxySettings.mockReturnValue({
            mode: 'manual', protocol: 'http', host: '1.2.3.4', port: 8080, username: 'bob', password: 'secret',
        });

        const authInfo = { isProxy: true, host: '1.2.3.4', port: 8080 };

        // Two images loading at once through the same proxy: same host:port, different requests
        // (different pid/url). Neither has been "attempted" yet, so both must get credentials
        // instead of the second one being cancelled as a false-positive retry.
        const first = fireLogin(authInfo, { pid: 1, url: 'https://example.com/image1.png' });
        const second = fireLogin(authInfo, { pid: 2, url: 'https://example.com/image2.png' });

        expect(first.callback).toHaveBeenCalledWith('bob', 'secret');
        expect(second.callback).toHaveBeenCalledWith('bob', 'secret');
    });

    test('retries after applyProxy() re-reads the setting (e.g. the user fixed the password)', async () => {
        getProxySettings.mockReturnValue({
            mode: 'manual', protocol: 'http', host: '1.2.3.4', port: 8080, username: 'bob', password: 'wrong',
        });

        const authInfo = { isProxy: true, host: '1.2.3.4', port: 8080 };
        fireLogin(authInfo);

        getProxySettings.mockReturnValue({
            mode: 'manual', protocol: 'http', host: '1.2.3.4', port: 8080, username: 'bob', password: 'correct',
        });
        await proxyModule.applyProxy({ setProxy: jest.fn().mockResolvedValue(undefined) });

        const { callback } = fireLogin(authInfo);
        expect(callback).toHaveBeenCalledWith('bob', 'correct');
    });
});
