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

const fs   = require('fs');
const os   = require('os');
const path = require('path');
const { EventEmitter } = require('events');

jest.mock('electron', () => ({
    ipcMain: { handle: jest.fn() },
    shell:   { openPath: jest.fn(() => Promise.resolve('')) },
}));

jest.mock('http', () => ({ get: jest.fn() }));

const http = require('http');
const { ipcMain, shell } = require('electron');
const { openStoragePath, configure } = require('../../native/shell');

/**
 * Makes the mocked http.get respond as if the backend's GET /storage/paths returned
 * the given list of storage paths.
 *
 * @param {string[]} paths
 */
function mockStoragePaths(paths) {
    http.get.mockImplementation((_url, callback) => {
        const res = new EventEmitter();
        res.statusCode = 200;
        res.setEncoding = jest.fn();
        callback(res);
        process.nextTick(() => {
            res.emit('data', JSON.stringify({ paths }));
            res.emit('end');
        });
        return { on: jest.fn() };
    });
}

let tmpRoot;
let storageDir;

beforeEach(() => {
    http.get.mockClear();
    shell.openPath.mockClear();
    configure(12345);
    jest.spyOn(console, 'error').mockImplementation(() => {});

    tmpRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'shell-test-'));
    storageDir = path.join(tmpRoot, 'storage');
    fs.mkdirSync(storageDir);
});

afterEach(() => {
    console.error.mockRestore();
    fs.rmSync(tmpRoot, { recursive: true, force: true });
});

test('registers the shell:open-path IPC handler on module load', () => {
    expect(ipcMain.handle).toHaveBeenCalledWith('shell:open-path', openStoragePath);
});

test('rejects a path outside the configured storages', async () => {
    const outside = path.join(tmpRoot, 'outside');
    fs.mkdirSync(outside);
    mockStoragePaths([storageDir]);

    await expect(openStoragePath({}, outside)).rejects.toThrow();

    expect(shell.openPath).not.toHaveBeenCalled();
    expect(console.error).toHaveBeenCalledWith(expect.stringContaining(outside));
});

test('rejects a path inside a storage that is not a directory', async () => {
    const filePath = path.join(storageDir, 'file.txt');
    fs.writeFileSync(filePath, 'content');
    mockStoragePaths([storageDir]);

    await expect(openStoragePath({}, filePath)).rejects.toThrow();

    expect(shell.openPath).not.toHaveBeenCalled();
    expect(console.error).toHaveBeenCalledWith(expect.stringContaining(filePath));
});

test('rejects a path with ".." that escapes the storage after canonicalization', async () => {
    const outside = path.join(tmpRoot, 'outside');
    fs.mkdirSync(outside);
    const escapingPath = `${storageDir}${path.sep}..${path.sep}outside`;
    mockStoragePaths([storageDir]);

    await expect(openStoragePath({}, escapingPath)).rejects.toThrow();

    expect(shell.openPath).not.toHaveBeenCalled();
    expect(console.error).toHaveBeenCalledWith(expect.stringContaining(escapingPath));
});

test('opens a directory equal to a configured storage', async () => {
    mockStoragePaths([storageDir]);

    await openStoragePath({}, storageDir);

    expect(shell.openPath).toHaveBeenCalledWith(storageDir);
});

test('opens a directory nested inside a configured storage', async () => {
    const nested = path.join(storageDir, 'anime', 'aot');
    fs.mkdirSync(nested, { recursive: true });
    mockStoragePaths([storageDir]);

    await openStoragePath({}, nested);

    expect(shell.openPath).toHaveBeenCalledWith(nested);
});
