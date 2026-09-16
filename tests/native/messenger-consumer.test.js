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
    getNativeTranslationsDir:        jest.fn(() => '/fake/app/native/translations'),
    getNativeTranslationsOverlayDir: jest.fn(() => '/fake/userData/native-translations'),
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
    pruneOldLogs:  jest.fn(),
    openLogStream: jest.fn(),
}));
jest.mock('../../native/supervisor/healthcheck', () => ({
    waitForProcessAlive: jest.fn(),
}));
// spawnProcess() пишет PID запущенного процесса через pid-tracker (issue #390) — без мока это
// был бы реальный fs.mkdirSync по замоканному пути из ../../native/paths.
jest.mock('../../native/supervisor/pid-tracker', () => ({
    writePid:   jest.fn(),
    clearPid:   jest.fn(),
    killOrphan: jest.fn(() => Promise.resolve()),
}));
jest.mock('child_process', () => ({ spawn: jest.fn() }));

const mockPhpCommandRun = jest.fn();
jest.mock('../../native/supervisor/php-command', () => ({
    run: (...args) => mockPhpCommandRun(...args),
}));

const { spawn } = require('child_process');
const { openLogStream } = require('../../native/supervisor/logrotate');
const { buildEnv, start } = require('../../native/supervisor/messenger-consumer');

/**
 * Builds a fake child_process handle. Passing an `exitCode` fires the 'exit' listener
 * synchronously (mirrors how the real child_process 'exit' event is consumed in these tests);
 * omitting it leaves the fake process "running" so it doesn't trigger backoff scheduling.
 */
function createFakeChild(exitCode) {
    return {
        stdout: { on: jest.fn() },
        stderr: { on: jest.fn() },
        on: jest.fn((event, cb) => {
            if (event === 'exit' && exitCode !== undefined) cb(exitCode);
        }),
    };
}

beforeEach(() => {
    jest.clearAllMocks();
    openLogStream.mockReturnValue({ write: jest.fn(), end: jest.fn() });
});

const CONTEXT = { appPort: 8000, qbittorrentPort: 7700, meiliPort: 7700, meiliKey: 'test-key' };

