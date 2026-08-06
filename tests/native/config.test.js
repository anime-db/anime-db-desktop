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

jest.mock('electron', () => ({
    app: { getLocale: jest.fn() },
}));

const os    = require('os');
const path  = require('path');
const fs    = require('fs');
const paths = require('../../native/paths');
const { app } = require('electron');
const {
    getOrCreateAppSecret,
    getOrCreateLocale,
    getLocale,
    mapOsLocaleToAppLocale,
} = require('../../native/config');

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

describe('mapOsLocaleToAppLocale', () => {
    test.each([
        ['ru-RU', 'ru'],
        ['ru-BY', 'ru'],
        ['ru', 'ru'],
        ['RU-ru', 'ru'],
        ['be-BY', 'ru'],
        ['be', 'ru'],
        ['kk-KZ', 'ru'],
        ['kk', 'ru'],
        ['ky-KG', 'ru'],
        ['ky', 'ru'],
        ['tg-TJ', 'ru'],
        ['tg', 'ru'],
        ['uz-UZ', 'ru'],
        ['uz', 'ru'],
        ['hy-AM', 'ru'],
        ['hy', 'ru'],
        ['az-AZ', 'ru'],
        ['az', 'ru'],
        ['KK-kz', 'ru'],
        ['en-US', 'en'],
        ['en', 'en'],
        ['de-DE', 'en'],
        ['fr', 'en'],
        ['ka-GE', 'en'],
        ['ka', 'en'],
        ['uk-UA', 'en'],
        ['uk', 'en'],
        ['ro-MD', 'en'],
        ['ro', 'en'],
    ])('maps %s to %s', (osLocale, expected) => {
        expect(mapOsLocaleToAppLocale(osLocale)).toBe(expected);
    });
});

describe('getOrCreateLocale', () => {
    test('derives the locale from app.getLocale() on first run', () => {
        app.getLocale.mockReturnValue('ru-RU');
        expect(getOrCreateLocale()).toBe('ru');
    });

    test('falls back to "en" for a non-Russian OS locale', () => {
        app.getLocale.mockReturnValue('en-US');
        expect(getOrCreateLocale()).toBe('en');
    });

    test('persists the locale to config.json', () => {
        app.getLocale.mockReturnValue('ru-RU');
        getOrCreateLocale();

        const configPath = path.join(tmpDir, 'config.json');
        const stored = JSON.parse(fs.readFileSync(configPath, 'utf8'));
        expect(stored.locale).toBe('ru');
    });

    test('does not overwrite an already persisted locale', () => {
        const configPath = path.join(tmpDir, 'config.json');
        fs.writeFileSync(configPath, JSON.stringify({ locale: 'en' }), 'utf8');
        app.getLocale.mockReturnValue('ru-RU');

        expect(getOrCreateLocale()).toBe('en');
    });

    test('preserves other fields in config.json', () => {
        app.getLocale.mockReturnValue('ru-RU');
        getOrCreateAppSecret();
        getOrCreateLocale();

        const configPath = path.join(tmpDir, 'config.json');
        const stored = JSON.parse(fs.readFileSync(configPath, 'utf8'));
        expect(stored.appSecret).toMatch(/^[0-9a-f]{64}$/);
        expect(stored.locale).toBe('ru');
    });
});

describe('readConfig error handling', () => {
    test('falls back to {} when config.json contains truncated/invalid JSON', () => {
        const configPath = path.join(tmpDir, 'config.json');
        fs.writeFileSync(configPath, '{"locale": "ru", "appSecret": ', 'utf8');

        app.getLocale.mockReturnValue('en-US');

        expect(() => getLocale()).not.toThrow();
        expect(getLocale()).toBe('en');
    });

    test('recovers by regenerating a secret when the file becomes corrupted', () => {
        const secret = getOrCreateAppSecret();

        const configPath = path.join(tmpDir, 'config.json');
        fs.writeFileSync(configPath, 'not json at all', 'utf8');

        expect(() => getOrCreateAppSecret()).not.toThrow();
        expect(getOrCreateAppSecret()).not.toBe(secret);
    });

    test.each([
        ['null', 'null'],
        ['a number', '42'],
        ['a string', '"just a string"'],
        ['an array', '[1, 2, 3]'],
    ])('normalizes valid but non-object JSON (%s) to {} instead of leaking it', (_label, jsonContent) => {
        const configPath = path.join(tmpDir, 'config.json');
        fs.writeFileSync(configPath, jsonContent, 'utf8');

        app.getLocale.mockReturnValue('en-US');

        expect(() => getOrCreateAppSecret()).not.toThrow();
        expect(getOrCreateAppSecret()).toMatch(/^[0-9a-f]{64}$/);
    });
});

describe('writeConfig atomicity', () => {
    test('leaves no temporary file behind after a write', () => {
        getOrCreateAppSecret();

        const leftovers = fs.readdirSync(tmpDir).filter((name) => name.endsWith('.tmp'));
        expect(leftovers).toEqual([]);
    });
});

describe('getLocale', () => {
    test('reads the locale persisted by getOrCreateLocale', () => {
        app.getLocale.mockReturnValue('ru-RU');
        getOrCreateLocale();

        expect(getLocale()).toBe('ru');
    });

    test('falls back to mapping the current OS locale when config.json has none yet', () => {
        app.getLocale.mockReturnValue('en-US');
        expect(getLocale()).toBe('en');
    });

    test('reflects a locale changed directly in config.json, without a restart', () => {
        const configPath = path.join(tmpDir, 'config.json');
        fs.writeFileSync(configPath, JSON.stringify({ locale: 'en' }), 'utf8');
        expect(getLocale()).toBe('en');

        fs.writeFileSync(configPath, JSON.stringify({ locale: 'ru' }), 'utf8');
        expect(getLocale()).toBe('ru');
    });
});
