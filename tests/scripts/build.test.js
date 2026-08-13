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

const fs = require('fs');
const os = require('os');
const path = require('path');

const { syncVersionFromTag } = require('../../scripts/build');

function writePackageJson(version) {
    const file = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'build-test-')), 'package.json');
    fs.writeFileSync(file, JSON.stringify({ name: 'anime-db-desktop', version }, null, 4) + '\n', 'utf8');
    return file;
}

describe('syncVersionFromTag', () => {
    test('stamps the version from a "v*.*.*" tag ref', () => {
        const pkgPath = writePackageJson('0.0.1');
        syncVersionFromTag('v1.2.3', pkgPath);
        expect(JSON.parse(fs.readFileSync(pkgPath, 'utf8')).version).toBe('1.2.3');
    });

    test('leaves the version untouched when ref is not a release tag (branch build)', () => {
        const pkgPath = writePackageJson('0.0.1');
        syncVersionFromTag('master', pkgPath);
        expect(JSON.parse(fs.readFileSync(pkgPath, 'utf8')).version).toBe('0.0.1');
    });

    test('leaves the version untouched when ref is undefined (local run)', () => {
        const pkgPath = writePackageJson('0.0.1');
        syncVersionFromTag(undefined, pkgPath);
        expect(JSON.parse(fs.readFileSync(pkgPath, 'utf8')).version).toBe('0.0.1');
    });

    test('is a no-op when the tag already matches the current version', () => {
        const pkgPath = writePackageJson('1.2.3');
        const before = fs.statSync(pkgPath).mtimeMs;
        syncVersionFromTag('v1.2.3', pkgPath);
        expect(fs.statSync(pkgPath).mtimeMs).toBe(before);
    });
});
