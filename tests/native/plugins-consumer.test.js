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
    getImportStagingDir:   jest.fn(() => '/fake/userData/import-staging'),
    getImportRejectionPath: jest.fn(() => '/fake/userData/import-rejected.json'),
    getImportAppliedPath: jest.fn(() => '/fake/userData/import-applied.json'),
    getConfigPath:         jest.fn(() => '/fake/userData/config.json'),
    getPluginsConfigPath:  jest.fn(() => '/fake/userData/plugins.json'),
    getPluginsDir:         jest.fn(() => '/fake/userData/plugins'),
    getNativeTranslationsDir:        jest.fn(() => '/fake/app/native/translations'),
    getNativeTranslationsOverlayDir: jest.fn(() => '/fake/userData/native-translations'),
    getMarketRegistryCachePath: jest.fn(() => '/fake/userData/market-registry-cache.json'),
    getMarketSnapshotCachePath: jest.fn(() => '/fake/userData/market-snapshot-cache.json'),
    getMarketRefreshLockPath:   jest.fn(() => '/fake/userData/market-refresh.lock'),
    getBackupsDir:        jest.fn(() => '/fake/userData/backups'),
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
    killTree:   jest.fn(() => Promise.resolve()),
    killTreeSync: jest.fn(),
}));
jest.mock('child_process', () => ({ spawn: jest.fn() }));

const { spawn } = require('child_process');
const { openLogStream } = require('../../native/supervisor/logrotate');
const { waitForProcessAlive } = require('../../native/supervisor/healthcheck');
const pidTracker = require('../../native/supervisor/pid-tracker');
const {
    buildEnv, start, stop, killSync, killOrphan, events,
} = require('../../native/supervisor/plugins-consumer');

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
        kill: jest.fn(),
    };
}

beforeEach(() => {
    jest.clearAllMocks();
    // clearAllMocks() clears call history but not a still-queued spawn.mockReturnValueOnce()
    // value — e.g. from a scheduled backoff respawn a test never advanced the fake timer past.
    // Reset just the spawn queue explicitly so it can never leak a stale fake child into the next
    // test (mockReset() is safe here since spawn has no default implementation to preserve).
    spawn.mockReset();
    jest.useFakeTimers();
    openLogStream.mockReturnValue({ write: jest.fn(), end: jest.fn() });
    waitForProcessAlive.mockResolvedValue(undefined);
});

afterEach(() => {
    jest.useRealTimers();
});

const CONTEXT = { appPort: 8000, qbittorrentPort: 7700, meiliPort: 7700, meiliKey: 'test-key' };

describe('buildEnv', () => {
    // Shares buildCommonEnv() with messenger-consumer.js (see env.js) — only a thin behavior
    // check here, the full env-shape coverage already lives in messenger-consumer.test.js.
    test('includes MESSENGER_TRANSPORT_DSN pointing to the queue connection', () => {
        const env = buildEnv(CONTEXT);
        expect(env.MESSENGER_TRANSPORT_DSN).toBe('doctrine://queue?auto_setup=0');
    });

    test('includes APP_ROOT pointing to the app directory', () => {
        const env = buildEnv(CONTEXT);
        expect(env.APP_ROOT).toBe('/fake/app');
    });
});

describe('start', () => {
    test('spawns messenger:consume for the plugins transport only', async () => {
        spawn.mockReturnValueOnce(createFakeChild());

        await start(CONTEXT);

        expect(spawn).toHaveBeenCalledTimes(1);
        expect(spawn.mock.calls[0][1].slice(-2)).toEqual(['messenger:consume', 'plugins']);
    });

    // issue #701: the acceptance criteria require the existing messenger-consumer's transport
    // list (`async media scheduler_downloads_poll`) to stay untouched by this change — this
    // module must never fold `plugins` into that same command line.
    test('does not include async, media or scheduler_downloads_poll on the command line', async () => {
        spawn.mockReturnValueOnce(createFakeChild());

        await start(CONTEXT);

        const args = spawn.mock.calls[0][1];
        expect(args).not.toContain('async');
        expect(args).not.toContain('media');
        expect(args).not.toContain('scheduler_downloads_poll');
    });

    test('tracks the spawned PID under its own name, distinct from messenger-consumer', async () => {
        const child = createFakeChild();
        child.pid = 4242;
        spawn.mockReturnValueOnce(child);

        await start(CONTEXT);

        expect(pidTracker.writePid).toHaveBeenCalledWith('plugins-consumer', 4242);
    });

    test('waits for the process to stay alive before resolving', async () => {
        const child = createFakeChild();
        spawn.mockReturnValueOnce(child);

        await start(CONTEXT);

        expect(waitForProcessAlive).toHaveBeenCalledWith(child);
    });

    test('rejects when the process exits before the alive check passes', async () => {
        spawn.mockReturnValueOnce(createFakeChild());
        waitForProcessAlive.mockRejectedValueOnce(new Error('процесс завершился до истечения проверки готовности (код 1)'));

        await expect(start(CONTEXT)).rejects.toThrow(/завершился до истечения проверки/);
    });
});

