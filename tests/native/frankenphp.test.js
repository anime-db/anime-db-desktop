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
    getAppRootDir:         jest.fn(() => '/fake/app'),
    getDbPath:             jest.fn(() => '/fake/userData/data.db'),
    getQueueDbPath:        jest.fn(() => '/fake/userData/queue.db'),
    getPhpIniDir:          jest.fn(() => '/fake/userData'),
    getPhpIniPath:         jest.fn(() => '/fake/userData/php.ini'),
    getRuntimeDir:         jest.fn(() => '/fake/userData/var'),
    getMediaDir:           jest.fn(() => '/fake/userData/media'),
    getConfigPath:         jest.fn(() => '/fake/userData/config.json'),
    getPluginsConfigPath:  jest.fn(() => '/fake/userData/plugins.json'),
    getPluginsDir:         jest.fn(() => '/fake/userData/plugins'),
    getMarketRegistryCachePath: jest.fn(() => '/fake/userData/market-registry-cache.json'),
    getMarketSnapshotCachePath: jest.fn(() => '/fake/userData/market-snapshot-cache.json'),
    getMarketRefreshLockPath:   jest.fn(() => '/fake/userData/market-refresh.lock'),
    getMeilisearchDataDir: jest.fn(() => '/fake/userData/meilisearch'),
    getMeilisearchKeyPath: jest.fn(() => '/fake/userData/meilisearch-key.txt'),
    getUserDataDir:        jest.fn(() => '/fake/userData'),
}));
jest.mock('../../native/config', () => ({
    getOrCreateAppSecret: jest.fn(() => 'a'.repeat(64)),
}));
jest.mock('../../native/supervisor/logrotate', () => ({
    pruneOldLogs:   jest.fn(),
    openLogStream:  jest.fn(),
}));
jest.mock('../../native/supervisor/port', () => ({
    findFreePort: jest.fn(),
}));
jest.mock('../../native/supervisor/healthcheck', () => ({
    waitForHealth: jest.fn(),
}));

const { buildEnv } = require('../../native/supervisor/frankenphp');

const CONTEXT = { appPort: 8000, qbittorrentPort: 9999, meiliPort: 7700, meiliKey: 'test-key' };
const WS_PORT = 9000;

describe('buildEnv', () => {
    test('includes APP_PORT as a string', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.APP_PORT).toBe('8000');
        expect(typeof env.APP_PORT).toBe('string');
    });

    test('includes WS_PORT as a string', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.WS_PORT).toBe('9000');
        expect(typeof env.WS_PORT).toBe('string');
    });

    test('includes APP_ROOT pointing to the app directory', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.APP_ROOT).toBe('/fake/app');
    });

    test('includes APP_ENV set to prod', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.APP_ENV).toBe('prod');
    });

    test('includes CORE_VERSION from app.getVersion()', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.CORE_VERSION).toBe('1.2.3');
    });

    test('includes DATABASE_URL as a sqlite:// URL', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.DATABASE_URL).toMatch(/^sqlite:\/\/\//);
        expect(env.DATABASE_URL).toContain('data.db');
    });

    test('includes QUEUE_DATABASE_URL as a sqlite:// URL pointing to a different file than DATABASE_URL', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.QUEUE_DATABASE_URL).toMatch(/^sqlite:\/\/\//);
        expect(env.QUEUE_DATABASE_URL).toContain('queue.db');
        expect(env.QUEUE_DATABASE_URL).not.toBe(env.DATABASE_URL);
    });

    test('includes MESSENGER_TRANSPORT_DSN pointing to the queue connection', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.MESSENGER_TRANSPORT_DSN).toBe('doctrine://queue?auto_setup=0');
    });

    test('includes PHPRC pointing to the php.ini directory', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.PHPRC).toBe('/fake/userData');
    });

    test('includes APP_RUNTIME_DIR pointing to the runtime dir', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.APP_RUNTIME_DIR).toBe('/fake/userData/var');
    });

    test('includes MEDIA_DIR pointing to the media dir', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.MEDIA_DIR).toBe('/fake/userData/media');
    });

    test('includes CONFIG_PATH pointing to config.json', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.CONFIG_PATH).toBe('/fake/userData/config.json');
    });

    test('includes PLUGINS_CONFIG_PATH pointing to plugins.json', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.PLUGINS_CONFIG_PATH).toBe('/fake/userData/plugins.json');
    });

    test('includes PLUGINS_DIR pointing to plugins', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.PLUGINS_DIR).toBe('/fake/userData/plugins');
    });

    test('includes QBITTORRENT_URL using the given qbittorrentPort', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.QBITTORRENT_URL).toBe('http://127.0.0.1:9999');
    });

    test('includes MEILISEARCH_URL using the given meiliPort', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.MEILISEARCH_URL).toBe('http://127.0.0.1:7700');

        const env2 = buildEnv({ ...CONTEXT, meiliPort: 8888 }, WS_PORT);
        expect(env2.MEILISEARCH_URL).toBe('http://127.0.0.1:8888');
    });

    test('includes MEILISEARCH_KEY matching the provided key', () => {
        const env = buildEnv({ ...CONTEXT, meiliKey: 'my-secret-key' }, WS_PORT);
        expect(env.MEILISEARCH_KEY).toBe('my-secret-key');
    });

    test('includes OAUTH_CALLBACK_ORIGIN using the given appPort', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.OAUTH_CALLBACK_ORIGIN).toBe('http://127.0.0.1:8000');

        const env2 = buildEnv({ ...CONTEXT, appPort: 12345 }, WS_PORT);
        expect(env2.OAUTH_CALLBACK_ORIGIN).toBe('http://127.0.0.1:12345');
    });

    test('inherits existing process.env variables', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env).toMatchObject(process.env);
    });

    test('includes APP_SECRET from getOrCreateAppSecret', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.APP_SECRET).toBe('a'.repeat(64));
        expect(env.APP_SECRET).toHaveLength(64);
    });
});
