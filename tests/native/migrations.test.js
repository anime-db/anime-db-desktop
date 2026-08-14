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
    getBackupsDir:         jest.fn(() => '/fake/userData/backups'),
}));
jest.mock('../../native/config', () => ({
    getOrCreateAppSecret: jest.fn(() => 'a'.repeat(64)),
}));

const mockLogStreamWrite = jest.fn();
const mockLogStreamEnd = jest.fn();
jest.mock('../../native/supervisor/logrotate', () => ({
    pruneOldLogs:  jest.fn(),
    openLogStream: jest.fn(() => ({ write: mockLogStreamWrite, end: mockLogStreamEnd })),
    todayStr:      jest.fn(() => '2026-08-14'),
}));

const { EventEmitter } = require('events');
const fs = require('fs');
const { spawn } = require('child_process');

jest.mock('child_process', () => ({
    spawn: jest.fn(),
}));

const { run, MigrationBootstrapError, MAX_BACKUPS } = require('../../native/supervisor/migrations');

// Миграции стартуют до веб-воркера, поэтому appPort в их контексте отсутствует (см. env.js).
const CONTEXT = { qbittorrentPort: 9000, meiliPort: 7700, meiliKey: 'k' };

function commandKeyFromArgs(args) {
    if (args.includes('doctrine:migrations:up-to-date')) return 'up-to-date';
    if (args.includes('doctrine:migrations:migrate')) return 'migrate';
    if (args.includes('app:database:backup')) return 'backup';
    throw new Error(`unrecognized console args: ${args.join(' ')}`);
}

/**
 * Queues { code, stdout, stderr } responses per console command and wires spawn() to resolve
 * them asynchronously, in the order run() actually invokes them (up-to-date, then optionally
 * backup, then migrate — possibly twice on retry).
 *
 * An up-to-date response with code 1 defaults its stdout to the real doctrine "Out-of-date!"
 * message (see UpToDateCommand::execute()) unless the test overrides it — run() now requires
 * that marker before treating a bare exit code 1 as "pending migrations" rather than a crash.
 *
 * @param {Record<string, Array<{ code: number, stdout?: string, stderr?: string }>>} responses
 */
function mockConsoleResponses(responses) {
    spawn.mockImplementation((_bin, args) => {
        const child = new EventEmitter();
        child.stdout = new EventEmitter();
        child.stderr = new EventEmitter();

        const key = commandKeyFromArgs(args);
        const queue = responses[key];
        const resp = queue.shift();
        const stdout = resp.stdout ?? (key === 'up-to-date' && resp.code === 1 ? 'Out-of-date! 1 migration is available to execute.' : undefined);

        setImmediate(() => {
            if (stdout) child.stdout.emit('data', Buffer.from(stdout));
            if (resp.stderr) child.stderr.emit('data', Buffer.from(resp.stderr));
            child.emit('exit', resp.code);
        });

        return child;
    });
}

beforeEach(() => {
    jest.spyOn(fs, 'mkdirSync').mockImplementation(() => {});
    jest.spyOn(fs, 'readdirSync').mockReturnValue([]);
    jest.spyOn(fs, 'rmSync').mockImplementation(() => {});
    jest.spyOn(fs, 'copyFileSync').mockImplementation(() => {});
    jest.spyOn(fs, 'writeFileSync').mockImplementation(() => {});
});

afterEach(() => {
    jest.restoreAllMocks();
    jest.clearAllMocks();
});

describe('env', () => {
    // Полный состав переменных проверяется в tests/native/env.test.js — здесь важно только то,
    // что консольные вызовы схемы получают именно общий env (issue #391), включая PLUGINS_DIR:
    // миграции бутуют ядро первыми и запекают набор бандлов плагинов в дамп контейнера.
    test('spawns console commands with the shared env, without OAUTH_CALLBACK_ORIGIN', async () => {
        mockConsoleResponses({ 'up-to-date': [{ code: 0 }] });

        await run(CONTEXT);

        const { env } = spawn.mock.calls[0][2];
        expect(env.PLUGINS_DIR).toBe('/fake/userData/plugins');
        expect(env.MEILISEARCH_URL).toBe('http://127.0.0.1:7700');
        expect(env.MEILISEARCH_KEY).toBe('k');
        expect(env.QBITTORRENT_URL).toBe('http://127.0.0.1:9000');
        expect(env.OAUTH_CALLBACK_ORIGIN).toBeUndefined();
    });
});

