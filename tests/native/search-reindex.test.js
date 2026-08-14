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

jest.mock('electron', () => ({
    app: { getVersion: jest.fn(() => '1.2.3') },
}));
jest.mock('../../native/paths', () => ({
    getAppRootDir:         jest.fn(() => '/fake/app'),
    getDbPath:             jest.fn(() => '/fake/userData/data.db'),
    getQueueDbPath:        jest.fn(() => '/fake/userData/queue.db'),
    getPhpIniDir:          jest.fn(() => '/fake/userData'),
    getRuntimeDir:         jest.fn(() => '/fake/userData/var'),
    getMediaDir:           jest.fn(() => '/fake/userData/media'),
    getConfigPath:         jest.fn(() => '/fake/userData/config.json'),
    getPluginsConfigPath:  jest.fn(() => '/fake/userData/plugins.json'),
}));
jest.mock('../../native/config', () => ({
    getOrCreateAppSecret: jest.fn(() => 'a'.repeat(64)),
}));

const { EventEmitter } = require('events');
const { spawn } = require('child_process');

jest.mock('child_process', () => ({
    spawn: jest.fn(),
}));

const { run, buildEnv } = require('../../native/supervisor/search-reindex');

describe('buildEnv', () => {
    test('includes MEILISEARCH_URL and MEILISEARCH_KEY for the given port/key', () => {
        const env = buildEnv(7700, 'test-key');
        expect(env.MEILISEARCH_URL).toBe('http://127.0.0.1:7700');
        expect(env.MEILISEARCH_KEY).toBe('test-key');
    });

    test('includes DATABASE_URL as a sqlite:// URL', () => {
        const env = buildEnv(7700, 'test-key');
        expect(env.DATABASE_URL).toMatch(/^sqlite:\/\/\//);
        expect(env.DATABASE_URL).toContain('data.db');
    });
});

describe('run', () => {
    let fakeChild;

    beforeEach(() => {
        fakeChild = new EventEmitter();
        spawn.mockReturnValue(fakeChild);
    });

    afterEach(() => {
        jest.clearAllMocks();
    });

    test('spawns frankenphp.exe running app:search:reindex via php-cli', () => {
        run(7700, 'test-key');
        expect(spawn).toHaveBeenCalledWith(
            expect.stringContaining('frankenphp.exe'),
            expect.arrayContaining(['php-cli', expect.stringContaining('console'), 'app:search:reindex']),
            expect.objectContaining({ cwd: '/fake/app' }),
        );
    });

    test('resolves when the process exits with code 0', async () => {
        const promise = run(7700, 'test-key');
        fakeChild.emit('exit', 0);
        await expect(promise).resolves.toBeUndefined();
    });

    test('rejects when the process exits with a non-zero code', async () => {
        const promise = run(7700, 'test-key');
        fakeChild.emit('exit', 1);
        await expect(promise).rejects.toThrow('app:search:reindex завершился с кодом 1');
    });

    test('rejects when the process fails to spawn', async () => {
        const promise = run(7700, 'test-key');
        fakeChild.emit('error', new Error('spawn ENOENT'));
        await expect(promise).rejects.toThrow('spawn ENOENT');
    });
});
