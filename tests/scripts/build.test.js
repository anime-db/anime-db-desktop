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
        syncVersionFromTag('v1.2.3', 'tag', pkgPath);
        expect(JSON.parse(fs.readFileSync(pkgPath, 'utf8')).version).toBe('1.2.3');
    });

    test('stamps a semver pre-release tag ref', () => {
        const pkgPath = writePackageJson('0.0.1');
        syncVersionFromTag('v1.0.0-rc1', 'tag', pkgPath);
        expect(JSON.parse(fs.readFileSync(pkgPath, 'utf8')).version).toBe('1.0.0-rc1');
    });

    test('leaves the version untouched when the run was not triggered by a tag (branch build)', () => {
        const pkgPath = writePackageJson('0.0.1');
        syncVersionFromTag('master', 'branch', pkgPath);
        expect(JSON.parse(fs.readFileSync(pkgPath, 'utf8')).version).toBe('0.0.1');
    });

    test('leaves the version untouched when ref type is undefined (local run / workflow_dispatch)', () => {
        const pkgPath = writePackageJson('0.0.1');
        syncVersionFromTag(undefined, undefined, pkgPath);
        expect(JSON.parse(fs.readFileSync(pkgPath, 'utf8')).version).toBe('0.0.1');
    });

    test('is a no-op when the tag already matches the current version', () => {
        const pkgPath = writePackageJson('1.2.3');
        const before = fs.statSync(pkgPath).mtimeMs;
        syncVersionFromTag('v1.2.3', 'tag', pkgPath);
        expect(fs.statSync(pkgPath).mtimeMs).toBe(before);
    });

    test('throws when a tag build ref does not parse as a release version', () => {
        const pkgPath = writePackageJson('0.0.1');
        expect(() => syncVersionFromTag('v1.2.3.4', 'tag', pkgPath)).toThrow(
            /does not match the expected/
        );
        expect(JSON.parse(fs.readFileSync(pkgPath, 'utf8')).version).toBe('0.0.1');
    });
});
