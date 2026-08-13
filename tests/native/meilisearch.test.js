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
    getMeilisearchDataDir: jest.fn(() => '/fake/meili'),
    getMeilisearchKeyPath: jest.fn(() => '/fake/meili-key.txt'),
    getRuntimeDir:         jest.fn(() => '/fake/var'),
    getUserDataDir:        jest.fn(() => '/fake/userData'),
    getPhpIniPath:         jest.fn(() => '/fake/userData/php.ini'),
    getPhpIniDir:          jest.fn(() => '/fake/userData'),
    getDbPath:             jest.fn(() => '/fake/userData/data.db'),
    getAppRootDir:         jest.fn(() => '/fake/app'),
}));
jest.mock('../../native/supervisor/logrotate', () => ({
    pruneOldLogs:  jest.fn(),
    openLogStream: jest.fn(),
}));
jest.mock('../../native/supervisor/port', () => ({
    findFreePort: jest.fn(),
}));
jest.mock('../../native/supervisor/healthcheck', () => ({
    waitForHealth: jest.fn(),
}));

const fs = require('fs');
const { checkVersionAndWipe } = require('../../native/supervisor/meilisearch');

describe('checkVersionAndWipe', () => {
    let existsSyncSpy;
    let readFileSyncSpy;
    let rmSyncSpy;

    beforeEach(() => {
        existsSyncSpy  = jest.spyOn(fs, 'existsSync');
        readFileSyncSpy = jest.spyOn(fs, 'readFileSync');
        rmSyncSpy      = jest.spyOn(fs, 'rmSync').mockImplementation(() => {});
    });

    afterEach(() => {
        jest.restoreAllMocks();
    });

    test('does nothing when VERSION file is absent', () => {
        existsSyncSpy.mockReturnValue(false);
        checkVersionAndWipe();
        expect(rmSyncSpy).not.toHaveBeenCalled();
    });

    test('does not wipe when installed version matches expected', () => {
        existsSyncSpy.mockReturnValue(true);
        readFileSyncSpy.mockImplementation((filePath) => {
            if (String(filePath).endsWith('versions.json')) {
                return JSON.stringify({ meilisearch: '1.13.0' });
            }
            return '1.13.0\n';
        });
        checkVersionAndWipe();
        expect(rmSyncSpy).not.toHaveBeenCalled();
    });

    test('wipes data.ms/ when version has changed', () => {
        existsSyncSpy.mockReturnValue(true);
        readFileSyncSpy.mockImplementation((filePath) => {
            if (String(filePath).endsWith('versions.json')) {
                return JSON.stringify({ meilisearch: '1.14.0' });
            }
            return '1.13.0';
        });
        checkVersionAndWipe();
        expect(rmSyncSpy).toHaveBeenCalledWith(
            expect.stringContaining('data.ms'),
            { recursive: true, force: true }
        );
    });

    test('skips rmSync when data.ms/ does not exist', () => {
        existsSyncSpy.mockImplementation((filePath) => !String(filePath).endsWith('data.ms'));
        readFileSyncSpy.mockImplementation((filePath) => {
            if (String(filePath).endsWith('versions.json')) {
                return JSON.stringify({ meilisearch: '1.14.0' });
            }
            return '1.13.0';
        });
        checkVersionAndWipe();
        expect(rmSyncSpy).not.toHaveBeenCalled();
    });
});
