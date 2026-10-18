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
const path = require('path');

const root = path.join(__dirname, '..', '..');
const pkg  = JSON.parse(fs.readFileSync(path.join(root, 'package.json'), 'utf8'));
const nsh  = fs.readFileSync(path.join(root, 'build', 'installer.nsh'), 'utf8');

describe('installer userData paths', () => {
    test('every $APPDATA directory in installer.nsh equals package.json name', () => {
        // (?<![A-Za-z]) keeps $LOCALAPPDATA out; the directory ends at a backslash, quote or end of line.
        const dirs = [...nsh.matchAll(/(?<![A-Za-z])\$APPDATA\\([^\\"\r\n]+)(?=[\\"\r\n]|$)/gm)].map((m) => m[1]);

        expect(dirs.length).toBeGreaterThan(0);
        for (const dir of dirs) {
            expect(dir).toBe(pkg.name);
        }
    });

    test('package.json has no top-level productName', () => {
        expect(pkg).not.toHaveProperty('productName');
    });

    test('package.json has no build.extraMetadata.productName', () => {
        expect(((pkg.build || {}).extraMetadata || {})).not.toHaveProperty('productName');
    });
});
