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
const { findLicenseFile, collectPackages, render, NOT_FOUND } = require('../../scripts/generate-composer-licenses');

describe('findLicenseFile', () => {
    let dir;

    beforeEach(() => {
        dir = fs.mkdtempSync(path.join(os.tmpdir(), 'anime-db-pkg-'));
    });

    afterEach(() => {
        fs.rmSync(dir, { recursive: true, force: true });
    });

    test.each(['LICENSE', 'license.md', 'LICENSE.txt', 'COPYING', 'LICENSE-MIT'])('finds %s', (name) => {
        fs.writeFileSync(path.join(dir, name), 'text');

        expect(findLicenseFile(dir)).toBe(name);
    });

    test('returns null when the directory has no license file', () => {
        fs.writeFileSync(path.join(dir, 'README.md'), 'text');

        expect(findLicenseFile(dir)).toBeNull();
    });

    test('returns null for a missing directory', () => {
        expect(findLicenseFile(path.join(dir, 'nope'))).toBeNull();
    });
});

describe('render', () => {
    test('names a package without a license file explicitly', () => {
        const output = render([
            { name: 'a/with', version: '1.0.0', license: 'MIT', file: 'LICENSE' },
            { name: 'b/without', version: '2.0.0', license: 'MIT', file: null },
        ]);

        expect(output).toContain('resources/app/app/vendor/a/with/LICENSE');
        expect(NOT_FOUND).toBe('NOT FOUND');
        expect(output).toMatch(new RegExp(`b/without\\s+\\| 2.0.0\\s+\\| MIT\\s+\\| ${NOT_FOUND}`));
    });

    test('aligns every column and the separator row', () => {
        const output = render([
            { name: 'vendor/long-name', version: 'v1.10.0', license: 'MIT, BSD-3-Clause', file: 'LICENSE' },
            { name: 'a/b', version: '1', license: 'MIT', file: null },
        ]);
        const table = output.split('\n').filter((row) => row.startsWith('|'));

        expect(table).toEqual([
            '| Package          | Version | License           | License text                                      |',
            '|------------------|---------|-------------------|---------------------------------------------------|',
            '| vendor/long-name | v1.10.0 | MIT, BSD-3-Clause | resources/app/app/vendor/vendor/long-name/LICENSE |',
            '| a/b              | 1       | MIT               | NOT FOUND                                         |',
        ]);
    });
});

describe('collectPackages', () => {
    test('reads and sorts packages from composer.lock', () => {
        const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'anime-db-lock-'));
        try {
            fs.mkdirSync(path.join(dir, 'vendor', 'a', 'b'), { recursive: true });
            fs.writeFileSync(path.join(dir, 'vendor', 'a', 'b', 'LICENSE'), 'text');
            fs.writeFileSync(path.join(dir, 'composer.lock'), JSON.stringify({
                packages: [
                    { name: 'z/z', version: 'dev-master', license: ['MIT'] },
                    { name: 'a/b', version: 'v1.0.0' },
                ],
            }));

            expect(collectPackages(path.join(dir, 'composer.lock'), path.join(dir, 'vendor'))).toEqual([
                { name: 'a/b', version: 'v1.0.0', license: 'none', file: 'LICENSE' },
                { name: 'z/z', version: 'dev-master', license: 'MIT', file: null },
            ]);
        } finally {
            fs.rmSync(dir, { recursive: true, force: true });
        }
    });
});