describe('run', () => {
    test('does nothing when the schema is already up-to-date (status 0)', async () => {
        mockConsoleResponses({ 'up-to-date': [{ code: 0 }] });

        await expect(run(CONTEXT)).resolves.toBeUndefined();
        expect(spawn).toHaveBeenCalledTimes(1);
    });

    test('rejects with a downgrade error on status 2, without attempting a backup or migrate', async () => {
        mockConsoleResponses({ 'up-to-date': [{ code: 2 }] });

        await expect(run(CONTEXT)).rejects.toMatchObject(
            { kind: 'downgrade' },
        );
        expect(spawn).toHaveBeenCalledTimes(1);
    });

    test('backs up and migrates when status is 1, and prunes old backups afterwards', async () => {
        mockConsoleResponses({
            'up-to-date': [{ code: 1 }],
            backup:       [{ code: 0 }],
            migrate:      [{ code: 0 }],
        });

        await expect(run(CONTEXT)).resolves.toBeUndefined();

        const backupCall = spawn.mock.calls.find(([, args]) => args.includes('app:database:backup'));
        expect(backupCall[1]).toEqual(expect.arrayContaining([
            'php-cli', expect.stringContaining('console'), 'app:database:backup',
            expect.stringMatching(/data-1\.2\.3-\d{8}-\d{6}\.db$/),
        ]));

        const migrateCall = spawn.mock.calls.find(([, args]) => args.includes('doctrine:migrations:migrate'));
        expect(migrateCall[1]).toEqual(expect.arrayContaining([
            'doctrine:migrations:migrate', '--no-interaction', '--allow-no-migration',
        ]));
    });

    test('restores the backup and retries once when the first migrate attempt fails, then succeeds', async () => {
        mockConsoleResponses({
            'up-to-date': [{ code: 1 }],
            backup:       [{ code: 0 }],
            migrate:      [{ code: 1, stderr: 'boom first try' }, { code: 0 }],
        });

        await expect(run(CONTEXT)).resolves.toBeUndefined();

        expect(fs.copyFileSync).toHaveBeenCalledTimes(1);
        expect(fs.rmSync).toHaveBeenCalledWith('/fake/userData/data.db-journal', { force: true });
        const migrateCalls = spawn.mock.calls.filter(([, args]) => args.includes('doctrine:migrations:migrate'));
        expect(migrateCalls).toHaveLength(2);
    });

    test('fails closed with a migrate-failed error, including the backup path, when both attempts fail', async () => {
        mockConsoleResponses({
            'up-to-date': [{ code: 1 }],
            backup:       [{ code: 0 }],
            migrate:      [{ code: 1, stderr: 'first' }, { code: 1, stderr: 'second' }],
        });

        await expect(run(CONTEXT)).rejects.toMatchObject({
            kind:   'migrate-failed',
            detail: 'second',
        });

        // The DB must not be left in a partially-migrated state after the second failure too —
        // restored once after the first failed attempt, and again after the second.
        expect(fs.copyFileSync).toHaveBeenCalledTimes(2);
    });

    test('treats a bare exit code 1 without the doctrine "Out-of-date!" marker as a crash, not pending migrations', async () => {
        mockConsoleResponses({
            'up-to-date': [{ code: 1, stdout: '', stderr: 'Fatal error: could not open php.ini' }],
        });

        await expect(run(7700, 'k', 9000)).rejects.toMatchObject({
            kind:   'migrate-failed',
            detail: expect.stringContaining('could not open php.ini'),
        });
        // Never gets to createBackup()/migrate() — a real crash isn't "pending migrations".
        expect(spawn).toHaveBeenCalledTimes(1);
    });

    test('fails closed with a backup-failed error and never attempts migrate when the backup command fails', async () => {
        mockConsoleResponses({
            'up-to-date': [{ code: 1 }],
            backup:       [{ code: 1, stderr: 'disk full' }],
        });

        await expect(run(CONTEXT)).rejects.toMatchObject({
            kind:   'backup-failed',
            detail: 'disk full',
        });
        expect(spawn.mock.calls.some(([, args]) => args.includes('doctrine:migrations:migrate'))).toBe(false);
    });

    test('every thrown error is a MigrationBootstrapError carrying a log path', async () => {
        mockConsoleResponses({ 'up-to-date': [{ code: 2 }] });

        try {
            await run(CONTEXT);
            throw new Error('expected run() to reject');
        } catch (err) {
            expect(err).toBeInstanceOf(MigrationBootstrapError);
            expect(err.logPath).toContain('migrations-');
        }
    });

    test('prunes backups older than MAX_BACKUPS by timestamp, not by version string', async () => {
        mockConsoleResponses({
            'up-to-date': [{ code: 1 }],
            backup:       [{ code: 0 }],
            migrate:      [{ code: 0 }],
        });
        // Older backups were taken on app version 1.9.0; the newest is from the just-updated
        // 1.10.0. Lexicographically "1.10.0" < "1.9.0", so a naive filename sort would prune the
        // newest backup first — assert it survives and only the oldest ones are removed instead.
        const excessCount = 2;
        const oldest = Array.from({ length: excessCount }, (_, i) => `data-1.9.0-2026010${i + 1}-000000.db`);
        const kept   = Array.from(
            { length: MAX_BACKUPS - 1 },
            (_, i) => `data-1.9.0-2026010${i + 1 + excessCount}-000000.db`,
        );
        const newest = `data-1.10.0-2026010${MAX_BACKUPS + excessCount}-000000.db`;
        fs.readdirSync.mockReturnValue([...oldest, ...kept, newest]);

        await run(CONTEXT);

        expect(fs.rmSync).toHaveBeenCalledTimes(excessCount);
        for (const file of oldest) {
            expect(fs.rmSync).toHaveBeenCalledWith(expect.stringContaining(file), expect.anything());
        }
        expect(fs.rmSync).not.toHaveBeenCalledWith(expect.stringContaining(newest), expect.anything());
    });
});