describe('restart with backoff', () => {
    test('respawns after the process exits, honoring the 1s/2s/4s backoff schedule', async () => {
        spawn.mockReturnValueOnce(createFakeChild());
        await start(CONTEXT);

        // First respawn after an unexpected exit.
        const firstChild = createFakeChild();
        spawn.mockReturnValueOnce(firstChild);
        spawn.mock.calls[0]; // no-op, keeps intent explicit that we inspect calls below
        const exitHandler = spawn.mock.results[0].value.on.mock.calls.find(([event]) => event === 'exit')[1];
        exitHandler(1);

        expect(spawn).toHaveBeenCalledTimes(1);
        jest.advanceTimersByTime(999);
        expect(spawn).toHaveBeenCalledTimes(1);
        jest.advanceTimersByTime(1);
        expect(spawn).toHaveBeenCalledTimes(2);

        // Second respawn backs off further (2s), not the same 1s delay again.
        const secondChild = createFakeChild();
        spawn.mockReturnValueOnce(secondChild);
        const secondExitHandler = firstChild.on.mock.calls.find(([event]) => event === 'exit')[1];
        secondExitHandler(1);

        jest.advanceTimersByTime(1999);
        expect(spawn).toHaveBeenCalledTimes(2);
        jest.advanceTimersByTime(1);
        expect(spawn).toHaveBeenCalledTimes(3);
    });

    test('emits an exit event on every unexpected exit', async () => {
        const child = createFakeChild();
        spawn.mockReturnValueOnce(child);
        await start(CONTEXT);

        // Not advancing timers below, so the scheduled respawn never actually spawns — no second
        // queued return value needed.
        const handler = jest.fn();
        events.once('exit', handler);

        const exitHandler = child.on.mock.calls.find(([event]) => event === 'exit')[1];
        exitHandler(137);

        expect(handler).toHaveBeenCalledWith(137);
    });

    test('does not respawn once stop() has been called', async () => {
        const child = createFakeChild();
        spawn.mockReturnValueOnce(child);
        await start(CONTEXT);

        const stopPromise = stop();
        // stop() registers its own 'exit' listener on top of spawnProcess's — the fake child's
        // `on` mock records both, in registration order, so the one stop() itself is waiting on
        // is the LAST 'exit' entry, not the first (that one belongs to spawnProcess's backoff
        // logic and no-ops once `stopping` is true).
        const exitCalls = child.on.mock.calls.filter(([event]) => event === 'exit');
        exitCalls[exitCalls.length - 1][1](0);
        await stopPromise;

        jest.advanceTimersByTime(60000);
        expect(spawn).toHaveBeenCalledTimes(1);
    });
});

describe('stop', () => {
    test('sends SIGTERM and resolves once the process exits', async () => {
        const child = createFakeChild();
        spawn.mockReturnValueOnce(child);
        await start(CONTEXT);

        const stopPromise = stop();
        const exitCalls = child.on.mock.calls.filter(([event]) => event === 'exit');
        exitCalls[exitCalls.length - 1][1](0);
        await stopPromise;

        expect(child.kill).toHaveBeenCalledWith('SIGTERM');
        expect(pidTracker.clearPid).toHaveBeenCalledWith('plugins-consumer');
    });

    test('escalates to SIGKILL if the process does not exit within the grace period', async () => {
        const child = createFakeChild();
        spawn.mockReturnValueOnce(child);
        await start(CONTEXT);

        stop();
        jest.advanceTimersByTime(500);

        expect(child.kill).toHaveBeenCalledWith('SIGKILL');
    });

    test('resolves immediately when no process is running', async () => {
        await expect(stop()).resolves.toBeUndefined();
    });
});

describe('killSync', () => {
    test('force-kills the running process', async () => {
        const child = createFakeChild();
        spawn.mockReturnValueOnce(child);
        await start(CONTEXT);

        killSync();

        expect(child.kill).toHaveBeenCalledWith('SIGKILL');
    });

    test('is a no-op when no process is running', () => {
        expect(() => killSync()).not.toThrow();
    });
});

describe('killOrphan', () => {
    test('delegates to pid-tracker under its own name, distinct from messenger-consumer', async () => {
        await killOrphan();

        expect(pidTracker.killOrphan).toHaveBeenCalledWith('plugins-consumer', expect.stringContaining('frankenphp'));
    });
});
