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
const path = require('path');

const ROOT = path.resolve(__dirname, '..', '..');
const ACCEPTED_EXTENSIONS = ['.txt', '.rtf', '.html'];

const pkg = JSON.parse(fs.readFileSync(path.join(ROOT, 'package.json'), 'utf8'));

describe('NSIS installer license screen', () => {
    const license = pkg.build.nsis.license;

    test('build.nsis.license is set', () => {
        expect(typeof license).toBe('string');
        expect(license).not.toBe('');
    });

    test('license file exists', () => {
        expect(fs.existsSync(path.join(ROOT, license))).toBe(true);
    });

    test('license extension is accepted by electron-builder', () => {
        expect(ACCEPTED_EXTENSIONS).toContain(path.extname(license).toLowerCase());
    });

    test('license text matches the root LICENSE', () => {
        const shown = fs.readFileSync(path.join(ROOT, license), 'utf8');
        const root = fs.readFileSync(path.join(ROOT, 'LICENSE'), 'utf8');
        expect(shown).toBe(root);
    });
});
