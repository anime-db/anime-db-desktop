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

const fs = require('fs');
const path = require('path');

jest.mock('../../native/supervisor/cache-invalidation', () => ({
    hasBuildChanged:          jest.fn(() => false),
    invalidateCache:          jest.fn(),
    invalidateMarketSnapshot: jest.fn(),
    commitFingerprint:        jest.fn(),
}));
jest.mock('../../native/supervisor/frankenphp', () => ({
    start:         jest.fn(() => Promise.resolve({ httpPort: 8000, wsPort: 8001 })),
    stop:          jest.fn(() => Promise.resolve()),
    killOrphan:    jest.fn(() => Promise.resolve()),
    ensurePhpIni:  jest.fn(),
    events:        { on: jest.fn() },
}));
jest.mock('../../native/supervisor/downloads-poll', () => ({
    run: jest.fn(() => Promise.resolve()),
}));
jest.mock('../../native/supervisor/market-refresh', () => ({
    run: jest.fn(() => Promise.resolve()),
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
    run:             jest.fn(() => Promise.resolve()),
    consumeRequired: jest.fn(() => false),
}));
jest.mock('../../native/supervisor/plugin-reconcile', () => ({
    run: jest.fn(() => Promise.resolve()),
}));
jest.mock('../../native/supervisor/plugins-consumer', () => ({
    start:      jest.fn(() => Promise.resolve()),
    stop:       jest.fn(() => Promise.resolve()),
    killOrphan: jest.fn(() => Promise.resolve()),
    events:     { on: jest.fn() },
}));
jest.mock('../../native/supervisor/migrations', () => ({
    run:        jest.fn(() => Promise.resolve()),
    killOrphan: jest.fn(() => Promise.resolve()),
}));
jest.mock('../../native/supervisor/php-command', () => ({
    run:        jest.fn(() => Promise.resolve()),
    killOrphan: jest.fn(() => Promise.resolve()),
}));
jest.mock('../../native/supervisor/safe-mode', () => ({
    beginStartAttempt:  jest.fn(() => false),
    hasModeChanged:     jest.fn(() => false),
    commitStartSuccess: jest.fn(),
}));

const cacheInvalidation = require('../../native/supervisor/cache-invalidation');
const downloadsPoll     = require('../../native/supervisor/downloads-poll');
const frankenphp        = require('../../native/supervisor/frankenphp');
const marketRefresh     = require('../../native/supervisor/market-refresh');
const meilisearch       = require('../../native/supervisor/meilisearch');
const messengerConsumer = require('../../native/supervisor/messenger-consumer');
const migrations        = require('../../native/supervisor/migrations');
const phpCommand        = require('../../native/supervisor/php-command');
const pluginReconcile   = require('../../native/supervisor/plugin-reconcile');
const pluginsConsumer   = require('../../native/supervisor/plugins-consumer');
const safeModeState     = require('../../native/supervisor/safe-mode');
const searchReindex     = require('../../native/supervisor/search-reindex');
const supervisor        = require('../../native/supervisor');

