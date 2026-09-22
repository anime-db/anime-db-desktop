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

jest.mock('../../native/paths', () => ({
    getImportStagingDir: jest.fn(() => '/fake/userData/import-staging'),
    getMediaDir:          jest.fn(() => '/fake/userData/media'),
}));

const mockRestoreBackup = jest.fn();
const mockCreatePreImportBackup = jest.fn();
const mockMigrationsRun = jest.fn();
jest.mock('../../native/supervisor/migrations', () => ({
    restoreBackup:         (...args) => mockRestoreBackup(...args),
    createPreImportBackup: (...args) => mockCreatePreImportBackup(...args),
    run:                   (...args) => mockMigrationsRun(...args),
}));

const mockRunSetupTransports = jest.fn();
jest.mock('../../native/supervisor/messenger-consumer', () => ({
    runSetupTransports: (...args) => mockRunSetupTransports(...args),
}));

const mockPhpCommandRun = jest.fn();
jest.mock('../../native/supervisor/php-command', () => ({
    run: (...args) => mockPhpCommandRun(...args),
}));

const mockSearchReindexRun = jest.fn();
jest.mock('../../native/supervisor/search-reindex', () => ({
    run: (...args) => mockSearchReindexRun(...args),
}));

const fs = require('fs');
const { apply } = require('../../native/supervisor/import-apply');

const CONTEXT = { qbittorrentPort: 9000, meiliPort: 7700, meiliKey: 'k' };
const STAGING_DIR = '/fake/userData/import-staging';
const MEDIA_DIR = '/fake/userData/media';
const PREIMPORT_BACKUP_PATH = '/fake/userData/backups/data-preimport-20260922-000000.db';

beforeEach(() => {
    jest.clearAllMocks();
    jest.spyOn(fs, 'rmSync').mockImplementation(() => {});
    jest.spyOn(fs, 'mkdirSync').mockImplementation(() => {});
    jest.spyOn(fs, 'existsSync').mockReturnValue(true);
    jest.spyOn(fs, 'renameSync').mockImplementation(() => {});

    mockCreatePreImportBackup.mockResolvedValue(PREIMPORT_BACKUP_PATH);
    mockMigrationsRun.mockResolvedValue(false);
    mockRunSetupTransports.mockResolvedValue(undefined);
    mockPhpCommandRun.mockResolvedValue(undefined);
    mockSearchReindexRun.mockResolvedValue(undefined);
});

afterEach(() => {
    jest.restoreAllMocks();
});

describe('apply() — success path', () => {
    test('takes the pre-import backup before touching any file on disk', async () => {
        const callOrder = [];
        mockCreatePreImportBackup.mockImplementation(() => {
            callOrder.push('createPreImportBackup');
            return Promise.resolve(PREIMPORT_BACKUP_PATH);
        });
        fs.rmSync.mockImplementation(() => callOrder.push('rmSync'));
        mockRestoreBackup.mockImplementation(() => callOrder.push('restoreBackup'));

        await apply(CONTEXT);

        expect(callOrder[0]).toBe('createPreImportBackup');
        expect(mockCreatePreImportBackup).toHaveBeenCalledWith(CONTEXT);
    });

    // Acceptance criterion 9: the marker must be gone before the swap, so a crash partway through
    // the remaining steps can never let the next start re-apply the same staging.
    test('removes the marker before swapping data.db', async () => {
        const callOrder = [];
        fs.rmSync.mockImplementation((target) => {
            if (target === `${STAGING_DIR}/import.json`) callOrder.push('rm marker');
        });
        mockRestoreBackup.mockImplementation((source) => {
            if (source === `${STAGING_DIR}/data.db`) callOrder.push('restoreBackup(data.db)');
        });

        await apply(CONTEXT);

        expect(callOrder).toEqual(['rm marker', 'restoreBackup(data.db)']);
    });

    // Reuses migrations.js#restoreBackup() for the data.db swap itself — it already clears the
    // -journal/-wal/-shm sidecars ahead of the copy (acceptance criterion 10) and copies via a
    // temp file + rename, the same atomicity a staged data.db swap needs.
    test('swaps data.db via migrations.restoreBackup() pointed at the staged file', async () => {
        await apply(CONTEXT);

        expect(mockRestoreBackup).toHaveBeenCalledWith(`${STAGING_DIR}/data.db`);
    });

    // Acceptance criterion 13: media/ is replaced wholesale, not merged — the previous media/ is
    // removed entirely and the staged one takes its place.
    test('replaces media/ wholesale by removing the existing directory and renaming the staged one into place', async () => {
        const callOrder = [];
        fs.rmSync.mockImplementation((target) => {
            if (target === MEDIA_DIR) callOrder.push('rm media');
        });
        fs.renameSync.mockImplementation((source, dest) => {
            if (source === `${STAGING_DIR}/media` && dest === MEDIA_DIR) callOrder.push('rename media');
        });

        await apply(CONTEXT);

        expect(callOrder).toEqual(['rm media', 'rename media']);
        expect(fs.mkdirSync).not.toHaveBeenCalledWith(MEDIA_DIR, expect.anything());
    });

    // Acceptance criterion 14: an archive staged without its own media/ must not fail the import —
    // the previous media/ is still cleared (never merged), just replaced with an empty directory.
    test('clears media/ into an empty directory when the staged archive has none', async () => {
        fs.existsSync.mockImplementation((target) => target !== `${STAGING_DIR}/media`);

        await apply(CONTEXT);

        expect(fs.rmSync).toHaveBeenCalledWith(MEDIA_DIR, { recursive: true, force: true });
        expect(fs.renameSync).not.toHaveBeenCalled();
        expect(fs.mkdirSync).toHaveBeenCalledWith(MEDIA_DIR, { recursive: true });
    });

    // Step 3: the swapped-in database goes through the same migration bootstrap the working
    // database already went through earlier in start() — a staged dump can be from an older
    // schema version (acceptance criterion 4).
    test('bootstraps migrations against the swapped-in database', async () => {
        await apply(CONTEXT);

        expect(mockMigrationsRun).toHaveBeenCalledWith(CONTEXT);
    });

    // Step 4: purge only after messenger:setup-transports has created its table, and named exactly
    // app:queue:purge — see App\Command\QueuePurgeCommand.
    test('purges the queue after messenger:setup-transports, before rebuilding the index', async () => {
        const callOrder = [];
        mockRunSetupTransports.mockImplementation(() => {
            callOrder.push('runSetupTransports');
            return Promise.resolve();
        });
        mockPhpCommandRun.mockImplementation(() => {
            callOrder.push('app:queue:purge');
            return Promise.resolve();
        });
        mockSearchReindexRun.mockImplementation(() => {
            callOrder.push('searchReindex.run');
            return Promise.resolve();
        });

        await apply(CONTEXT);

        expect(callOrder).toEqual(['runSetupTransports', 'app:queue:purge', 'searchReindex.run']);
        expect(mockPhpCommandRun).toHaveBeenCalledWith('app:queue:purge', [], CONTEXT, expect.any(Number));
    });

    // Acceptance criterion 3 / issue #707: unconditional, unlike the ordinary upgrade path's own
    // reindex (index.js), which only runs when wiped/migrationsApplied/reindexRequired — an import
    // targeting the same schema version must still rebuild the index even though migrate() applied
    // nothing.
    test('rebuilds the search index even when no migration was applied', async () => {
        mockMigrationsRun.mockResolvedValue(false);

        await apply(CONTEXT);

        expect(mockSearchReindexRun).toHaveBeenCalledWith(CONTEXT);
    });

    // Step 6: import-staging/ is only removed once every earlier step has succeeded (acceptance
    // criterion 9 — a second start must not find anything left to re-apply).
    test('removes import-staging/ only after every step has succeeded, and resolves applied:true', async () => {
        const result = await apply(CONTEXT);

        expect(fs.rmSync).toHaveBeenCalledWith(STAGING_DIR, { recursive: true, force: true });
        expect(result).toEqual({ applied: true, error: null });
    });
});

