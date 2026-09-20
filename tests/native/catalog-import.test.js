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
jest.mock('../../native/supervisor/php-command', () => ({
    run: (...args) => mockRun(...args),
}));

const mockGetWorkerContext = jest.fn();
jest.mock('../../native/supervisor', () => ({
    getWorkerContext: (...args) => mockGetWorkerContext(...args),
}));

const mockRelaunch = jest.fn();
jest.mock('../../native/lifecycle', () => ({ relaunch: (...args) => mockRelaunch(...args) }));

const { startImport } = require('../../native/catalog-import');

const CONTEXT = { appPort: 8000, qbittorrentPort: 9999, meiliPort: 7700, meiliKey: 'test-key' };

afterEach(() => {
    jest.clearAllMocks();
});

describe('startImport', () => {
    test('returns { ok: false, code: null } without spawning anything when the worker is not up', async () => {
        mockGetWorkerContext.mockReturnValue(null);

        await expect(startImport(null, '/path/to/archive.zip')).resolves.toEqual({ ok: false, code: null });

        expect(mockRun).not.toHaveBeenCalled();
        expect(mockRelaunch).not.toHaveBeenCalled();
    });

    // Issue #671: a successful stage triggers the app's own restart, rather than leaving the
    // user to keep working against the old catalog until they happen to close the window
    // themselves — the window-close handler only hides to tray (native/lifecycle/index.js).
    test('triggers lifecycle.relaunch() when app:catalog:stage exits successfully', async () => {
        mockGetWorkerContext.mockReturnValue(CONTEXT);
        mockRun.mockResolvedValue({ code: 0 });

        const outcome = await startImport(null, '/path/to/archive.zip');

        expect(outcome).toEqual({ ok: true, code: 0 });
        expect(mockRun).toHaveBeenCalledWith(
            'app:catalog:stage',
            ['/path/to/archive.zip'],
            CONTEXT,
            expect.any(Number),
            { rejectOnNonZero: false },
        );
        expect(mockRelaunch).toHaveBeenCalledTimes(1);
    });

    test('does not trigger a relaunch when app:catalog:stage fails', async () => {
        mockGetWorkerContext.mockReturnValue(CONTEXT);
        mockRun.mockResolvedValue({ code: 3 });

        const outcome = await startImport(null, '/path/to/archive.zip');

        expect(outcome).toEqual({ ok: false, code: 3 });
        expect(mockRelaunch).not.toHaveBeenCalled();
    });
});
