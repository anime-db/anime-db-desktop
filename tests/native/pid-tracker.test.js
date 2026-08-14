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
    getRuntimeDir: jest.fn(() => '/fake/userData/var'),
}));
jest.mock('child_process', () => ({ execFile: jest.fn() }));

const fs = require('fs');
const { execFile } = require('child_process');
const { writePid, clearPid, killOrphan, pidFilePath } = require('../../native/supervisor/pid-tracker');

const originalPlatform = process.platform;

beforeEach(() => {
    execFile.mockClear();
});

afterEach(() => {
    jest.restoreAllMocks();
    Object.defineProperty(process, 'platform', { value: originalPlatform });
});

describe('pidFilePath', () => {
    test('nests pid files under <runtimeDir>/pids/<name>.pid', () => {
        expect(pidFilePath('frankenphp')).toBe('/fake/userData/var/pids/frankenphp.pid');
    });
});

describe('writePid', () => {
    test('creates the pids directory and writes the pid as a string', () => {
        const mkdirSyncSpy = jest.spyOn(fs, 'mkdirSync').mockImplementation(() => {});
        const writeFileSyncSpy = jest.spyOn(fs, 'writeFileSync').mockImplementation(() => {});

        writePid('meilisearch', 4242);

        expect(mkdirSyncSpy).toHaveBeenCalledWith('/fake/userData/var/pids', { recursive: true });
        expect(writeFileSyncSpy).toHaveBeenCalledWith('/fake/userData/var/pids/meilisearch.pid', '4242', 'utf8');
    });
});

describe('clearPid', () => {
    test('removes the pid file when it exists', () => {
        jest.spyOn(fs, 'existsSync').mockReturnValue(true);
        const rmSyncSpy = jest.spyOn(fs, 'rmSync').mockImplementation(() => {});

        clearPid('qbittorrent');

        expect(rmSyncSpy).toHaveBeenCalledWith('/fake/userData/var/pids/qbittorrent.pid');
    });

    test('does nothing when the pid file is absent', () => {
        jest.spyOn(fs, 'existsSync').mockReturnValue(false);
        const rmSyncSpy = jest.spyOn(fs, 'rmSync').mockImplementation(() => {});

        clearPid('qbittorrent');

        expect(rmSyncSpy).not.toHaveBeenCalled();
    });
});

describe('killOrphan', () => {
    test('does nothing when no pid file was left behind', async () => {
        jest.spyOn(fs, 'existsSync').mockReturnValue(false);

        await killOrphan('frankenphp', 'C:/app/bin/frankenphp/frankenphp.exe');

        expect(execFile).not.toHaveBeenCalled();
    });

    test('clears the pid file even when nothing is running under that pid anymore', async () => {
        Object.defineProperty(process, 'platform', { value: 'win32' });
        jest.spyOn(fs, 'existsSync').mockReturnValue(true);
        jest.spyOn(fs, 'readFileSync').mockReturnValue('4242');
        const rmSyncSpy = jest.spyOn(fs, 'rmSync').mockImplementation(() => {});
        execFile.mockImplementation((cmd, args, options, cb) => cb(null, ''));

        await killOrphan('frankenphp', 'C:/app/bin/frankenphp/frankenphp.exe');

        expect(rmSyncSpy).toHaveBeenCalledWith('/fake/userData/var/pids/frankenphp.pid');
        expect(execFile).toHaveBeenCalledWith(
            'tasklist',
            ['/FI', 'PID eq 4242', '/FO', 'CSV', '/NH'],
            { windowsHide: true },
            expect.any(Function),
        );
    });

    test('force-kills the pid when tasklist confirms it still belongs to the expected binary', async () => {
        Object.defineProperty(process, 'platform', { value: 'win32' });
        jest.spyOn(fs, 'existsSync').mockReturnValue(true);
        jest.spyOn(fs, 'readFileSync').mockReturnValue('4242');
        jest.spyOn(fs, 'rmSync').mockImplementation(() => {});
        execFile.mockImplementation((cmd, args, options, cb) => {
            if (cmd === 'tasklist') {
                cb(null, '"frankenphp.exe","4242","Console","1","12,345 K"');
            } else {
                cb(null, '');
            }
        });

        await killOrphan('frankenphp', 'C:/app/bin/frankenphp/frankenphp.exe');

        expect(execFile).toHaveBeenCalledWith(
            'taskkill',
            ['/PID', '4242', '/F'],
            { windowsHide: true },
            expect.any(Function),
        );
    });

    test('does not kill when the pid now belongs to an unrelated process (recycled pid)', async () => {
        Object.defineProperty(process, 'platform', { value: 'win32' });
        jest.spyOn(fs, 'existsSync').mockReturnValue(true);
        jest.spyOn(fs, 'readFileSync').mockReturnValue('4242');
        jest.spyOn(fs, 'rmSync').mockImplementation(() => {});
        execFile.mockImplementation((cmd, args, options, cb) => {
            if (cmd === 'tasklist') {
                cb(null, '"notepad.exe","4242","Console","1","5,000 K"');
            } else {
                cb(null, '');
            }
        });

        await killOrphan('frankenphp', 'C:/app/bin/frankenphp/frankenphp.exe');

        expect(execFile).not.toHaveBeenCalledWith(
            'taskkill',
            expect.anything(),
            expect.anything(),
            expect.anything(),
        );
    });

    test('does nothing on non-Windows platforms even with a pid file present', async () => {
        Object.defineProperty(process, 'platform', { value: 'linux' });
        jest.spyOn(fs, 'existsSync').mockReturnValue(true);
        jest.spyOn(fs, 'readFileSync').mockReturnValue('4242');
        jest.spyOn(fs, 'rmSync').mockImplementation(() => {});

        await killOrphan('frankenphp', '/app/bin/frankenphp/frankenphp.exe');

        expect(execFile).not.toHaveBeenCalled();
    });

    test('does nothing when the pid file content is not a valid pid', async () => {
        Object.defineProperty(process, 'platform', { value: 'win32' });
        jest.spyOn(fs, 'existsSync').mockReturnValue(true);
        jest.spyOn(fs, 'readFileSync').mockReturnValue('not-a-pid');
        jest.spyOn(fs, 'rmSync').mockImplementation(() => {});

        await killOrphan('frankenphp', 'C:/app/bin/frankenphp/frankenphp.exe');

        expect(execFile).not.toHaveBeenCalled();
    });
});
