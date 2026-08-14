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
    app: { getVersion: jest.fn(() => '1.2.3') },
}));
jest.mock('../../native/paths', () => ({
    getAppRootDir:        jest.fn(() => '/fake/app'),
    getDbPath:            jest.fn(() => '/fake/userData/data.db'),
    getQueueDbPath:       jest.fn(() => '/fake/userData/queue.db'),
    getPhpIniDir:         jest.fn(() => '/fake/userData'),
    getRuntimeDir:        jest.fn(() => '/fake/userData/var'),
    getMediaDir:          jest.fn(() => '/fake/userData/media'),
    getConfigPath:        jest.fn(() => '/fake/userData/config.json'),
    getPluginsConfigPath: jest.fn(() => '/fake/userData/plugins.json'),
    getPluginsDir:        jest.fn(() => '/fake/userData/plugins'),
    getMarketRegistryCachePath: jest.fn(() => '/fake/userData/market-registry-cache.json'),
}));
jest.mock('../../native/config', () => ({
    getOrCreateAppSecret: jest.fn(() => 'a'.repeat(64)),
}));

const { buildCommonEnv, buildWebWorkerEnv } = require('../../native/supervisor/env');

const CONTEXT = { meiliPort: 7700, meiliKey: 'test-key', qbittorrentPort: 9999, appPort: 8000 };

describe('buildCommonEnv', () => {
    test('includes the full set of user-data paths/addresses shared by every PHP process', () => {
        const env = buildCommonEnv(CONTEXT);
        expect(env.PLUGINS_DIR).toBe('/fake/userData/plugins');
        expect(env.PLUGINS_CONFIG_PATH).toBe('/fake/userData/plugins.json');
        expect(env.MARKET_REGISTRY_CACHE_PATH).toBe('/fake/userData/market-registry-cache.json');
        expect(env.QBITTORRENT_URL).toBe('http://127.0.0.1:9999');
        expect(env.OAUTH_CALLBACK_ORIGIN).toBe('http://127.0.0.1:8000');
        expect(env.MEDIA_DIR).toBe('/fake/userData/media');
        expect(env.CONFIG_PATH).toBe('/fake/userData/config.json');
        expect(env.APP_RUNTIME_DIR).toBe('/fake/userData/var');
    });

    test('does not include worker-only ports', () => {
        const env = buildCommonEnv(CONTEXT);
        expect(env.APP_PORT).toBeUndefined();
        expect(env.WS_PORT).toBeUndefined();
    });
});

describe('buildWebWorkerEnv', () => {
    test('adds APP_PORT/WS_PORT as strings on top of the common env, without mutating it', () => {
        const common = buildCommonEnv(CONTEXT);
        const webEnv = buildWebWorkerEnv(CONTEXT, 9000);

        expect(webEnv.APP_PORT).toBe('8000');
        expect(webEnv.WS_PORT).toBe('9000');
        expect(webEnv).toMatchObject(common);
        expect(common.APP_PORT).toBeUndefined();
    });

    // APP_PORT и OAUTH_CALLBACK_ORIGIN описывают один и тот же порт веб-воркера: оба берутся из
    // context.appPort, поэтому разъехаться не могут — отдельного параметра для порта нет.
    test('derives APP_PORT from the same context.appPort as OAUTH_CALLBACK_ORIGIN', () => {
        const webEnv = buildWebWorkerEnv({ ...CONTEXT, appPort: 12345 }, 9000);

        expect(webEnv.APP_PORT).toBe('12345');
        expect(webEnv.OAUTH_CALLBACK_ORIGIN).toBe('http://127.0.0.1:12345');
    });
});
