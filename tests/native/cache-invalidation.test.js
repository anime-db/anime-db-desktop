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
    getStatePath:               jest.fn(() => '/fake/userData/state.json'),
    getRuntimeDir:              jest.fn(() => '/fake/userData/var'),
    getMarketSnapshotCachePath: jest.fn(() => '/fake/userData/market-snapshot-cache.json'),
}));

const fs = require('fs');
const { app } = require('electron');
const paths = require('../../native/paths');
const {
    hasBuildChanged,
    invalidateCache,
    invalidateMarketSnapshot,
    commitFingerprint,
    computeBuildFingerprint,
    CacheInvalidationError,
} = require('../../native/supervisor/cache-invalidation');

const VERSIONS_JSON_CONTENT = JSON.stringify({ frankenphp: '1.12.4' });
const MIGRATION_FILES = ['Version20260627000000.php', 'Version20260627000001.php'];
const BUILD_ID_CONTENT = 'deadbeef-42';

beforeEach(() => {
    jest.spyOn(fs, 'readFileSync').mockImplementation((filePath) => {
        if (String(filePath).endsWith('versions.json')) return VERSIONS_JSON_CONTENT;
        if (String(filePath).endsWith('build-id.txt')) return BUILD_ID_CONTENT;
        if (String(filePath) === '/fake/userData/state.json') return JSON.stringify({});
        throw Object.assign(new Error('ENOENT'), { code: 'ENOENT' });
    });
    jest.spyOn(fs, 'readdirSync').mockReturnValue(MIGRATION_FILES);
    jest.spyOn(fs, 'existsSync').mockReturnValue(true);
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

    test('changes when scripts/build-id.txt content changes (e.g. new commit built on the same checked-in version)', () => {
        const before = computeBuildFingerprint();
        fs.readFileSync.mockImplementation((filePath) => {
            if (String(filePath).endsWith('versions.json')) return VERSIONS_JSON_CONTENT;
            if (String(filePath).endsWith('build-id.txt')) return 'other-build-id';
            return '{}';
        });
        expect(computeBuildFingerprint()).not.toBe(before);
    });

    test('falls back to a fixed placeholder when build-id.txt does not exist (unpackaged dev run)', () => {
        fs.existsSync.mockImplementation((filePath) => !String(filePath).endsWith('build-id.txt'));
        expect(() => computeBuildFingerprint()).not.toThrow();
    });

    test('changes when scripts/versions.json content changes', () => {
        const before = computeBuildFingerprint();
        fs.readFileSync.mockImplementation((filePath) => {
            if (String(filePath).endsWith('versions.json')) return JSON.stringify({ frankenphp: '2.0.0' });
            if (String(filePath).endsWith('build-id.txt')) return BUILD_ID_CONTENT;
            return '{}';
        });
        expect(computeBuildFingerprint()).not.toBe(before);
    });

    test('changes when the app/migrations file list changes', () => {
        const before = computeBuildFingerprint();
        fs.readdirSync.mockReturnValue([...MIGRATION_FILES, 'Version20260814000000.php']);
        expect(computeBuildFingerprint()).not.toBe(before);
    });
});

