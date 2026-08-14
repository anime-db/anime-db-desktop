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

describe('buildEnv', () => {
    test('does not include HTTP-specific ports', () => {
        const env = buildEnv(7700, 'test-key');
        expect(env.APP_PORT).toBeUndefined();
        expect(env.WS_PORT).toBeUndefined();
    });

    test('includes APP_ROOT pointing to the app directory', () => {
        const env = buildEnv(7700, 'test-key');
        expect(env.APP_ROOT).toBe('/fake/app');
    });

    test('includes APP_ENV set to prod', () => {
        const env = buildEnv(7700, 'test-key');
        expect(env.APP_ENV).toBe('prod');
    });

    test('includes CORE_VERSION from app.getVersion()', () => {
        const env = buildEnv(7700, 'test-key');
        expect(env.CORE_VERSION).toBe('1.2.3');
    });

    test('includes DATABASE_URL as a sqlite:// URL', () => {
        const env = buildEnv(7700, 'test-key');
        expect(env.DATABASE_URL).toMatch(/^sqlite:\/\/\//);
        expect(env.DATABASE_URL).toContain('data.db');
    });

    test('includes QUEUE_DATABASE_URL as a sqlite:// URL pointing to a different file than DATABASE_URL', () => {
        const env = buildEnv(7700, 'test-key');
        expect(env.QUEUE_DATABASE_URL).toMatch(/^sqlite:\/\/\//);
        expect(env.QUEUE_DATABASE_URL).toContain('queue.db');
        expect(env.QUEUE_DATABASE_URL).not.toBe(env.DATABASE_URL);
    });

    test('includes MESSENGER_TRANSPORT_DSN pointing to the queue connection', () => {
        const env = buildEnv(7700, 'test-key');
        expect(env.MESSENGER_TRANSPORT_DSN).toBe('doctrine://queue?auto_setup=0');
    });

    test('includes PHPRC pointing to the php.ini directory', () => {
        const env = buildEnv(7700, 'test-key');
        expect(env.PHPRC).toBe('/fake/userData');
    });

    test('includes APP_RUNTIME_DIR pointing to the runtime dir', () => {
        const env = buildEnv(7700, 'test-key');
        expect(env.APP_RUNTIME_DIR).toBe('/fake/userData/var');
    });

    test('includes MEDIA_DIR pointing to the media dir', () => {
        const env = buildEnv(7700, 'test-key');
        expect(env.MEDIA_DIR).toBe('/fake/userData/media');
    });

    test('includes CONFIG_PATH pointing to config.json', () => {
        const env = buildEnv(7700, 'test-key');
        expect(env.CONFIG_PATH).toBe('/fake/userData/config.json');
    });

    test('includes PLUGINS_CONFIG_PATH pointing to plugins.json', () => {
        const env = buildEnv(7700, 'test-key');
        expect(env.PLUGINS_CONFIG_PATH).toBe('/fake/userData/plugins.json');
    });

    test('includes MEILISEARCH_URL using the given meiliPort', () => {
        const env = buildEnv(7700, 'test-key');
        expect(env.MEILISEARCH_URL).toBe('http://127.0.0.1:7700');

        const env2 = buildEnv(8888, 'test-key');
        expect(env2.MEILISEARCH_URL).toBe('http://127.0.0.1:8888');
    });

    test('includes MEILISEARCH_KEY matching the provided key', () => {
        const env = buildEnv(7700, 'my-secret-key');
        expect(env.MEILISEARCH_KEY).toBe('my-secret-key');
    });

    test('inherits existing process.env variables', () => {
        const env = buildEnv(7700, 'test-key');
        expect(env).toMatchObject(process.env);
    });

    test('includes APP_SECRET from getOrCreateAppSecret', () => {
        const env = buildEnv(7700, 'test-key');
        expect(env.APP_SECRET).toBe('a'.repeat(64));
        expect(env.APP_SECRET).toHaveLength(64);
    });
});

describe('start', () => {
    test('runs messenger:setup-transports before spawning messenger:consume', async () => {
        spawn.mockReturnValueOnce(createFakeChild(0)).mockReturnValueOnce(createFakeChild());

        await start(7700, 'test-key');

        expect(spawn).toHaveBeenCalledTimes(2);
        expect(spawn.mock.calls[0][1]).toEqual(expect.arrayContaining(['messenger:setup-transports']));
        expect(spawn.mock.calls[1][1]).toEqual(expect.arrayContaining(['messenger:consume', 'async']));
    });

    test('does not spawn messenger:consume when messenger:setup-transports exits non-zero', async () => {
        spawn.mockReturnValueOnce(createFakeChild(1));

        await expect(start(7700, 'test-key')).rejects.toThrow(/messenger:setup-transports/);
        expect(spawn).toHaveBeenCalledTimes(1);
    });

    test('rejects with a descriptive error when messenger:setup-transports fails to spawn', async () => {
        const fakeChild = {
            stdout: { on: jest.fn() },
            stderr: { on: jest.fn() },
            on: jest.fn((event, cb) => {
                if (event === 'error') cb(new Error('ENOENT'));
            }),
        };
        spawn.mockReturnValueOnce(fakeChild);

        await expect(start(7700, 'test-key')).rejects.toThrow(/не удалось запустить messenger:setup-transports/);
    });
});
