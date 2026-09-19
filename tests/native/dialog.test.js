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
    dialog:  { showOpenDialog: jest.fn() },
}));

const { ipcMain, dialog } = require('electron');
const { pickFolder, pickFile } = require('../../native/dialog');

test('registers the dialog:pick-folder IPC handler on module load', () => {
    expect(ipcMain.handle).toHaveBeenCalledWith('dialog:pick-folder', pickFolder);
});

test('pickFolder returns the selected path', async () => {
    dialog.showOpenDialog.mockResolvedValueOnce({ canceled: false, filePaths: ['/anime/aot'] });

    await expect(pickFolder()).resolves.toBe('/anime/aot');
});

test('pickFolder returns null when the dialog is canceled', async () => {
    dialog.showOpenDialog.mockResolvedValueOnce({ canceled: true, filePaths: [] });

    await expect(pickFolder()).resolves.toBeNull();
});

test('registers the dialog:pick-file IPC handler on module load', () => {
    expect(ipcMain.handle).toHaveBeenCalledWith('dialog:pick-file', pickFile);
});

test('pickFile returns the selected path', async () => {
    dialog.showOpenDialog.mockResolvedValueOnce({ canceled: false, filePaths: ['/anime/export.zip'] });

    await expect(pickFile(null, [{ name: 'Archive', extensions: ['zip'] }])).resolves.toBe('/anime/export.zip');
});

test('pickFile returns null when the dialog is canceled', async () => {
    dialog.showOpenDialog.mockResolvedValueOnce({ canceled: true, filePaths: [] });

    await expect(pickFile(null, [{ name: 'Archive', extensions: ['zip'] }])).resolves.toBeNull();
});

test('pickFile passes the caller-supplied extension filter to the native dialog', async () => {
    dialog.showOpenDialog.mockResolvedValueOnce({ canceled: true, filePaths: [] });
    const filters = [{ name: 'Archive', extensions: ['zip'] }];

    await pickFile(null, filters);

    expect(dialog.showOpenDialog).toHaveBeenCalledWith({ properties: ['openFile'], filters });
});

test('pickFile defaults to no extension filter when the caller omits one', async () => {
    dialog.showOpenDialog.mockResolvedValueOnce({ canceled: true, filePaths: [] });

    await pickFile();

    expect(dialog.showOpenDialog).toHaveBeenCalledWith({ properties: ['openFile'], filters: [] });
});
