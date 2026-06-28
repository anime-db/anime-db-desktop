/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

'use strict';

jest.mock('../../native/paths', () => ({
    getUserDataDir: jest.fn(),
}));

const os    = require('os');
const path  = require('path');
const fs    = require('fs');
const paths = require('../../native/paths');
const { getOrCreateAppSecret } = require('../../native/config');

let tmpDir;

beforeEach(() => {
    tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'animedb-config-test-'));
    paths.getUserDataDir.mockReturnValue(tmpDir);
});

afterEach(() => {
    fs.rmSync(tmpDir, { recursive: true, force: true });
});

describe('getOrCreateAppSecret', () => {
    test('returns a 64-character hex string', () => {
        const secret = getOrCreateAppSecret();
        expect(secret).toMatch(/^[0-9a-f]{64}$/);
    });

    test('persists the secret to config.json', () => {
        const secret = getOrCreateAppSecret();

        const configPath = path.join(tmpDir, 'config.json');
        expect(fs.existsSync(configPath)).toBe(true);

        const stored = JSON.parse(fs.readFileSync(configPath, 'utf8'));
        expect(stored.appSecret).toBe(secret);
    });

    test('returns the same secret on repeated calls', () => {
        const first  = getOrCreateAppSecret();
        const second = getOrCreateAppSecret();
        expect(second).toBe(first);
    });

    test('reads the existing secret without overwriting it', () => {
        const existingSecret = 'b'.repeat(64);
        const configPath = path.join(tmpDir, 'config.json');
        fs.writeFileSync(configPath, JSON.stringify({ appSecret: existingSecret }), 'utf8');

        expect(getOrCreateAppSecret()).toBe(existingSecret);
    });

    test('preserves other fields in config.json', () => {
        const configPath = path.join(tmpDir, 'config.json');
        fs.writeFileSync(configPath, JSON.stringify({ someOtherField: 'value' }), 'utf8');

        getOrCreateAppSecret();

        const stored = JSON.parse(fs.readFileSync(configPath, 'utf8'));
        expect(stored.someOtherField).toBe('value');
        expect(stored.appSecret).toMatch(/^[0-9a-f]{64}$/);
    });

    test('creates the userData directory if it does not exist', () => {
        const nested = path.join(tmpDir, 'deep', 'nested');
        paths.getUserDataDir.mockReturnValue(nested);

        expect(() => getOrCreateAppSecret()).not.toThrow();
        expect(fs.existsSync(path.join(nested, 'config.json'))).toBe(true);
    });
});
