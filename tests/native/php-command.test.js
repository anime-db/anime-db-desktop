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
    getDbPath:             jest.fn(() => '/fake/userData/data.db'),
    getQueueDbPath:        jest.fn(() => '/fake/userData/queue.db'),
    getPhpIniDir:          jest.fn(() => '/fake/userData'),
    getRuntimeDir:         jest.fn(() => '/fake/userData/var'),
    getMediaDir:           jest.fn(() => '/fake/userData/media'),
    getConfigPath:         jest.fn(() => '/fake/userData/config.json'),
    getPluginsConfigPath:  jest.fn(() => '/fake/userData/plugins.json'),
    getPluginsDir:         jest.fn(() => '/fake/userData/plugins'),
    getMarketRegistryCachePath: jest.fn(() => '/fake/userData/market-registry-cache.json'),
}));
jest.mock('../../native/config', () => ({
    getOrCreateAppSecret: jest.fn(() => 'a'.repeat(64)),
}));

const mockLogStreamWrite = jest.fn();
const mockLogStreamEnd   = jest.fn();
jest.mock('../../native/supervisor/logrotate', () => ({
    pruneOldLogs:  jest.fn(),
    openLogStream: jest.fn(() => ({ write: mockLogStreamWrite, end: mockLogStreamEnd })),
}));

const mockWritePid   = jest.fn();
const mockClearPid   = jest.fn();
const mockKillOrphan = jest.fn(() => Promise.resolve());
jest.mock('../../native/supervisor/pid-tracker', () => ({
    writePid:   (...args) => mockWritePid(...args),
    clearPid:   (...args) => mockClearPid(...args),
    killOrphan: (...args) => mockKillOrphan(...args),
}));

const { EventEmitter } = require('events');
const { spawn } = require('child_process');

jest.mock('child_process', () => ({
    spawn: jest.fn(),
}));

const { pruneOldLogs, openLogStream } = require('../../native/supervisor/logrotate');
const { run, killOrphan } = require('../../native/supervisor/php-command');

const CONTEXT = { appPort: 8000, qbittorrentPort: 9999, meiliPort: 7700, meiliKey: 'test-key' };

/**
 * @returns {EventEmitter & { stdout: EventEmitter, stderr: EventEmitter, kill: jest.Mock }}
 */
function createFakeChild() {
    const child = new EventEmitter();
    child.stdout = new EventEmitter();
    child.stderr = new EventEmitter();
    child.kill = jest.fn();
    child.pid = 4242;
    return child;
}

let fakeChild;

beforeEach(() => {
    fakeChild = createFakeChild();
    spawn.mockReturnValue(fakeChild);
});

afterEach(() => {
    jest.clearAllMocks();
});

describe('run', () => {
    test('spawns frankenphp.exe running the given command via php-cli, with extra args', () => {
        run('app:search:reindex', ['--foo'], CONTEXT, 1000);

        expect(spawn).toHaveBeenCalledWith(
            expect.stringContaining('frankenphp.exe'),
            ['php-cli', expect.stringContaining('console'), 'app:search:reindex', '--foo'],
            expect.objectContaining({ cwd: '/fake/app' }),
        );
    });

    test('builds the process env from the shared module', () => {
        run('app:search:reindex', [], CONTEXT, 1000);

        const { env } = spawn.mock.calls[0][2];
        expect(env.PLUGINS_DIR).toBe('/fake/userData/plugins');
        expect(env.MEILISEARCH_URL).toBe('http://127.0.0.1:7700');
        expect(env.MEILISEARCH_KEY).toBe('test-key');
    });

    test('logs to a file named after the command, with ":" replaced by "-"', () => {
        run('app:search:reindex', [], CONTEXT, 1000);

        expect(pruneOldLogs).toHaveBeenCalledWith(expect.any(String), 'app-search-reindex', expect.any(Number));
        expect(openLogStream).toHaveBeenCalledWith(expect.any(String), 'app-search-reindex');
    });

    test('mirrors stdout and stderr into the log stream', () => {
        run('app:search:reindex', [], CONTEXT, 1000);

        fakeChild.stdout.emit('data', Buffer.from('reindexing...\n'));
        fakeChild.stderr.emit('data', Buffer.from('warning: slow\n'));

        expect(mockLogStreamWrite).toHaveBeenCalledWith(Buffer.from('reindexing...\n'));
        expect(mockLogStreamWrite).toHaveBeenCalledWith(Buffer.from('warning: slow\n'));
    });

    test('tracks and clears the PID under the sanitized command name', async () => {
        const promise = run('app:search:reindex', [], CONTEXT, 1000);
        expect(mockWritePid).toHaveBeenCalledWith('app-search-reindex', 4242);

        fakeChild.emit('exit', 0);
        await promise;

        expect(mockClearPid).toHaveBeenCalledWith('app-search-reindex');
    });

    test('resolves when the process exits with code 0', async () => {
        const promise = run('app:search:reindex', [], CONTEXT, 1000);
        fakeChild.emit('exit', 0);
        await expect(promise).resolves.toBeUndefined();
    });

    test('rejects with the exit code and collected output when the process exits non-zero', async () => {
        const promise = run('app:search:reindex', [], CONTEXT, 1000);
        fakeChild.stderr.emit('data', Buffer.from('boom'));
        fakeChild.emit('exit', 1);
        await expect(promise).rejects.toThrow(/app:search:reindex завершился с кодом 1[\s\S]*boom/);
    });

    test('rejects when the process fails to spawn', async () => {
        const promise = run('app:search:reindex', [], CONTEXT, 1000);
        fakeChild.emit('error', new Error('spawn ENOENT'));
        await expect(promise).rejects.toThrow(/spawn ENOENT/);
    });

    test('kills the process and rejects with a timeout error once timeoutMs elapses', async () => {
        jest.useFakeTimers();
        try {
            const promise = run('app:search:reindex', [], CONTEXT, 5000);
            const assertion = expect(promise).rejects.toThrow(/app:search:reindex не завершился за 5000ms/);

            await jest.advanceTimersByTimeAsync(5000);
            expect(fakeChild.kill).toHaveBeenCalledWith('SIGKILL');

            fakeChild.emit('exit', null);
            await assertion;

            expect(mockLogStreamWrite).toHaveBeenCalledWith(expect.stringContaining('timed out'));
        } finally {
            jest.useRealTimers();
        }
    });

    test('does not reject twice when the process exits normally after the timeout already fired', async () => {
        jest.useFakeTimers();
        try {
            const promise = run('app:search:reindex', [], CONTEXT, 5000);
            promise.catch(() => {});

            await jest.advanceTimersByTimeAsync(5000);
            fakeChild.emit('exit', 0);

            await expect(promise).rejects.toThrow(/не завершился за 5000ms/);
        } finally {
            jest.useRealTimers();
        }
    });
});

describe('killOrphan', () => {
    test('delegates to pid-tracker with the sanitized command name and the frankenphp binary', async () => {
        await killOrphan('messenger:setup-transports');
        expect(mockKillOrphan).toHaveBeenCalledWith('messenger-setup-transports', expect.stringContaining('frankenphp.exe'));
    });
});