describe('apply() — rollback on failure (acceptance criteria 6, 7, 8)', () => {
    test.each([
        ['migrations.run (step 3)', () => mockMigrationsRun.mockRejectedValue(new Error('migrate-failed'))],
        ['messenger:setup-transports (step 4)', () => mockRunSetupTransports.mockRejectedValue(new Error('setup-transports failed'))],
        ['app:queue:purge (step 4)', () => mockPhpCommandRun.mockRejectedValue(new Error('queue purge failed'))],
    ])('rolls back to the pre-import backup and rebuilds the index when %s fails', async (_label, breakStep) => {
        breakStep();

        const result = await apply(CONTEXT);

        expect(mockRestoreBackup).toHaveBeenCalledWith(PREIMPORT_BACKUP_PATH);
        expect(mockSearchReindexRun).toHaveBeenLastCalledWith(CONTEXT);
        expect(result.applied).toBe(false);
        expect(result.error).toBeInstanceOf(Error);
    });

    // The reindex step (5) itself failing must roll back too — a valid catalog paired with a
    // stale/missing index is worse than staying on the previous one.
    test('rolls back when the post-swap reindex itself fails', async () => {
        mockSearchReindexRun.mockRejectedValueOnce(new Error('reindex failed')).mockResolvedValueOnce(undefined);

        const result = await apply(CONTEXT);

        expect(mockRestoreBackup).toHaveBeenCalledWith(PREIMPORT_BACKUP_PATH);
        expect(mockSearchReindexRun).toHaveBeenCalledTimes(2);
        expect(result.applied).toBe(false);
    });

    test('never removes import-staging/ when a rollback happened', async () => {
        mockMigrationsRun.mockRejectedValue(new Error('migrate-failed'));

        await apply(CONTEXT);

        expect(fs.rmSync).not.toHaveBeenCalledWith(STAGING_DIR, { recursive: true, force: true });
    });

    // restoreBackup() runs before the recovery reindex, never after — the index must be rebuilt
    // against the catalog that is actually back in place.
    test('restores the backup before rebuilding the index during rollback', async () => {
        mockMigrationsRun.mockRejectedValue(new Error('migrate-failed'));
        const callOrder = [];
        mockRestoreBackup.mockImplementation((path) => {
            if (path === PREIMPORT_BACKUP_PATH) callOrder.push('restoreBackup(preimport)');
        });
        mockSearchReindexRun.mockImplementation(() => {
            callOrder.push('searchReindex.run');
            return Promise.resolve();
        });

        await apply(CONTEXT);

        expect(callOrder).toEqual(['restoreBackup(preimport)', 'searchReindex.run']);
    });

    // Acceptance criterion 8: a reindex failure must never be swallowed — including the recovery
    // reindex that runs after a rollback. Losing it silently would start the app on a restored
    // catalog paired with a search index that still describes the discarded one.
    test('propagates the error when the recovery reindex after a rollback also fails', async () => {
        mockMigrationsRun.mockRejectedValue(new Error('migrate-failed'));
        mockSearchReindexRun.mockRejectedValue(new Error('index rebuild also failed'));

        await expect(apply(CONTEXT)).rejects.toThrow('index rebuild also failed');
        expect(mockRestoreBackup).toHaveBeenCalledWith(PREIMPORT_BACKUP_PATH);
    });
});
