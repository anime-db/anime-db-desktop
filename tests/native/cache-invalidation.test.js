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
    app: { getVersion: jest.fn(() => '1.2.3') },
}));
jest.mock('../../native/paths', () => ({
    getStatePath:  jest.fn(() => '/fake/userData/state.json'),
    getRuntimeDir: jest.fn(() => '/fake/userData/var'),
}));

const fs = require('fs');
const { app } = require('electron');
const paths = require('../../native/paths');
const { invalidateStaleCache, computeBuildFingerprint } = require('../../native/supervisor/cache-invalidation');

const VERSIONS_JSON_CONTENT = JSON.stringify({ frankenphp: '1.12.4' });
const MIGRATION_FILES = ['Version20260627000000.php', 'Version20260627000001.php'];

beforeEach(() => {
    jest.spyOn(fs, 'readFileSync').mockImplementation((filePath) => {
        if (String(filePath).endsWith('versions.json')) return VERSIONS_JSON_CONTENT;
        if (String(filePath) === '/fake/userData/state.json') return JSON.stringify({});
        throw Object.assign(new Error('ENOENT'), { code: 'ENOENT' });
    });
    jest.spyOn(fs, 'readdirSync').mockReturnValue(MIGRATION_FILES);
    jest.spyOn(fs, 'existsSync').mockReturnValue(false);
    jest.spyOn(fs, 'mkdirSync').mockImplementation(() => {});
    jest.spyOn(fs, 'writeFileSync').mockImplementation(() => {});
    jest.spyOn(fs, 'rmSync').mockImplementation(() => {});
});

afterEach(() => {
    jest.restoreAllMocks();
});

describe('computeBuildFingerprint', () => {
    test('returns the same fingerprint for identical inputs', () => {
        expect(computeBuildFingerprint()).toBe(computeBuildFingerprint());
    });

    test('changes when app.getVersion() changes', () => {
        const before = computeBuildFingerprint();
        app.getVersion.mockReturnValue('9.9.9');
        expect(computeBuildFingerprint()).not.toBe(before);
    });

    test('changes when scripts/versions.json content changes', () => {
        const before = computeBuildFingerprint();
        fs.readFileSync.mockImplementation((filePath) => (
            String(filePath).endsWith('versions.json') ? JSON.stringify({ frankenphp: '2.0.0' }) : '{}'
        ));
        expect(computeBuildFingerprint()).not.toBe(before);
    });

    test('changes when the app/migrations file list changes', () => {
        const before = computeBuildFingerprint();
        fs.readdirSync.mockReturnValue([...MIGRATION_FILES, 'Version20260814000000.php']);
        expect(computeBuildFingerprint()).not.toBe(before);
    });
});

describe('invalidateStaleCache', () => {
    test('first run (no state.json yet) writes a marker without deleting the cache dir', () => {
        fs.existsSync.mockReturnValue(false);

        invalidateStaleCache();

        expect(fs.rmSync).not.toHaveBeenCalled();
        expect(fs.writeFileSync).toHaveBeenCalledWith(
            '/fake/userData/state.json',
            expect.stringContaining('buildFingerprint'),
        );
    });

    test('unchanged build (matching stored fingerprint) neither deletes the cache dir nor rewrites the marker', () => {
        const fingerprint = computeBuildFingerprint();
        fs.existsSync.mockReturnValue(true);
        fs.readFileSync.mockImplementation((filePath) => {
            if (String(filePath).endsWith('versions.json')) return VERSIONS_JSON_CONTENT;
            return JSON.stringify({ buildFingerprint: fingerprint });
        });

        invalidateStaleCache();

        expect(fs.rmSync).not.toHaveBeenCalled();
        expect(fs.writeFileSync).not.toHaveBeenCalled();
    });

    test('changed build (mismatched stored fingerprint) deletes the cache dir and writes a new marker', () => {
        fs.existsSync.mockReturnValue(true);
        fs.readFileSync.mockImplementation((filePath) => {
            if (String(filePath).endsWith('versions.json')) return VERSIONS_JSON_CONTENT;
            return JSON.stringify({ buildFingerprint: 'stale-fingerprint' });
        });

        invalidateStaleCache();

        expect(fs.rmSync).toHaveBeenCalledWith('/fake/userData/var/cache', {
            recursive: true,
            force: true,
            maxRetries: 3,
            retryDelay: 200,
        });
        expect(fs.writeFileSync).toHaveBeenCalledWith(
            '/fake/userData/state.json',
            expect.stringContaining('buildFingerprint'),
        );
    });

    test('a corrupt state.json is treated like a first run — no deletion, marker is (re)written', () => {
        fs.existsSync.mockReturnValue(true);
        fs.readFileSync.mockImplementation((filePath) => (
            String(filePath).endsWith('versions.json') ? VERSIONS_JSON_CONTENT : 'not json'
        ));

        invalidateStaleCache();

        expect(fs.rmSync).not.toHaveBeenCalled();
        expect(fs.writeFileSync).toHaveBeenCalled();
    });

    test('reads and writes the state marker at paths.getStatePath()', () => {
        fs.existsSync.mockReturnValue(false);

        invalidateStaleCache();

        expect(paths.getStatePath).toHaveBeenCalled();
    });
});
