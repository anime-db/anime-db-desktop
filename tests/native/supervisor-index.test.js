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

jest.mock('../../native/supervisor/cache-invalidation', () => ({
    hasBuildChanged:   jest.fn(() => false),
    invalidateCache:   jest.fn(),
    commitFingerprint: jest.fn(),
}));
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

const cacheInvalidation = require('../../native/supervisor/cache-invalidation');
const frankenphp        = require('../../native/supervisor/frankenphp');
const meilisearch       = require('../../native/supervisor/meilisearch');
const messengerConsumer = require('../../native/supervisor/messenger-consumer');
const searchReindex     = require('../../native/supervisor/search-reindex');
const supervisor        = require('../../native/supervisor');

describe('supervisor.start', () => {
    beforeEach(() => {
        // Значения по умолчанию, чтобы тесты не зависели от того, что настроил предыдущий:
        // jest.clearAllMocks() чистит статистику вызовов, но не реализации.
        cacheInvalidation.hasBuildChanged.mockReturnValue(false);
        cacheInvalidation.invalidateCache.mockImplementation(() => {});
        cacheInvalidation.commitFingerprint.mockImplementation(() => {});
        frankenphp.start.mockResolvedValue({ httpPort: 8000, wsPort: 8001 });
        messengerConsumer.start.mockResolvedValue(undefined);
        meilisearch.start.mockResolvedValue({ port: 7700, key: 'k', wiped: false });
        searchReindex.run.mockResolvedValue(undefined);
    });

    afterEach(() => {
        jest.clearAllMocks();
    });

    test('runs search-reindex when meilisearch reports the index was wiped', async () => {
        meilisearch.start.mockResolvedValue({ port: 7700, key: 'k', wiped: true });

        const onProgress = jest.fn();
        await supervisor.start(onProgress);

        // Один и тот же контекст уходит и в messenger-consumer, и в переиндексацию (issue #391).
        expect(searchReindex.run).toHaveBeenCalledWith({
            appPort:         8000,
            qbittorrentPort: 9000,
            meiliPort:       7700,
            meiliKey:        'k',
        });
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

    // Инвалидация устаревшего скомпилированного контейнера (issue #386) обязана происходить до
    // запуска любого PHP-процесса: и frankenphp, и messenger-consumer бутуют одно и то же ядро,
    // и первый же бут против устаревшего дампа запекает его *.bundles.php для всех последующих.
    test('invalidates the cache before starting frankenphp or messenger-consumer when the build changed', async () => {
        cacheInvalidation.hasBuildChanged.mockReturnValue(true);
        const callOrder = [];
        cacheInvalidation.invalidateCache.mockImplementation(() => callOrder.push('invalidateCache'));
        frankenphp.start.mockImplementation(() => {
            callOrder.push('frankenphp.start');
            return Promise.resolve({ httpPort: 8000, wsPort: 8001 });
        });
        messengerConsumer.start.mockImplementation(() => {
            callOrder.push('messengerConsumer.start');
            return Promise.resolve();
        });

        await supervisor.start();

        expect(callOrder).toEqual(['invalidateCache', 'frankenphp.start', 'messengerConsumer.start']);
    });

    test('does not delete the cache when the build has not changed', async () => {
        cacheInvalidation.hasBuildChanged.mockReturnValue(false);

        await supervisor.start();

        expect(cacheInvalidation.invalidateCache).not.toHaveBeenCalled();
    });

    test('commits the build fingerprint only after every process has started successfully', async () => {
        const callOrder = [];
        messengerConsumer.start.mockImplementation(() => {
            callOrder.push('messengerConsumer.start');
            return Promise.resolve();
        });
        cacheInvalidation.commitFingerprint.mockImplementation(() => callOrder.push('commitFingerprint'));

        await supervisor.start();

        expect(callOrder).toEqual(['messengerConsumer.start', 'commitFingerprint']);
    });

    test('does not commit the fingerprint when a child process fails to start', async () => {
        frankenphp.start.mockRejectedValue(new Error('spawn failed'));

        await expect(supervisor.start()).rejects.toThrow('spawn failed');

        expect(cacheInvalidation.commitFingerprint).not.toHaveBeenCalled();
    });
});
