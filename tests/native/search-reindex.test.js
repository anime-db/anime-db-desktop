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

const mockRun = jest.fn();
jest.mock('../../native/supervisor/php-command', () => ({
    run: (...args) => mockRun(...args),
}));

jest.mock('../../native/paths', () => ({
    getStatePath: jest.fn(() => '/fake/userData/state.json'),
}));

const fs = require('fs');
const { run, markRequired, consumeRequired } = require('../../native/supervisor/search-reindex');

const CONTEXT = { appPort: 8000, qbittorrentPort: 9999, meiliPort: 7700, meiliKey: 'test-key' };

let stateOnDisk;

beforeEach(() => {
    stateOnDisk = undefined;
    jest.spyOn(fs, 'readFileSync').mockImplementation(() => {
        if (stateOnDisk === undefined) throw Object.assign(new Error('ENOENT'), { code: 'ENOENT' });
        return stateOnDisk;
    });
    jest.spyOn(fs, 'writeFileSync').mockImplementation((_, contents) => { stateOnDisk = contents; });
    jest.spyOn(fs, 'mkdirSync').mockImplementation(() => {});
});

afterEach(() => {
    jest.restoreAllMocks();
    jest.clearAllMocks();
});

describe('run', () => {
    // Разовая переиндексация обязана идти через общую обёртку php-command.js (issue #400) —
    // именно она отвечает за env, лог, таймаут и PID-трекинг; search-reindex.js своего спавна
    // не заводит.
    test('delegates to php-command.js with the reindex command and a generous timeout', () => {
        run(CONTEXT);

        expect(mockRun).toHaveBeenCalledWith('app:search:reindex', [], CONTEXT, expect.any(Number));
        const timeoutMs = mockRun.mock.calls[0][3];
        expect(timeoutMs).toBeGreaterThan(60000);
    });

    test('resolves and rejects exactly as php-command.js does', async () => {
        mockRun.mockResolvedValueOnce(undefined);
        await expect(run(CONTEXT)).resolves.toBeUndefined();

        mockRun.mockRejectedValueOnce(new Error('app:search:reindex завершился с кодом 1'));
        await expect(run(CONTEXT)).rejects.toThrow('app:search:reindex завершился с кодом 1');
    });
});

// Issue #681 review: index.js#start() only reindexes on wiped||migrationsApplied, which both
// stay false when a restored snapshot has the same schema as the current one — this marker is
// how native/backup-restore/index.js forces a reindex on the next start regardless.
describe('markRequired / consumeRequired', () => {
    test('consumeRequired reports false when nothing was ever marked', () => {
        expect(consumeRequired()).toBe(false);
    });

    test('consumeRequired reports true exactly once after markRequired, then reverts to false', () => {
        markRequired();

        expect(consumeRequired()).toBe(true);
        expect(consumeRequired()).toBe(false);
    });

    test('markRequired merges into existing state.json content instead of overwriting other fields', () => {
        stateOnDisk = JSON.stringify({ someOtherField: 'keep-me' });

        markRequired();

        expect(JSON.parse(stateOnDisk).someOtherField).toBe('keep-me');
    });

    test('consumeRequired merges when clearing the marker', () => {
        stateOnDisk = JSON.stringify({ reindexRequired: true, someOtherField: 'keep-me' });

        expect(consumeRequired()).toBe(true);

        const written = JSON.parse(stateOnDisk);
        expect(written.reindexRequired).toBe(false);
        expect(written.someOtherField).toBe('keep-me');
    });
});
