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

const { LEAK_GUARDED_PATHS, snapshotGuardedPaths, findLeakedPaths } = require('../../scripts/leak-guard');

describe('leak guard', () => {
    test('guards the developer data paths, including the cache.app share dir', () => {
        const rel = LEAK_GUARDED_PATHS.map((p) => path.relative(path.resolve(__dirname, '..', '..'), p));
        expect(rel).toEqual(expect.arrayContaining(['data', path.join('app', 'var', 'config.json'), path.join('app', 'var', 'share')]));
    });

    test('reports a guarded path that appeared and ignores untouched ones', () => {
        const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'leak-guard-'));
        try {
            const leaky = path.join(dir, 'share');
            const quiet = path.join(dir, 'quiet');
            fs.writeFileSync(quiet, 'x');
            const before = snapshotGuardedPaths([leaky, quiet]);
            expect(findLeakedPaths(before)).toEqual([]);

            fs.mkdirSync(leaky);
            expect(findLeakedPaths(before)).toEqual([leaky]);
        } finally {
            fs.rmSync(dir, { recursive: true, force: true });
        }
    });

    test('detects a file written into a nested subdirectory of an already existing guarded dir', () => {
        const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'leak-guard-'));
        try {
            const share = path.join(dir, 'share');
            const pool  = path.join(share, 'prod', 'pools', 'app');
            fs.mkdirSync(pool, { recursive: true });
            const before = snapshotGuardedPaths([share]);
            expect(findLeakedPaths(before)).toEqual([]);

            fs.writeFileSync(path.join(pool, 'item'), 'x');
            expect(findLeakedPaths(before)).toEqual([share]);
        } finally {
            fs.rmSync(dir, { recursive: true, force: true });
        }
    });
});