describe('hasBuildChanged', () => {
    test('first run (no state.json yet) reports no change', () => {
        fs.existsSync.mockImplementation((filePath) => !String(filePath).endsWith('state.json'));
        expect(hasBuildChanged()).toBe(false);
    });

    test('a corrupt state.json is treated like a first run — no change reported', () => {
        fs.readFileSync.mockImplementation((filePath) => {
            if (String(filePath).endsWith('versions.json')) return VERSIONS_JSON_CONTENT;
            if (String(filePath).endsWith('build-id.txt')) return BUILD_ID_CONTENT;
            return 'not json';
        });
        expect(hasBuildChanged()).toBe(false);
    });

    test('unchanged build (matching stored fingerprint) reports no change', () => {
        const fingerprint = computeBuildFingerprint();
        fs.readFileSync.mockImplementation((filePath) => {
            if (String(filePath).endsWith('versions.json')) return VERSIONS_JSON_CONTENT;
            if (String(filePath).endsWith('build-id.txt')) return BUILD_ID_CONTENT;
            return JSON.stringify({ buildFingerprint: fingerprint });
        });
        expect(hasBuildChanged()).toBe(false);
    });

    test('changed build (mismatched stored fingerprint) reports a change', () => {
        fs.readFileSync.mockImplementation((filePath) => {
            if (String(filePath).endsWith('versions.json')) return VERSIONS_JSON_CONTENT;
            if (String(filePath).endsWith('build-id.txt')) return BUILD_ID_CONTENT;
            return JSON.stringify({ buildFingerprint: 'stale-fingerprint' });
        });
        expect(hasBuildChanged()).toBe(true);
    });
});

describe('invalidateCache', () => {
    test('deletes APP_RUNTIME_DIR/cache with Windows-friendly retry options', () => {
        invalidateCache();

        expect(fs.rmSync).toHaveBeenCalledWith('/fake/userData/var/cache', {
            recursive: true,
            force: true,
            maxRetries: 3,
            retryDelay: 200,
        });
    });

    test('wraps and propagates errors instead of swallowing them, as a distinguishable CacheInvalidationError', () => {
        fs.rmSync.mockImplementation(() => { throw new Error('EBUSY: resource busy or locked'); });
        expect(() => invalidateCache()).toThrow('EBUSY');
        expect(() => invalidateCache()).toThrow(CacheInvalidationError);
    });
});

describe('invalidateMarketSnapshot', () => {
    test('deletes the market snapshot cache file', () => {
        invalidateMarketSnapshot();

        expect(fs.rmSync).toHaveBeenCalledWith('/fake/userData/market-snapshot-cache.json', { force: true });
    });

    // Unlike invalidateCache() above, a missing/locked snapshot file is not fatal to startup —
    // the render-fallback (MarketController, issue #440) and the next scheduled refresh already
    // cover its absence, so this swallows the error instead of throwing.
    test('does not throw when deletion fails, just logs it', () => {
        fs.rmSync.mockImplementation(() => { throw new Error('EBUSY: resource busy or locked'); });

        expect(() => invalidateMarketSnapshot()).not.toThrow();
    });
});

describe('commitFingerprint', () => {
    test('writes the current fingerprint to state.json at paths.getStatePath()', () => {
        fs.existsSync.mockImplementation((filePath) => !String(filePath).endsWith('state.json'));

        commitFingerprint();

        expect(paths.getStatePath).toHaveBeenCalled();
        expect(fs.writeFileSync).toHaveBeenCalledWith(
            '/fake/userData/state.json',
            expect.stringContaining('buildFingerprint'),
        );
    });

    test('merges into existing state.json content instead of overwriting other fields', () => {
        fs.readFileSync.mockImplementation((filePath) => {
            if (String(filePath).endsWith('versions.json')) return VERSIONS_JSON_CONTENT;
            if (String(filePath).endsWith('build-id.txt')) return BUILD_ID_CONTENT;
            return JSON.stringify({ someOtherField: 'keep-me' });
        });

        commitFingerprint();

        const written = JSON.parse(fs.writeFileSync.mock.calls[0][1]);
        expect(written.someOtherField).toBe('keep-me');
        expect(written.buildFingerprint).toBe(computeBuildFingerprint());
    });

    test('a corrupt existing state.json is treated as empty, not fatal', () => {
        fs.readFileSync.mockImplementation((filePath) => {
            if (String(filePath).endsWith('versions.json')) return VERSIONS_JSON_CONTENT;
            if (String(filePath).endsWith('build-id.txt')) return BUILD_ID_CONTENT;
            return 'not json';
        });

        expect(() => commitFingerprint()).not.toThrow();
        expect(fs.writeFileSync).toHaveBeenCalled();
    });
});