describe('buildEnv', () => {
    test('does not include HTTP-specific ports', () => {
        const env = buildEnv(CONTEXT);
        expect(env.APP_PORT).toBeUndefined();
        expect(env.WS_PORT).toBeUndefined();
    });

    test('includes APP_ROOT pointing to the app directory', () => {
        const env = buildEnv(CONTEXT);
        expect(env.APP_ROOT).toBe('/fake/app');
    });

    test('includes APP_ENV set to prod', () => {
        const env = buildEnv(CONTEXT);
        expect(env.APP_ENV).toBe('prod');
    });

    test('includes CORE_VERSION from app.getVersion()', () => {
        const env = buildEnv(CONTEXT);
        expect(env.CORE_VERSION).toBe('1.2.3');
    });

    test('includes DATABASE_URL as a sqlite:// URL', () => {
        const env = buildEnv(CONTEXT);
        expect(env.DATABASE_URL).toMatch(/^sqlite:\/\/\//);
        expect(env.DATABASE_URL).toContain('data.db');
    });

    test('includes QUEUE_DATABASE_URL as a sqlite:// URL pointing to a different file than DATABASE_URL', () => {
        const env = buildEnv(CONTEXT);
        expect(env.QUEUE_DATABASE_URL).toMatch(/^sqlite:\/\/\//);
        expect(env.QUEUE_DATABASE_URL).toContain('queue.db');
        expect(env.QUEUE_DATABASE_URL).not.toBe(env.DATABASE_URL);
    });

    test('includes MESSENGER_TRANSPORT_DSN pointing to the queue connection', () => {
        const env = buildEnv(CONTEXT);
        expect(env.MESSENGER_TRANSPORT_DSN).toBe('doctrine://queue?auto_setup=0');
    });

    test('includes PHPRC pointing to the php.ini directory', () => {
        const env = buildEnv(CONTEXT);
        expect(env.PHPRC).toBe('/fake/userData');
    });

    test('includes APP_RUNTIME_DIR pointing to the runtime dir', () => {
        const env = buildEnv(CONTEXT);
        expect(env.APP_RUNTIME_DIR).toBe('/fake/userData/var');
    });

    test('includes MEDIA_DIR pointing to the media dir', () => {
        const env = buildEnv(CONTEXT);
        expect(env.MEDIA_DIR).toBe('/fake/userData/media');
    });

    test('includes CONFIG_PATH pointing to config.json', () => {
        const env = buildEnv(CONTEXT);
        expect(env.CONFIG_PATH).toBe('/fake/userData/config.json');
    });

    test('includes PLUGINS_CONFIG_PATH pointing to plugins.json', () => {
        const env = buildEnv(CONTEXT);
        expect(env.PLUGINS_CONFIG_PATH).toBe('/fake/userData/plugins.json');
    });

    test('includes PLUGINS_DIR pointing to plugins', () => {
        const env = buildEnv(CONTEXT);
        expect(env.PLUGINS_DIR).toBe('/fake/userData/plugins');
    });

    test('includes MEILISEARCH_URL using the given meiliPort', () => {
        const env = buildEnv(CONTEXT);
        expect(env.MEILISEARCH_URL).toBe('http://127.0.0.1:7700');

        const env2 = buildEnv({ ...CONTEXT, meiliPort: 8888 });
        expect(env2.MEILISEARCH_URL).toBe('http://127.0.0.1:8888');
    });

    test('includes MEILISEARCH_KEY matching the provided key', () => {
        const env = buildEnv({ ...CONTEXT, meiliKey: 'my-secret-key' });
        expect(env.MEILISEARCH_KEY).toBe('my-secret-key');
    });

    test('includes QBITTORRENT_URL using the given qbittorrentPort', () => {
        const env = buildEnv({ ...CONTEXT, qbittorrentPort: 9999 });
        expect(env.QBITTORRENT_URL).toBe('http://127.0.0.1:9999');
    });

    test('includes OAUTH_CALLBACK_ORIGIN using the given appPort', () => {
        const env = buildEnv({ ...CONTEXT, appPort: 12345 });
        expect(env.OAUTH_CALLBACK_ORIGIN).toBe('http://127.0.0.1:12345');
    });

    test('inherits existing process.env variables', () => {
        const env = buildEnv(CONTEXT);
        expect(env).toMatchObject(process.env);
    });

    test('includes APP_SECRET from getOrCreateAppSecret', () => {
        const env = buildEnv(CONTEXT);
        expect(env.APP_SECRET).toBe('a'.repeat(64));
        expect(env.APP_SECRET).toHaveLength(64);
    });
});

describe('start', () => {
    // messenger:setup-transports идёт через общую обёртку php-command.js (issue #400) — она сама
    // отвечает за env, лог, таймаут и PID-трекинг; messenger:consume остаётся собственным
    // долгоживущим спавном, т.к. это не разовый вызов.
    test('runs messenger:setup-transports via php-command.js before spawning messenger:consume', async () => {
        mockPhpCommandRun.mockResolvedValueOnce(undefined);
        spawn.mockReturnValueOnce(createFakeChild());

        await start(7700, 'test-key');

        expect(mockPhpCommandRun).toHaveBeenCalledWith('messenger:setup-transports', [], 7700, expect.any(Number));
        expect(spawn).toHaveBeenCalledTimes(1);
        expect(spawn.mock.calls[0][1]).toEqual(expect.arrayContaining(['messenger:consume', 'async']));
    });

    // Order is priority, not just membership (issue #508): `media` must come after `async` so
    // the worker only ever drains it once `async` is empty — see spawnProcess()'s docblock.
    test('consumes the async transport before the media transport', async () => {
        mockPhpCommandRun.mockResolvedValueOnce(undefined);
        spawn.mockReturnValueOnce(createFakeChild());

        await start(7700, 'test-key');

        const args = spawn.mock.calls[0][1];
        expect(args.slice(-3)).toEqual(['messenger:consume', 'async', 'media']);
    });

    test('does not spawn messenger:consume when messenger:setup-transports rejects', async () => {
        mockPhpCommandRun.mockRejectedValueOnce(new Error('messenger:setup-transports завершился с кодом 1'));

        await expect(start(7700, 'test-key')).rejects.toThrow(/messenger:setup-transports/);
        expect(spawn).not.toHaveBeenCalled();
    });

    test('propagates a php-command.js spawn-failure error unchanged', async () => {
        mockPhpCommandRun.mockRejectedValueOnce(new Error('не удалось запустить messenger:setup-transports: ENOENT'));

        await expect(start(7700, 'test-key')).rejects.toThrow(/не удалось запустить messenger:setup-transports/);
    });

    test('propagates a php-command.js timeout error unchanged', async () => {
        mockPhpCommandRun.mockRejectedValueOnce(new Error('messenger:setup-transports не завершился за 30000ms и был принудительно остановлен'));

        await expect(start(7700, 'test-key')).rejects.toThrow(/messenger:setup-transports не завершился за/);
        expect(spawn).not.toHaveBeenCalled();
    });
});
