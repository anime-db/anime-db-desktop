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

const { generate, parseArgs } = require('../../scripts/generate-php-ini');

describe('parseArgs', () => {
    test('reads --extension-dir, --target-dir and --timezone', () => {
        const options = parseArgs(['--extension-dir', '/x/ext', '--target-dir', '/x/out', '--timezone', 'UTC']);
        expect(options).toEqual({ extensionDir: '/x/ext', targetDir: '/x/out', timezone: 'UTC' });
    });

    test('ignores unknown flags and leaves unset options out', () => {
        expect(parseArgs(['--bogus', 'value'])).toEqual({});
    });
});

describe('generate', () => {
    let targetDir;

    beforeEach(() => {
        targetDir = fs.mkdtempSync(path.join(os.tmpdir(), 'generate-php-ini-test-'));
    });

    afterEach(() => {
        fs.rmSync(targetDir, { recursive: true, force: true });
    });

    test('writes php.ini into the given target directory and returns that directory', () => {
        const returned = generate({ extensionDir: '/fake/ext', targetDir, timezone: 'UTC' });

        expect(returned).toBe(targetDir);
        const ini = fs.readFileSync(path.join(targetDir, 'php.ini'), 'utf8');
        expect(ini).toContain('extension_dir = "/fake/ext"');
        expect(ini).toContain('date.timezone = UTC');
    });

    test('defaults to a freshly created temp directory when no target is given', () => {
        const returned = generate({ extensionDir: '/fake/ext', timezone: 'UTC' });

        expect(fs.existsSync(path.join(returned, 'php.ini'))).toBe(true);
        fs.rmSync(returned, { recursive: true, force: true });
    });
});
