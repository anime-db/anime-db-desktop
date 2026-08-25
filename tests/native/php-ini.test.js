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

const { renderPhpIni, ensurePhpIni } = require('../../native/php-ini');

const EXTENSION_DIR = '/fake/ext';

describe('renderPhpIni', () => {
    test('substitutes {{EXTENSION_DIR}} and an explicit {{TIMEZONE}}', () => {
        const ini = renderPhpIni({ extensionDir: EXTENSION_DIR, timezone: 'Europe/Moscow' });

        expect(ini).toContain(`extension_dir = "${EXTENSION_DIR}"`);
        expect(ini).toContain('date.timezone = Europe/Moscow');
        expect(ini).not.toContain('{{EXTENSION_DIR}}');
        expect(ini).not.toContain('{{TIMEZONE}}');
    });

    test('falls back to the host timezone when none is given', () => {
        const ini = renderPhpIni({ extensionDir: EXTENSION_DIR });

        const hostTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
        expect(ini).toContain(`date.timezone = ${hostTimezone}`);
    });

    test('keeps the rest of the template intact', () => {
        const ini = renderPhpIni({ extensionDir: EXTENSION_DIR, timezone: 'UTC' });

        expect(ini).toContain('extension=intl');
        expect(ini).toContain('extension=zip');
        expect(ini).toContain('extension=pdo_sqlite');
        expect(ini).toContain('extension=openssl');
        expect(ini).toContain('extension=mbstring');
        expect(ini).toContain('extension=gd');
        expect(ini).toContain('opcache.enable=1');
    });
});

describe('ensurePhpIni', () => {
    let tmpDir;
    let iniPath;

    beforeEach(() => {
        tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'php-ini-test-'));
        iniPath = path.join(tmpDir, 'php.ini');
    });

    afterEach(() => {
        fs.rmSync(tmpDir, { recursive: true, force: true });
    });

    test('creates php.ini from the template when none exists yet', () => {
        ensurePhpIni({ iniPath, iniDir: tmpDir, extensionDir: EXTENSION_DIR, timezone: 'UTC' });

        const ini = fs.readFileSync(iniPath, 'utf8');
        expect(ini).toContain(`extension_dir = "${EXTENSION_DIR}"`);
        expect(ini).toContain('date.timezone = UTC');
    });

    test('creates the target directory if it does not exist yet', () => {
        const nestedDir = path.join(tmpDir, 'nested');
        const nestedIniPath = path.join(nestedDir, 'php.ini');

        ensurePhpIni({ iniPath: nestedIniPath, iniDir: nestedDir, extensionDir: EXTENSION_DIR, timezone: 'UTC' });

        expect(fs.existsSync(nestedIniPath)).toBe(true);
    });

    test('calling it again does not duplicate directives or change an up-to-date file', () => {
        ensurePhpIni({ iniPath, iniDir: tmpDir, extensionDir: EXTENSION_DIR, timezone: 'UTC' });
        const first = fs.readFileSync(iniPath, 'utf8');

        ensurePhpIni({ iniPath, iniDir: tmpDir, extensionDir: EXTENSION_DIR, timezone: 'UTC' });
        const second = fs.readFileSync(iniPath, 'utf8');

        expect(second).toBe(first);
        expect((second.match(/extension_dir/g) || []).length).toBe(1);
        expect((second.match(/extension=intl/g) || []).length).toBe(1);
    });

    test('appends missing directives to a pre-existing file without duplicating what is already there', () => {
        const partial = ['extension_dir = "/fake/ext"', 'extension=intl', ''].join('\n');
        fs.writeFileSync(iniPath, partial, 'utf8');

        ensurePhpIni({ iniPath, iniDir: tmpDir, extensionDir: '/fake/ext' });
        ensurePhpIni({ iniPath, iniDir: tmpDir, extensionDir: '/fake/ext' });

        const updated = fs.readFileSync(iniPath, 'utf8');
        expect((updated.match(/extension_dir/g) || []).length).toBe(1);
        expect((updated.match(/extension=intl/g) || []).length).toBe(1);
        expect(updated).toContain('extension=zip');
        expect(updated).toContain('extension=pdo_sqlite');
        expect(updated).toContain('extension=openssl');
        expect(updated).toContain('extension=mbstring');
        expect(updated).toContain('extension=gd');
        expect(updated).toContain('memory_limit = 256M');
    });

    test('appends memory_limit to a pre-existing file that predates the directive', () => {
        const partial = ['extension_dir = "/fake/ext"', 'extension=intl', ''].join('\n');
        fs.writeFileSync(iniPath, partial, 'utf8');

        ensurePhpIni({ iniPath, iniDir: tmpDir, extensionDir: '/fake/ext' });

        const updated = fs.readFileSync(iniPath, 'utf8');
        expect((updated.match(/memory_limit/g) || []).length).toBe(1);
        expect(updated).toContain('memory_limit = 256M');
    });

    test('leaves a user-edited memory_limit value untouched', () => {
        const partial = ['extension_dir = "/fake/ext"', 'memory_limit = 512M', 'extension=intl', ''].join('\n');
        fs.writeFileSync(iniPath, partial, 'utf8');

        ensurePhpIni({ iniPath, iniDir: tmpDir, extensionDir: '/fake/ext' });

        const updated = fs.readFileSync(iniPath, 'utf8');
        expect((updated.match(/memory_limit/g) || []).length).toBe(1);
        expect(updated).toContain('memory_limit = 512M');
        expect(updated).not.toContain('memory_limit = 256M');
    });
});
