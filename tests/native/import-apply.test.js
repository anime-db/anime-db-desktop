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
const mockSearchReindexMarkRequired = jest.fn();
jest.mock('../../native/supervisor/search-reindex', () => ({
    run:          (...args) => mockSearchReindexRun(...args),
    markRequired: (...args) => mockSearchReindexMarkRequired(...args),
}));

const mockWriteRejection = jest.fn();
jest.mock('../../native/supervisor/staged-import', () => ({
    writeRejection: (...args) => mockWriteRejection(...args),
    RejectReason:   { IMPORT_ROLLED_BACK: 'import_rolled_back' },
}));

const fs = require('fs');
const { apply } = require('../../native/supervisor/import-apply');

const CONTEXT = { qbittorrentPort: 9000, meiliPort: 7700, meiliKey: 'k' };
const STAGING_DIR = '/fake/userData/import-staging';
const MEDIA_DIR = '/fake/userData/media';
const PRE_IMPORT_MEDIA_DIR = '/fake/userData/media.pre-import';
const PREIMPORT_BACKUP_PATH = '/fake/userData/backups/data-preimport-20260922-000000.db';

beforeEach(() => {
    jest.clearAllMocks();
    jest.spyOn(fs, 'rmSync').mockImplementation(() => {});
    jest.spyOn(fs, 'mkdirSync').mockImplementation(() => {});
    jest.spyOn(fs, 'existsSync').mockReturnValue(true);
    jest.spyOn(fs, 'renameSync').mockImplementation(() => {});
    jest.spyOn(console, 'error').mockImplementation(() => {});

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
    // moved aside (so a rollback can still restore it, see the rollback describe block below) and
    // the staged one takes its place.
    test('replaces media/ wholesale by moving the existing directory aside and renaming the staged one into place', async () => {
        const callOrder = [];
        fs.renameSync.mockImplementation((source, dest) => {
            if (source === MEDIA_DIR && dest === PRE_IMPORT_MEDIA_DIR) callOrder.push('move aside');
            if (source === `${STAGING_DIR}/media` && dest === MEDIA_DIR) callOrder.push('rename media');
        });

        await apply(CONTEXT);

        expect(callOrder).toEqual(['move aside', 'rename media']);
        expect(fs.rmSync).toHaveBeenCalledWith(PRE_IMPORT_MEDIA_DIR, { recursive: true, force: true });
    });

    // Acceptance criterion 14: an archive staged without its own media/ must not fail the import —
    // the previous media/ is still moved aside (never merged), and mediaDir is replaced with an
    // empty directory.
    test('clears media/ into an empty directory when the staged archive has none', async () => {
        fs.existsSync.mockImplementation((target) => target !== `${STAGING_DIR}/media`);

        await apply(CONTEXT);

        expect(fs.renameSync).toHaveBeenCalledWith(MEDIA_DIR, PRE_IMPORT_MEDIA_DIR);
        expect(fs.renameSync).not.toHaveBeenCalledWith(`${STAGING_DIR}/media`, MEDIA_DIR);
        expect(fs.mkdirSync).toHaveBeenCalledWith(MEDIA_DIR, { recursive: true });
    });

    // A leftover from a previous run that crashed mid-swap must not survive into the next one.
    test('clears any leftover pre-import media/ snapshot before moving the current one aside', async () => {
        const callOrder = [];
        fs.rmSync.mockImplementation((target) => {
            if (target === PRE_IMPORT_MEDIA_DIR) callOrder.push('rm leftover pre-import media');
        });
        fs.renameSync.mockImplementation((source, dest) => {
            if (source === MEDIA_DIR && dest === PRE_IMPORT_MEDIA_DIR) callOrder.push('move aside');
        });

        await apply(CONTEXT);

        expect(callOrder[0]).toBe('rm leftover pre-import media');
        expect(callOrder).toContain('move aside');
    });

    // Once every step has succeeded, the moved-aside previous media/ is no longer needed — on top
    // of swapCatalog()'s own leftover-clear at the start, apply() removes it again at the end.
    test('removes the moved-aside previous media/ once the import fully succeeds', async () => {
        await apply(CONTEXT);

        const preImportMediaRmCalls = fs.rmSync.mock.calls.filter(([target]) => target === PRE_IMPORT_MEDIA_DIR);
        expect(preImportMediaRmCalls).toHaveLength(2);
        expect(fs.rmSync).toHaveBeenCalledWith(STAGING_DIR, { recursive: true, force: true });
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

describe('apply() — rollback on failure (acceptance criteria 6, 7, 8; revised by issue #710)', () => {
    test.each([
        ['migrations.run (step 3)', () => mockMigrationsRun.mockRejectedValue(new Error('migrate-failed'))],
        ['messenger:setup-transports (step 4)', () => mockRunSetupTransports.mockRejectedValue(new Error('setup-transports failed'))],
        ['app:queue:purge (step 4)', () => mockPhpCommandRun.mockRejectedValue(new Error('queue purge failed'))],
    ])('rolls back to the pre-import backup and marks a reindex required when %s fails', async (_label, breakStep) => {
        breakStep();

        const result = await apply(CONTEXT);

        expect(mockRestoreBackup).toHaveBeenCalledWith(PREIMPORT_BACKUP_PATH);
        expect(mockSearchReindexRun).not.toHaveBeenCalled();
        expect(mockSearchReindexMarkRequired).toHaveBeenCalledTimes(1);
        expect(result.applied).toBe(false);
        expect(result.error).toBeInstanceOf(Error);
    });

    // Issue #710: the reindex step (5) itself failing must still roll back, but the recovery must
    // no longer re-run the reindex synchronously (same data, same timeout, as the failure just
    // handled) — it marks one required on the next start instead, the same mechanism
    // native/backup-restore/index.js already uses (issue #681).
    test('rolls back when the post-swap reindex itself fails, without re-running it synchronously', async () => {
        mockSearchReindexRun.mockRejectedValueOnce(new Error('reindex failed'));

        const result = await apply(CONTEXT);

        expect(mockRestoreBackup).toHaveBeenCalledWith(PREIMPORT_BACKUP_PATH);
        expect(mockSearchReindexRun).toHaveBeenCalledTimes(1);
        expect(mockSearchReindexMarkRequired).toHaveBeenCalledTimes(1);
        expect(result.applied).toBe(false);
    });

    // Regression guard for issue #710: previously import-staging/ was left in place (marker-less,
    // since swapCatalog() already removed it) for the next start's staged-import.js#decide() to
    // find — which can only ever see that as an invalid marker and mislabel the rejection reason.
    // Removing it here, alongside recording the real reason directly (see below), means decide()
    // never gets a chance to overwrite it.
    test('removes import-staging/ once the rollback has recorded its own rejection reason', async () => {
        mockMigrationsRun.mockRejectedValue(new Error('migrate-failed'));

        await apply(CONTEXT);

        expect(fs.rmSync).toHaveBeenCalledWith(STAGING_DIR, { recursive: true, force: true });
    });

    // Acceptance criterion 4 (issue #710): /settings/backup must report that the import was rolled
    // back, not the invalid_marker reason staged-import.js#decide() would otherwise (wrongly)
    // attribute this to on the next start.
    test('records the rollback as the rejection reason for /settings/backup', async () => {
        mockMigrationsRun.mockRejectedValue(new Error('migrate-failed'));

        await apply(CONTEXT);

        expect(mockWriteRejection).toHaveBeenCalledWith('import_rolled_back');
    });

    // Acceptance criterion 3 (issue #710): restoreCatalog()'s own failure must not propagate out of
    // apply() — the whole point of this change is that a startup never crashes on a failed import,
    // even a doubly-failed one.
    test('does not throw out of apply() even when restoreCatalog() itself fails', async () => {
        mockMigrationsRun.mockRejectedValue(new Error('migrate-failed'));
        // swapCatalog() also calls migrations.restoreBackup() (for the staged data.db, step 2) —
        // that first call must succeed; only the rollback's own second call, restoring
        // PREIMPORT_BACKUP_PATH, must fail here. Both are queued as *Once so neither implementation
        // leaks into later tests the way a persistent mockImplementation() would.
        mockRestoreBackup.mockImplementationOnce(() => {});
        mockRestoreBackup.mockImplementationOnce(() => {
            throw new Error('restore also failed');
        });

        await expect(apply(CONTEXT)).resolves.toEqual({ applied: false, error: expect.any(Error) });
        expect(mockSearchReindexMarkRequired).toHaveBeenCalledTimes(1);
        expect(mockWriteRejection).toHaveBeenCalledWith('import_rolled_back');
        expect(console.error).toHaveBeenCalled();
    });

    // The core bug this block guards against: createPreImportBackup() only snapshots data.db, so
    // the previous media/ (moved aside by swapCatalog(), never deleted) must be renamed back on
    // rollback, or the catalog restored via restoreBackup() ends up paired with the wrong media/.
    test('restores the previous media/ on rollback instead of leaving the staged one in place', async () => {
        mockMigrationsRun.mockRejectedValue(new Error('migrate-failed'));

        await apply(CONTEXT);

        expect(fs.renameSync).toHaveBeenCalledWith(PRE_IMPORT_MEDIA_DIR, MEDIA_DIR);
    });

    // Never removes the moved-aside previous media/ on rollback — it must never be removed here,
    // it must always be renamed back into place instead (see the test above).
    test('never removes the moved-aside previous media/ when a rollback happened', async () => {
        mockMigrationsRun.mockRejectedValue(new Error('migrate-failed'));

        await apply(CONTEXT);

        const preImportMediaRmCalls = fs.rmSync.mock.calls.filter(([target]) => target === PRE_IMPORT_MEDIA_DIR);
        expect(preImportMediaRmCalls).toHaveLength(1);
    });

    // If swapCatalog() itself throws partway through (e.g. the media/ rename fails), the failure
    // must roll back exactly like a failure in steps 3-5 — it is now inside apply()'s try/catch.
    test('rolls back when swapCatalog() itself fails partway through the media/ swap', async () => {
        // The rename that would create PRE_IMPORT_MEDIA_DIR is exactly the one that fails, so it
        // never exists — restoreCatalog() must see that and know the original media/ was untouched.
        fs.existsSync.mockImplementation((target) => target !== PRE_IMPORT_MEDIA_DIR);
        fs.renameSync.mockImplementation((source) => {
            if (source === MEDIA_DIR) throw new Error('rename failed');
        });

        const result = await apply(CONTEXT);

        expect(mockRestoreBackup).toHaveBeenCalledWith(PREIMPORT_BACKUP_PATH);
        expect(mockSearchReindexMarkRequired).toHaveBeenCalledTimes(1);
        expect(result.applied).toBe(false);
        expect(result.error).toBeInstanceOf(Error);
        // swapCatalog() never got far enough to move media/ aside, so restoreCatalog() must leave
        // the untouched original media/ alone rather than overwriting it with an empty directory.
        expect(fs.renameSync).not.toHaveBeenCalledWith(PRE_IMPORT_MEDIA_DIR, MEDIA_DIR);
    });

    // restoreBackup() runs before the reindex is marked required, never after — marking one
    // required against a catalog that has not actually been restored yet would be premature.
    test('restores the backup before marking a reindex required during rollback', async () => {
        mockMigrationsRun.mockRejectedValue(new Error('migrate-failed'));
        const callOrder = [];
        mockRestoreBackup.mockImplementation((path) => {
            if (path === PREIMPORT_BACKUP_PATH) callOrder.push('restoreBackup(preimport)');
        });
        mockSearchReindexMarkRequired.mockImplementation(() => {
            callOrder.push('searchReindex.markRequired');
        });

        await apply(CONTEXT);

        expect(callOrder).toEqual(['restoreBackup(preimport)', 'searchReindex.markRequired']);
    });

    // Regression guard for issue #710's core bug: the reindex failing on its one and only attempt
    // (step 5) used to be followed by a synchronous recovery reindex against the same data and the
    // same timeout, which could fail again and propagate out of apply() and, in turn, out of
    // supervisor.start() — the app never started. It must now resolve instead, every time, since a
    // second synchronous attempt no longer exists to fail.
    test('resolves instead of throwing when the reindex fails', async () => {
        mockSearchReindexRun.mockRejectedValue(new Error('reindex failed'));

        await expect(apply(CONTEXT)).resolves.toEqual({ applied: false, error: expect.any(Error) });
        expect(mockRestoreBackup).toHaveBeenCalledWith(PREIMPORT_BACKUP_PATH);
        expect(mockSearchReindexMarkRequired).toHaveBeenCalledTimes(1);
    });
});
