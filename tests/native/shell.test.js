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
    ipcMain: { handle: jest.fn() },
    shell:   { openPath: jest.fn(() => Promise.resolve('')) },
}));

const { ipcMain, shell } = require('electron');
const { openStoragePath } = require('../../native/shell');

test('registers the shell:open-path IPC handler on module load', () => {
    expect(ipcMain.handle).toHaveBeenCalledWith('shell:open-path', openStoragePath);
});

test('openStoragePath delegates to shell.openPath with the given path', () => {
    openStoragePath({}, '/anime/aot');

    expect(shell.openPath).toHaveBeenCalledWith('/anime/aot');
});
