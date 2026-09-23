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

jest.mock('electron', () => ({ ipcMain: { handle: jest.fn() } }));

const fs = require('fs');
jest.mock('fs', () => ({
    existsSync: jest.fn(),
    rmSync: jest.fn(),
}));

jest.mock('../../native/paths', () => ({
    getBackupsDir: jest.fn(() => '/fake/userData/backups'),
    getImportAppliedPath: jest.fn(() => '/fake/userData/import-applied.json'),
}));

const mockRestoreBackup = jest.fn();
jest.mock('../../native/supervisor/migrations', () => ({
    restoreBackup: (...args) => mockRestoreBackup(...args),
}));

const mockStop = jest.fn();
jest.mock('../../native/supervisor', () => ({
    stop: (...args) => mockStop(...args),
}));

const mockMarkRequired = jest.fn();
jest.mock('../../native/supervisor/search-reindex', () => ({
    markRequired: (...args) => mockMarkRequired(...args),
}));

const mockRelaunch = jest.fn();
jest.mock('../../native/lifecycle', () => ({ relaunch: (...args) => mockRelaunch(...args) }));

const { startRestore } = require('../../native/backup-restore');

afterEach(() => {
    jest.clearAllMocks();
});

describe('startRestore', () => {
    test('returns { ok: false } without stopping or restoring when the backup file is missing', async () => {
        fs.existsSync.mockReturnValue(false);

        await expect(startRestore(null, 'data-1.2.3-20260101-000000.db')).resolves.toEqual({ ok: false });

        expect(mockStop).not.toHaveBeenCalled();
        expect(mockRestoreBackup).not.toHaveBeenCalled();
        expect(mockRelaunch).not.toHaveBeenCalled();
    });

    // Issue #681: the swap must only happen once FrankenPHP/messenger-consumer (and their open
    // Doctrine connection to data.db) are torn down — never while the worker is still live.
    test('stops the supervisor, restores the backup, marks a reindex, then relaunches, in that order', async () => {
        fs.existsSync.mockReturnValue(true);
        const order = [];
        mockStop.mockImplementation(async () => { order.push('stop'); });
        mockRestoreBackup.mockImplementation(() => { order.push('restore'); });
        mockMarkRequired.mockImplementation(() => { order.push('mark-reindex'); });
        mockRelaunch.mockImplementation(() => { order.push('relaunch'); });

        const outcome = await startRestore(null, 'data-preimport-20260101-000000.db');

        expect(outcome).toEqual({ ok: true });
        expect(order).toEqual(['stop', 'restore', 'mark-reindex', 'relaunch']);
        expect(mockRestoreBackup).toHaveBeenCalledWith('/fake/userData/backups/data-preimport-20260101-000000.db');
    });

    // Issue #681 review: index.js#start() only reindexes automatically on wiped||migrationsApplied
    // — both stay false when the restored snapshot has the same schema as the current one, so
    // without this marker the search index would silently stay stale against the restored catalog.
    test('marks a forced reindex for the next start even when the file check passed but nothing else did', async () => {
        fs.existsSync.mockReturnValue(true);

        await startRestore(null, 'data-preimport-20260101-000000.db');

        expect(mockMarkRequired).toHaveBeenCalledTimes(1);
    });

    // A compromised or buggy renderer only ever gets file names back from the snapshot list, but
    // this handler must not trust that — path.basename() strips any directory component before
    // resolving against getBackupsDir(), so a name like "../../data.db" can't reach outside it.
    test('resolves the name against getBackupsDir() via basename, ignoring any path component', async () => {
        fs.existsSync.mockReturnValue(true);

        await startRestore(null, '../../secrets/data.db');

        expect(fs.existsSync).toHaveBeenCalledWith('/fake/userData/backups/data.db');
        expect(mockRestoreBackup).toHaveBeenCalledWith('/fake/userData/backups/data.db');
    });

    // Reviewer feedback (issue #681): supervisor.stop() has already torn down FrankenPHP and
    // messenger-consumer by the time restoreBackup() runs, and neither restarts on its own — so a
    // thrown restoreBackup() (out of disk space, locked file, ...) must still relaunch the app
    // instead of leaving it with an open window and a dead backend.
    test('still relaunches, without marking a reindex, when restoreBackup() throws', async () => {
        fs.existsSync.mockReturnValue(true);
        mockRestoreBackup.mockImplementation(() => { throw new Error('ENOSPC: no space left on device'); });

        const outcome = await startRestore(null, 'data-preimport-20260101-000000.db');

        expect(outcome).toEqual({ ok: false });
        expect(mockMarkRequired).not.toHaveBeenCalled();
        expect(mockRelaunch).toHaveBeenCalledTimes(1);
    });

    // Issue #726: a successful restore replaces the catalog with an arbitrary, potentially
    // plugin-free snapshot — the imported-plugins list App\Service\Import\ImportedPluginsService
    // reads on /settings/backup must not keep describing the catalog this restore just replaced.
    test('removes import-applied.json on a successful restore', async () => {
        fs.existsSync.mockReturnValue(true);
        mockRestoreBackup.mockImplementation(() => {});

        await startRestore(null, 'data-preimport-20260101-000000.db');

        expect(fs.rmSync).toHaveBeenCalledWith('/fake/userData/import-applied.json', { force: true });
    });

    // A failed restoreBackup() never actually swaps data.db (see its own doc comment) — the
    // catalog import-applied.json describes is still the one that is about to start, so it must
    // survive a failed restore attempt.
    test('does not remove import-applied.json when restoreBackup() throws', async () => {
        fs.existsSync.mockReturnValue(true);
        mockRestoreBackup.mockImplementation(() => { throw new Error('ENOSPC: no space left on device'); });

        await startRestore(null, 'data-preimport-20260101-000000.db');

        expect(fs.rmSync).not.toHaveBeenCalled();
    });
});
