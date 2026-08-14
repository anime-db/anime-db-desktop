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

jest.mock('../../native/supervisor/frankenphp', () => ({
    start:      jest.fn(() => Promise.resolve({ httpPort: 8000, wsPort: 8001 })),
    stop:       jest.fn(() => Promise.resolve()),
    killOrphan: jest.fn(() => Promise.resolve()),
    events:     { on: jest.fn() },
}));
jest.mock('../../native/supervisor/meilisearch', () => ({
    start:      jest.fn(),
    stop:       jest.fn(() => Promise.resolve()),
    killOrphan: jest.fn(() => Promise.resolve()),
}));
jest.mock('../../native/supervisor/messenger-consumer', () => ({
    start:      jest.fn(() => Promise.resolve()),
    stop:       jest.fn(() => Promise.resolve()),
    killOrphan: jest.fn(() => Promise.resolve()),
    events:     { on: jest.fn() },
}));
jest.mock('../../native/supervisor/qbittorrent', () => ({
    start:      jest.fn(() => Promise.resolve({ webuiPort: 9000 })),
    stop:       jest.fn(() => Promise.resolve()),
    killOrphan: jest.fn(() => Promise.resolve()),
    events:     { on: jest.fn() },
}));
jest.mock('../../native/supervisor/search-reindex', () => ({
    run: jest.fn(() => Promise.resolve()),
}));

const meilisearch   = require('../../native/supervisor/meilisearch');
const searchReindex = require('../../native/supervisor/search-reindex');
const supervisor    = require('../../native/supervisor');

describe('supervisor.start', () => {
    afterEach(() => {
        jest.clearAllMocks();
    });

    test('runs search-reindex when meilisearch reports the index was wiped', async () => {
        meilisearch.start.mockResolvedValue({ port: 7700, key: 'k', wiped: true });

        const onProgress = jest.fn();
        await supervisor.start(onProgress);

        expect(searchReindex.run).toHaveBeenCalledWith(7700, 'k');
        expect(onProgress).toHaveBeenCalledWith(3, 'Переиндексация каталога...');
        expect(onProgress).toHaveBeenCalledWith(3, 'Готово');
    });

    test('skips search-reindex when the index was not wiped', async () => {
        meilisearch.start.mockResolvedValue({ port: 7700, key: 'k', wiped: false });

        const onProgress = jest.fn();
        await supervisor.start(onProgress);

        expect(searchReindex.run).not.toHaveBeenCalled();
        expect(onProgress).not.toHaveBeenCalledWith(3, 'Переиндексация каталога...');
        expect(onProgress).toHaveBeenCalledWith(3, 'Готово');
    });

    test('does not fail startup when reindexing errors out', async () => {
        meilisearch.start.mockResolvedValue({ port: 7700, key: 'k', wiped: true });
        searchReindex.run.mockRejectedValue(new Error('boom'));

        await expect(supervisor.start(jest.fn())).resolves.toMatchObject({ frankenphpPort: 8000 });
    });
});
