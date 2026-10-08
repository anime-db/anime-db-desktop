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

const mockRun = jest.fn();
const mockKillOrphan = jest.fn(() => Promise.resolve());
jest.mock('../../native/supervisor/php-command', () => ({
    run: (...args) => mockRun(...args),
    killOrphan: (...args) => mockKillOrphan(...args),
}));

const mockGetWorkerContext = jest.fn();
jest.mock('../../native/supervisor', () => ({
    getWorkerContext: (...args) => mockGetWorkerContext(...args),
}));

const { ipcMain } = require('electron');
const { startImport, cancelImport } = require('../../native/import-v1');

const CONTEXT = { appPort: 8000, qbittorrentPort: 9999, meiliPort: 7700, meiliKey: 'test-key' };

afterEach(() => {
    jest.clearAllMocks();
});

test('registers its two IPC channels', () => {
    expect(ipcMain.handle).toHaveBeenCalledWith('import-v1:start', startImport);
    expect(ipcMain.handle).toHaveBeenCalledWith('import-v1:cancel', cancelImport);
});

describe('startImport', () => {
    test('returns { ok: false, code: null } without spawning anything when the worker is not up', async () => {
        mockGetWorkerContext.mockReturnValue(null);

        await expect(startImport(null, '/v1')).resolves.toEqual({ ok: false, code: null });

        expect(mockRun).not.toHaveBeenCalled();
    });

    test('spawns app:catalog:import-v1 with the chosen directory', async () => {
        mockGetWorkerContext.mockReturnValue(CONTEXT);
        mockRun.mockResolvedValue({ code: 0 });

        await expect(startImport(null, '/v1')).resolves.toEqual({ ok: true, code: 0 });

        expect(mockRun).toHaveBeenCalledWith('app:catalog:import-v1', ['/v1'], CONTEXT, expect.any(Number), { rejectOnNonZero: false });
    });

    test('reports the exit code of a refused import', async () => {
        mockGetWorkerContext.mockReturnValue(CONTEXT);
        mockRun.mockResolvedValue({ code: 2 });

        await expect(startImport(null, '/v1')).resolves.toEqual({ ok: false, code: 2 });
    });
});

test('cancelImport kills the process tracked under the command name', async () => {
    await cancelImport();

    expect(mockKillOrphan).toHaveBeenCalledWith('app:catalog:import-v1');
});