describe('supervisor.start', () => {
    beforeEach(() => {
        // Значения по умолчанию, чтобы тесты не зависели от того, что настроил предыдущий:
        // jest.clearAllMocks() чистит статистику вызовов, но не реализации.
        cacheInvalidation.hasBuildChanged.mockReturnValue(false);
        cacheInvalidation.invalidateCache.mockImplementation(() => {});
        cacheInvalidation.invalidateMarketSnapshot.mockImplementation(() => {});
        cacheInvalidation.commitFingerprint.mockImplementation(() => {});
        safeModeState.hasModeChanged.mockReturnValue(false);
        safeModeState.commitStartSuccess.mockImplementation(() => {});
        frankenphp.start.mockResolvedValue({ httpPort: 8000, wsPort: 8001 });
        messengerConsumer.start.mockResolvedValue(undefined);
        pluginsConsumer.start.mockResolvedValue(undefined);
        meilisearch.start.mockResolvedValue({ port: 7700, key: 'k', wiped: false });
        searchReindex.run.mockResolvedValue(undefined);
        searchReindex.consumeRequired.mockReturnValue(false);
        pluginReconcile.run.mockResolvedValue(undefined);
        marketRefresh.run.mockResolvedValue(undefined);
        downloadsPoll.run.mockResolvedValue(undefined);
        migrations.run.mockResolvedValue(undefined);
    });

    afterEach(() => {
        jest.clearAllMocks();
    });

    // Миграции стартуют до веб-воркера, поэтому их контекст — без appPort (см. env.js).
    test('runs migrations before starting FrankenPHP, with a context that has no appPort', async () => {
        const callOrder = [];
        migrations.run.mockImplementation(() => {
            callOrder.push('migrations.run');
            return Promise.resolve();
        });
        frankenphp.start.mockImplementation(() => {
            callOrder.push('frankenphp.start');
            return Promise.resolve({ httpPort: 8000, wsPort: 8001 });
        });

        await supervisor.start(jest.fn());

        expect(callOrder).toEqual(['migrations.run', 'frankenphp.start']);
        expect(migrations.run).toHaveBeenCalledWith({
            qbittorrentPort: 9000,
            meiliPort:       7700,
            meiliKey:        'k',
            safeMode:        false,
        });
    });

    /**
     * Порядок здесь несущий, а не косметический. Расширения Windows-сборки — подгружаемые DLL
     * (issue #477), путь к ним даёт только extension_dir из php.ini, и без него bin/console падает
     * на vendor/composer/platform_check.php ещё до Symfony. Пока ensurePhpIni() вызывался лишь
     * внутри frankenphp.start(), на ЧИСТОМ профиле миграции успевали отработать раньше, чем файл
     * появлялся, и весь запуск ложился (issue #552). Отказ был строго первозапускным: со второго
     * раза php.ini уже лежал от прошлого сеанса, поэтому в разработке не воспроизводился.
     */
    test('writes php.ini before the first PHP process of the session, not with the web worker', async () => {
        const callOrder = [];
        frankenphp.ensurePhpIni.mockImplementation(() => callOrder.push('ensurePhpIni'));
        migrations.run.mockImplementation(() => {
            callOrder.push('migrations.run');
            return Promise.resolve();
        });

        await supervisor.start(jest.fn());

        expect(callOrder).toEqual(['ensurePhpIni', 'migrations.run']);
    });

    test('kills an orphaned migrations console process before any child process starts', async () => {
        meilisearch.start.mockResolvedValue({ port: 7700, key: 'k', wiped: false });

        await supervisor.start(jest.fn());

        expect(migrations.killOrphan).toHaveBeenCalled();
    });

    // Оба разовых консольных вызова (issue #400) идут через ту же начальную зачистку сирот, что
    // и долгоживущие процессы — до старта любого дочернего процесса текущего сеанса.
    test('kills orphaned one-off console processes before any child process starts', async () => {
        await supervisor.start(jest.fn());

        expect(phpCommand.killOrphan).toHaveBeenCalledWith('app:market:refresh');
        expect(phpCommand.killOrphan).toHaveBeenCalledWith('messenger:setup-transports');
        expect(phpCommand.killOrphan).toHaveBeenCalledWith('app:search:reindex');
        expect(phpCommand.killOrphan).toHaveBeenCalledWith('app:plugin:reconcile');
        expect(phpCommand.killOrphan).toHaveBeenCalledWith('app:downloads:poll');
    });

    // issue #701: plugins-consumer shares frankenphp.exe with messenger-consumer, so its orphan
    // from a previous session must be cleaned up before any child process of the current one
    // starts — same rationale as messenger-consumer's own killOrphan() above (issue #390).
    test('kills an orphaned plugins-consumer process before any child process starts', async () => {
        await supervisor.start(jest.fn());

        expect(pluginsConsumer.killOrphan).toHaveBeenCalled();
    });

    // Part 1 of #684 (issue #701): a dedicated long-lived `messenger:consume plugins` worker,
    // separate from messenger-consumer's own `async`/`media`/`scheduler_downloads_poll` list, so a
    // long-running plugin background task can never occupy the worker PushSyncMessage depends on.
    // Deleting the pluginsConsumer.start() call from supervisor/index.js must fail this test.
    describe('plugins-consumer startup (issue #701)', () => {
        test('starts the plugins consumer after messenger-consumer, with the same worker context', async () => {
            const callOrder = [];
            messengerConsumer.start.mockImplementation(() => {
                callOrder.push('messengerConsumer.start');
                return Promise.resolve();
            });
            pluginsConsumer.start.mockImplementation((context) => {
                callOrder.push('pluginsConsumer.start');
                return Promise.resolve(context);
            });

            await supervisor.start(jest.fn());

            expect(callOrder).toEqual(['messengerConsumer.start', 'pluginsConsumer.start']);
            expect(pluginsConsumer.start).toHaveBeenCalledWith({
                appPort:         8000,
                qbittorrentPort: 9000,
                meiliPort:       7700,
                meiliKey:        'k',
                safeMode:        false,
            });
        });

        test('does not start frankenphp before the plugins consumer has started', async () => {
            await expect(supervisor.start(jest.fn())).resolves.toBeDefined();

            expect(frankenphp.start).toHaveBeenCalledTimes(1);
            expect(pluginsConsumer.start).toHaveBeenCalledTimes(1);
        });

        test('propagates a plugins-consumer start failure and never commits the fingerprint', async () => {
            pluginsConsumer.start.mockRejectedValue(new Error('spawn failed'));

            await expect(supervisor.start(jest.fn())).rejects.toThrow('spawn failed');

            expect(cacheInvalidation.commitFingerprint).not.toHaveBeenCalled();
        });
    });

    test('runs search-reindex when meilisearch reports the index was wiped', async () => {
        meilisearch.start.mockResolvedValue({ port: 7700, key: 'k', wiped: true });

        const onProgress = jest.fn();
        await supervisor.start(onProgress);

        // Один и тот же контекст уходит и в messenger-consumer, и в переиндексацию (issue #391);
        // у них, в отличие от миграций, appPort уже известен.
        expect(searchReindex.run).toHaveBeenCalledWith({
            appPort:         8000,
            qbittorrentPort: 9000,
            meiliPort:       7700,
            meiliKey:        'k',
            safeMode:        false,
        });
        expect(onProgress).toHaveBeenCalledWith(4, 5, 'splash.step_reindex');
        expect(onProgress).toHaveBeenCalledWith(5, 5, 'splash.step_done');
    });

    test('skips search-reindex when the index was not wiped and no migrations were applied', async () => {
        meilisearch.start.mockResolvedValue({ port: 7700, key: 'k', wiped: false });
        migrations.run.mockResolvedValue(false);

        const onProgress = jest.fn();
        await supervisor.start(onProgress);

        expect(searchReindex.run).not.toHaveBeenCalled();
        expect(onProgress).not.toHaveBeenCalledWith(4, 5, 'splash.step_reindex');
        expect(onProgress).toHaveBeenCalledWith(5, 5, 'splash.step_done');
    });

    // issue #402: миграции меняют data.db сырым SQL в обход ORM-слушателей, которые диспатчат
    // индексирующие сообщения, поэтому индекс должен обновляться и без вайпа Meilisearch.
    test('runs search-reindex when migrations were applied, even if the index was not wiped', async () => {
        meilisearch.start.mockResolvedValue({ port: 7700, key: 'k', wiped: false });
        migrations.run.mockResolvedValue(true);

        const onProgress = jest.fn();
        await supervisor.start(onProgress);

        expect(searchReindex.run).toHaveBeenCalledWith({
            appPort:         8000,
            qbittorrentPort: 9000,
            meiliPort:       7700,
            meiliKey:        'k',
            safeMode:        false,
        });
        expect(onProgress).toHaveBeenCalledWith(4, 5, 'splash.step_reindex');
        expect(onProgress).toHaveBeenCalledWith(5, 5, 'splash.step_done');
    });

    // Issue #681 review: a restored backup snapshot can have the current schema (wiped and
    // migrationsApplied both false) yet still belong to a different catalog than the running
    // Meilisearch index — native/backup-restore/index.js marks this via searchReindex.markRequired()
    // ahead of its relaunch, and this consumeRequired() check is what honors that marker.
    test('runs search-reindex when a reindex was marked required, even if not wiped and no migrations applied', async () => {
        meilisearch.start.mockResolvedValue({ port: 7700, key: 'k', wiped: false });
        migrations.run.mockResolvedValue(false);
        searchReindex.consumeRequired.mockReturnValue(true);

        const onProgress = jest.fn();
        await supervisor.start(onProgress);

        expect(searchReindex.run).toHaveBeenCalledWith({
            appPort:         8000,
            qbittorrentPort: 9000,
            meiliPort:       7700,
            meiliKey:        'k',
            safeMode:        false,
        });
        expect(onProgress).toHaveBeenCalledWith(4, 5, 'splash.step_reindex');
    });

    test('does not fail startup when reindexing errors out', async () => {
        meilisearch.start.mockResolvedValue({ port: 7700, key: 'k', wiped: true });
        searchReindex.run.mockRejectedValue(new Error('boom'));

        await expect(supervisor.start(jest.fn())).resolves.toMatchObject({ frankenphpPort: 8000 });
    });

    // issue #440, epic #435 decision №5: the startup market refresh must be fired and forgotten,
    // never awaited — the appearing window/splash must not wait on a network fetch of the plugin
    // registry the way it does wait on migrations/FrankenPHP/messenger-consumer above.
    describe('market refresh trigger (issue #440)', () => {
        test('triggers a market refresh with the same context as messenger-consumer, after it has started', async () => {
            const callOrder = [];
            messengerConsumer.start.mockImplementation(() => {
                callOrder.push('messengerConsumer.start');
                return Promise.resolve();
            });
            marketRefresh.run.mockImplementation((context) => {
                callOrder.push('marketRefresh.run');
                return Promise.resolve(context);
            });

            await supervisor.start(jest.fn());

            expect(callOrder).toEqual(['messengerConsumer.start', 'marketRefresh.run']);
            expect(marketRefresh.run).toHaveBeenCalledWith({
                appPort:         8000,
                qbittorrentPort: 9000,
                meiliPort:       7700,
                meiliKey:        'k',
                safeMode:        false,
            });
        });

        test('does not block start() from resolving while the refresh is still in flight', async () => {
            let resolveRefresh;
            marketRefresh.run.mockImplementation(() => new Promise((resolve) => { resolveRefresh = resolve; }));

            await expect(supervisor.start(jest.fn())).resolves.toMatchObject({ frankenphpPort: 8000 });

            // Cleans up the still-pending promise so it doesn't leak into another test.
            resolveRefresh(undefined);
        });

        test('does not fail startup when the market refresh errors out', async () => {
            marketRefresh.run.mockRejectedValue(new Error('registry unreachable'));

            await expect(supervisor.start(jest.fn())).resolves.toMatchObject({ frankenphpPort: 8000 });
        });
    });

    // issue #685: a download finished while the app was closed must be linked without the
    // appearing window/splash waiting on it — same fired-and-forget rationale as the market
    // refresh trigger above, and this is the only thing that ever covers "app was closed" since
    // App\Scheduler\DownloadsPollSchedule's own tick only runs once messenger-consumer is up and
    // does not fire until its own interval elapses.
    describe('downloads-poll startup trigger (issue #685)', () => {
        test('triggers a downloads poll with the same context as messenger-consumer, after it has started', async () => {
            const callOrder = [];
            messengerConsumer.start.mockImplementation(() => {
                callOrder.push('messengerConsumer.start');
                return Promise.resolve();
            });
            downloadsPoll.run.mockImplementation((context) => {
                callOrder.push('downloadsPoll.run');
                return Promise.resolve(context);
            });

            await supervisor.start(jest.fn());

            expect(callOrder).toEqual(['messengerConsumer.start', 'downloadsPoll.run']);
            expect(downloadsPoll.run).toHaveBeenCalledWith({
                appPort:         8000,
                qbittorrentPort: 9000,
                meiliPort:       7700,
                meiliKey:        'k',
                safeMode:        false,
            });
        });

        test('does not block start() from resolving while the poll is still in flight', async () => {
            let resolvePoll;
            downloadsPoll.run.mockImplementation(() => new Promise((resolve) => { resolvePoll = resolve; }));

            await expect(supervisor.start(jest.fn())).resolves.toMatchObject({ frankenphpPort: 8000 });

            // Cleans up the still-pending promise so it doesn't leak into another test.
            resolvePoll(undefined);
        });

        test('does not fail startup when the downloads poll errors out', async () => {
            downloadsPoll.run.mockRejectedValue(new Error('app:downloads:poll завершился с кодом 1'));

            await expect(supervisor.start(jest.fn())).resolves.toMatchObject({ frankenphpPort: 8000 });
        });
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

    // issue #440, epic #435 decision №6: the market snapshot is keyed on CORE_VERSION, which only
    // an actual build change bumps — unlike the compiled-container cache above, a safe-mode-only
    // toggle must not delete it.
    test('deletes the market snapshot when the build changed', async () => {
        cacheInvalidation.hasBuildChanged.mockReturnValue(true);

        await supervisor.start();

        expect(cacheInvalidation.invalidateMarketSnapshot).toHaveBeenCalled();
    });

    test('does not delete the market snapshot when only the safe mode flag changed', async () => {
        cacheInvalidation.hasBuildChanged.mockReturnValue(false);
        safeModeState.hasModeChanged.mockReturnValue(true);

        await supervisor.start(jest.fn(), { safeMode: true });

        expect(cacheInvalidation.invalidateMarketSnapshot).not.toHaveBeenCalled();
    });

    test('does not delete the market snapshot when nothing changed', async () => {
        cacheInvalidation.hasBuildChanged.mockReturnValue(false);

        await supervisor.start();

        expect(cacheInvalidation.invalidateMarketSnapshot).not.toHaveBeenCalled();
    });

    // issue #575: an installed-plugins.php entry a previous, more permissive build accepted must
    // not survive an upgrade as-is, so the index is rebuilt against the current build's manifest
    // validation — but only on an actual build change, not on every startup.
    describe('plugin index reconciliation (issue #575)', () => {
        test('reconciles the installed-plugins index before starting frankenphp when the build changed', async () => {
            cacheInvalidation.hasBuildChanged.mockReturnValue(true);
            const callOrder = [];
            pluginReconcile.run.mockImplementation((context) => {
                callOrder.push('pluginReconcile.run');
                return Promise.resolve(context);
            });
            frankenphp.start.mockImplementation(() => {
                callOrder.push('frankenphp.start');
                return Promise.resolve({ httpPort: 8000, wsPort: 8001 });
            });

            await supervisor.start(jest.fn());

            expect(callOrder).toEqual(['pluginReconcile.run', 'frankenphp.start']);
            expect(pluginReconcile.run).toHaveBeenCalledWith(expect.objectContaining({
                qbittorrentPort: 9000,
                meiliPort:       7700,
                meiliKey:        'k',
                safeMode:        false,
            }));
        });

        test('does not reconcile the installed-plugins index when the build has not changed', async () => {
            cacheInvalidation.hasBuildChanged.mockReturnValue(false);

            await supervisor.start(jest.fn());

            expect(pluginReconcile.run).not.toHaveBeenCalled();
        });

        test('does not fail startup when reconciliation errors out', async () => {
            cacheInvalidation.hasBuildChanged.mockReturnValue(true);
            pluginReconcile.run.mockRejectedValue(new Error('boom'));

            await expect(supervisor.start(jest.fn())).resolves.toMatchObject({ frankenphpPort: 8000 });
        });
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

    test('does not start FrankenPHP when migrations fail', async () => {
        migrations.run.mockRejectedValue(new Error('migration failed'));

        await expect(supervisor.start(jest.fn())).rejects.toThrow('migration failed');

        expect(frankenphp.start).not.toHaveBeenCalled();
        expect(cacheInvalidation.commitFingerprint).not.toHaveBeenCalled();
    });

    // Safe mode (issue #403): SAFE_MODE must reach every PHP process the same way, since
    // migrations.run() boots the same Kernel (and its plugin bundles) as frankenphp.
    describe('safe mode (issue #403)', () => {
        test('passes safeMode:true through the shared context to migrations, frankenphp, messenger-consumer and plugins-consumer', async () => {
            await supervisor.start(jest.fn(), { safeMode: true });

            expect(migrations.run).toHaveBeenCalledWith(expect.objectContaining({ safeMode: true }));
            expect(frankenphp.start).toHaveBeenCalledWith(expect.objectContaining({ safeMode: true }));
            expect(messengerConsumer.start).toHaveBeenCalledWith(expect.objectContaining({ safeMode: true }));
            expect(pluginsConsumer.start).toHaveBeenCalledWith(expect.objectContaining({ safeMode: true }));
        });

        test('defaults to safeMode:false when no options are given', async () => {
            await supervisor.start(jest.fn());

            expect(frankenphp.start).toHaveBeenCalledWith(expect.objectContaining({ safeMode: false }));
        });

        test('invalidates the cache when the safe mode flag changed even though the build did not', async () => {
            cacheInvalidation.hasBuildChanged.mockReturnValue(false);
            safeModeState.hasModeChanged.mockReturnValue(true);

            await supervisor.start(jest.fn(), { safeMode: true });

            expect(safeModeState.hasModeChanged).toHaveBeenCalledWith(true);
            expect(cacheInvalidation.invalidateCache).toHaveBeenCalled();
        });

        test('does not invalidate the cache when neither the build nor the safe mode flag changed', async () => {
            cacheInvalidation.hasBuildChanged.mockReturnValue(false);
            safeModeState.hasModeChanged.mockReturnValue(false);

            await supervisor.start(jest.fn());

            expect(cacheInvalidation.invalidateCache).not.toHaveBeenCalled();
        });

        test('commits the safe mode state only after every process has started successfully, alongside the fingerprint', async () => {
            const callOrder = [];
            cacheInvalidation.commitFingerprint.mockImplementation(() => callOrder.push('commitFingerprint'));
            safeModeState.commitStartSuccess.mockImplementation(() => callOrder.push('commitStartSuccess'));

            await supervisor.start(jest.fn(), { safeMode: true });

            expect(callOrder).toEqual(['commitFingerprint', 'commitStartSuccess']);
            expect(safeModeState.commitStartSuccess).toHaveBeenCalledWith(true);
        });

        test('does not commit the safe mode state when a child process fails to start', async () => {
            frankenphp.start.mockRejectedValue(new Error('spawn failed'));

            await expect(supervisor.start(jest.fn(), { safeMode: true })).rejects.toThrow('spawn failed');

            expect(safeModeState.commitStartSuccess).not.toHaveBeenCalled();
        });
    });
});

// issue #411 — "make live" step of plugin activation: invalidate the real cache and restart the
// live worker processes on WORKERS_RELOAD_EVENT, rolling back a failed restart instead of letting
// the processes' own crash-loop backoff fight a broken plugin forever.
describe('supervisor.reloadForPlugin (issue #411)', () => {
    beforeEach(async () => {
        cacheInvalidation.hasBuildChanged.mockReturnValue(false);
        cacheInvalidation.invalidateCache.mockImplementation(() => {});
        cacheInvalidation.commitFingerprint.mockImplementation(() => {});
        safeModeState.hasModeChanged.mockReturnValue(false);
        safeModeState.commitStartSuccess.mockImplementation(() => {});
        frankenphp.start.mockResolvedValue({ httpPort: 8000, wsPort: 8001 });
        messengerConsumer.start.mockResolvedValue(undefined);
        pluginsConsumer.start.mockResolvedValue(undefined);
        meilisearch.start.mockResolvedValue({ port: 7700, key: 'k', wiped: false });
        searchReindex.run.mockResolvedValue(undefined);
        migrations.run.mockResolvedValue(undefined);

        // Establishes liveContext the same way a real app start would — reloadForPlugin() has
        // nothing to restart before this.
        await supervisor.start(jest.fn());
        jest.clearAllMocks();

        frankenphp.start.mockResolvedValue({ httpPort: 8000, wsPort: 8001 });
        frankenphp.stop.mockResolvedValue(undefined);
        messengerConsumer.start.mockResolvedValue(undefined);
        messengerConsumer.stop.mockResolvedValue(undefined);
        pluginsConsumer.start.mockResolvedValue(undefined);
        pluginsConsumer.stop.mockResolvedValue(undefined);
        cacheInvalidation.invalidateCache.mockImplementation(() => {});
        phpCommand.run.mockResolvedValue(undefined);
    });

    afterEach(() => {
        jest.clearAllMocks();
    });

    test('invalidates the cache and restarts messenger-consumer/plugins-consumer then frankenphp then messenger-consumer/plugins-consumer again on the same ports', async () => {
        const callOrder = [];
        cacheInvalidation.invalidateCache.mockImplementation(() => callOrder.push('invalidateCache'));
        messengerConsumer.stop.mockImplementation(() => { callOrder.push('messengerConsumer.stop'); return Promise.resolve(); });
        pluginsConsumer.stop.mockImplementation(() => { callOrder.push('pluginsConsumer.stop'); return Promise.resolve(); });
        frankenphp.stop.mockImplementation(() => { callOrder.push('frankenphp.stop'); return Promise.resolve(); });
        frankenphp.start.mockImplementation(() => {
            callOrder.push('frankenphp.start');
            return Promise.resolve({ httpPort: 8000, wsPort: 8001 });
        });
        messengerConsumer.start.mockImplementation(() => { callOrder.push('messengerConsumer.start'); return Promise.resolve(); });
        pluginsConsumer.start.mockImplementation(() => { callOrder.push('pluginsConsumer.start'); return Promise.resolve(); });

        await supervisor.reloadForPlugin('animedb-shikimori');

        expect(callOrder).toEqual([
            'invalidateCache', 'messengerConsumer.stop', 'pluginsConsumer.stop', 'frankenphp.stop',
            'frankenphp.start', 'messengerConsumer.start', 'pluginsConsumer.start',
        ]);
        // Re-requests the ports the live worker was already bound to (issue #411) — the
        // already-open BrowserWindow and the reconnecting WsClient both assume they don't change.
        expect(frankenphp.start).toHaveBeenCalledWith(expect.any(Object), { port: 8000, wsPort: 8001 });
        expect(phpCommand.run).not.toHaveBeenCalled();
    });

    test('emits plugin-activated once the restarted processes are healthy', async () => {
        const handler = jest.fn();
        supervisor.events.once('plugin-activated', handler);

        await supervisor.reloadForPlugin('animedb-shikimori');

        expect(handler).toHaveBeenCalledWith({ pluginId: 'animedb-shikimori' });
    });

    test('rolls back via app:plugin:deactivate and restarts clean when the restarted worker never becomes healthy', async () => {
        frankenphp.start
            .mockRejectedValueOnce(new Error('health check timed out'))
            .mockResolvedValueOnce({ httpPort: 8000, wsPort: 8001 });
        const failedHandler = jest.fn();
        supervisor.events.once('plugin-activation-failed', failedHandler);

        await supervisor.reloadForPlugin('animedb-broken');

        expect(phpCommand.run).toHaveBeenCalledWith(
            'app:plugin:deactivate', ['animedb-broken'], expect.any(Object), expect.any(Number),
        );
        expect(cacheInvalidation.invalidateCache).toHaveBeenCalledTimes(2);
        expect(frankenphp.start).toHaveBeenCalledTimes(2);
        expect(messengerConsumer.start).toHaveBeenCalledTimes(1);
        expect(pluginsConsumer.start).toHaveBeenCalledTimes(1);
        expect(failedHandler).toHaveBeenCalledWith({ pluginId: 'animedb-broken' });
    });

    test('a failed app:plugin:deactivate rollback call does not stop the clean restart from being attempted', async () => {
        frankenphp.start.mockRejectedValueOnce(new Error('health check timed out'))
            .mockResolvedValueOnce({ httpPort: 8000, wsPort: 8001 });
        phpCommand.run.mockRejectedValue(new Error('console command timed out'));

        await supervisor.reloadForPlugin('animedb-broken');

        expect(frankenphp.start).toHaveBeenCalledTimes(2);
        expect(messengerConsumer.start).toHaveBeenCalledTimes(1);
        expect(pluginsConsumer.start).toHaveBeenCalledTimes(1);
    });

    test('never rejects even when the rollback restart also fails', async () => {
        frankenphp.start.mockRejectedValue(new Error('still broken'));

        await expect(supervisor.reloadForPlugin('animedb-broken')).resolves.toBeUndefined();

        expect(phpCommand.run).toHaveBeenCalled();
    });

    // issue #424: frankenphp.start()/messengerConsumer.start()/pluginsConsumer.start() flip their
    // own `stopping` flag back to false as soon as they're called — if the rollback restart itself
    // never becomes healthy (e.g. a locked plugin folder defeats app:plugin:deactivate, and the
    // pre-plugin process also fails to come up healthy), that leaves their crash-loop backoff
    // armed with `stopping === false`, respawning forever against a state already known to be
    // broken. The extra stop() call this test checks for is what cancels that armed backoff.
    test('stops all three processes again after a failed deactivate and a failed rollback restart, to cancel any backoff respawn the rollback start armed', async () => {
        frankenphp.start.mockRejectedValue(new Error('still broken'));
        phpCommand.run.mockRejectedValue(new Error('plugin folder is locked'));

        await supervisor.reloadForPlugin('animedb-broken');

        // Once before the failed activation attempt's own restart, once before the rollback
        // restart, and once more after the rollback restart also fails.
        expect(frankenphp.stop).toHaveBeenCalledTimes(3);
        expect(messengerConsumer.stop).toHaveBeenCalledTimes(3);
        expect(pluginsConsumer.stop).toHaveBeenCalledTimes(3);
    });

    test('a second signal while a reload is in flight coalesces into a single extra run for the latest plugin id', async () => {
        let resolveFirstStart;
        frankenphp.start
            .mockImplementationOnce(() => new Promise((resolve) => { resolveFirstStart = resolve; }))
            .mockResolvedValue({ httpPort: 8000, wsPort: 8001 });

        const first = supervisor.reloadForPlugin('plugin-a');
        const second = supervisor.reloadForPlugin('plugin-b');
        const third = supervisor.reloadForPlugin('plugin-c');

        // second/third join the same in-flight promise as first — no overlapping restart starts.
        expect(second).toBe(first);
        expect(third).toBe(first);

        // Lets the pending microtasks (messengerConsumer.stop -> frankenphp.stop -> the
        // frankenphp.start call) actually run, so the mock implementation above has executed and
        // captured resolveFirstStart, before resolving it.
        await new Promise((resolve) => { setImmediate(resolve); });

        resolveFirstStart({ httpPort: 8000, wsPort: 8001 });
        await first;

        // One run for plugin-a (the in-flight one) plus one coalesced run for plugin-c (the
        // latest queued id) — plugin-b's own request never gets its own run.
        expect(frankenphp.start).toHaveBeenCalledTimes(2);
    });
});

// WORKERS_RELOAD_EVENT actually used by lifecycle/index.js to trigger reloadForPlugin().
describe('WORKERS_RELOAD_EVENT contract with the PHP side', () => {
    test('matches App\\Service\\Plugin\\ZipPluginInstaller::WORKERS_RELOAD_EVENT', () => {
        const phpSource = fs.readFileSync(
            path.join(__dirname, '../../app/src/Service/Plugin/ZipPluginInstaller.php'),
            'utf8',
        );

        const match = phpSource.match(/public const string WORKERS_RELOAD_EVENT = '([^']+)';/);

        expect(match).not.toBeNull();
        expect(supervisor.WORKERS_RELOAD_EVENT).toBe(match[1]);
    });
});
