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
    getAppRootDir:         jest.fn(() => '/fake/app'),
    getDbPath:             jest.fn(() => '/fake/userData/data.db'),
    getPhpIniDir:          jest.fn(() => '/fake/userData'),
    getPhpIniPath:         jest.fn(() => '/fake/userData/php.ini'),
    getRuntimeDir:         jest.fn(() => '/fake/userData/var'),
    getMeilisearchDataDir: jest.fn(() => '/fake/userData/meilisearch'),
    getMeilisearchKeyPath: jest.fn(() => '/fake/userData/meilisearch-key.txt'),
    getUserDataDir:        jest.fn(() => '/fake/userData'),
}));
jest.mock('../../native/config', () => ({
    getOrCreateAppSecret: jest.fn(() => 'a'.repeat(64)),
}));
jest.mock('../../native/supervisor/logrotate', () => ({
    pruneOldLogs:   jest.fn(),
    openLogStream:  jest.fn(),
}));
jest.mock('../../native/supervisor/port', () => ({
    findFreePort: jest.fn(),
}));
jest.mock('../../native/supervisor/healthcheck', () => ({
    waitForHealth: jest.fn(),
}));

const { buildEnv } = require('../../native/supervisor/frankenphp');

describe('buildEnv', () => {
    test('includes APP_PORT as a string', () => {
        const env = buildEnv(8000, 7700, 'test-key');
        expect(env.APP_PORT).toBe('8000');
        expect(typeof env.APP_PORT).toBe('string');
    });

    test('includes APP_ROOT pointing to the app directory', () => {
        const env = buildEnv(8000, 7700, 'test-key');
        expect(env.APP_ROOT).toBe('/fake/app');
    });

    test('includes APP_ENV set to prod', () => {
        const env = buildEnv(8000, 7700, 'test-key');
        expect(env.APP_ENV).toBe('prod');
    });

    test('includes DATABASE_URL as a sqlite:// URL', () => {
        const env = buildEnv(8000, 7700, 'test-key');
        expect(env.DATABASE_URL).toMatch(/^sqlite:\/\/\//);
        expect(env.DATABASE_URL).toContain('data.db');
    });

    test('includes PHPRC pointing to the php.ini directory', () => {
        const env = buildEnv(8000, 7700, 'test-key');
        expect(env.PHPRC).toBe('/fake/userData');
    });

    test('includes APP_RUNTIME_DIR pointing to the runtime dir', () => {
        const env = buildEnv(8000, 7700, 'test-key');
        expect(env.APP_RUNTIME_DIR).toBe('/fake/userData/var');
    });

    test('includes MEILISEARCH_URL using the given meiliPort', () => {
        const env = buildEnv(8000, 7700, 'test-key');
        expect(env.MEILISEARCH_URL).toBe('http://127.0.0.1:7700');

        const env2 = buildEnv(8000, 8888, 'test-key');
        expect(env2.MEILISEARCH_URL).toBe('http://127.0.0.1:8888');
    });

    test('includes MEILISEARCH_KEY matching the provided key', () => {
        const env = buildEnv(8000, 7700, 'my-secret-key');
        expect(env.MEILISEARCH_KEY).toBe('my-secret-key');
    });

    test('inherits existing process.env variables', () => {
        const env = buildEnv(8000, 7700, 'test-key');
        expect(env).toMatchObject(process.env);
    });

    test('includes APP_SECRET from getOrCreateAppSecret', () => {
        const env = buildEnv(8000, 7700, 'test-key');
        expect(env.APP_SECRET).toBe('a'.repeat(64));
        expect(env.APP_SECRET).toHaveLength(64);
    });
});
