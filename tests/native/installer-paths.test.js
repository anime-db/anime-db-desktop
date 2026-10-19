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

// Electron names the app — and so the userData directory — after `productName || name` of the PACKAGED
// package.json. electron-builder deep-merges build.extraMetadata into it, so extraMetadata wins.
// build.productName is NOT carried over: it only names the exe, shortcuts and install directory.
const effectiveName = (top, extraMetadata) => {
    const merged = { ...top, ...(extraMetadata || {}) };
    return merged.productName || merged.name;
};
const appName = effectiveName(pkg, (pkg.build || {}).extraMetadata);

describe('installer userData paths', () => {
    test('the app name, and so userData, is AnimeDB', () => {
        expect(appName).toBe('AnimeDB');
    });

    test('top-level productName matches build.productName', () => {
        expect(pkg.productName).toBe((pkg.build || {}).productName);
    });

    test('every $APPDATA directory in installer.nsh equals the effective app name', () => {
        // (?<![A-Za-z]) keeps $LOCALAPPDATA out; the directory ends at a backslash, quote or end of line.
        const dirs = [...nsh.matchAll(/(?<![A-Za-z])\$APPDATA\\([^\\"\r\n]+)(?=[\\"\r\n]|$)/gm)].map((m) => m[1]);

        expect(dirs.length).toBeGreaterThan(0);
        for (const dir of dirs) {
            expect(dir).toBe(appName);
        }
    });

    test('build.extraMetadata overrides the top-level name and productName', () => {
        expect(effectiveName({ name: 'a', productName: 'B' }, undefined)).toBe('B');
        expect(effectiveName({ name: 'a' }, undefined)).toBe('a');
        expect(effectiveName({ name: 'a', productName: 'B' }, { productName: 'C' })).toBe('C');
        expect(effectiveName({ name: 'a' }, { name: 'D' })).toBe('D');
    });
});
