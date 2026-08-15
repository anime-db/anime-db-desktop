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

const { EventEmitter } = require('events');
const cacheInvalidation = require('./cache-invalidation');
const frankenphp        = require('./frankenphp');
const meilisearch       = require('./meilisearch');
const messengerConsumer = require('./messenger-consumer');
const migrations        = require('./migrations');
const phpCommand        = require('./php-command');
const qbittorrent       = require('./qbittorrent');
const safeModeState     = require('./safe-mode');
const searchReindex     = require('./search-reindex');

const events = new EventEmitter();
frankenphp.events.on('exit', (code) => events.emit('exit', code));
messengerConsumer.events.on('exit', (code) => events.emit('exit', code));
qbittorrent.events.on('exit', (code) => events.emit('exit', code));

/**
 * Total number of splash-progress points (Meilisearch=0, migrations=1, FrankenPHP=2,
 * messenger-consumer=3, done=5) the progress bar divides by. Step 4 (reindex) is only reached
 * conditionally — same "jump straight to done" behavior the splash bar already had before it
 * became dynamic, just no longer hardcoded to exactly four steps (issue #392).
 */
const TOTAL_STEPS = 5;

/**
 * Backend event name (see App\Service\Plugin\ZipPluginInstaller::WORKERS_RELOAD_EVENT on the PHP
 * side, published from install() after a successful isolated cache warm-up) that
 * native/lifecycle/index.js listens for over /ws to trigger reloadForPlugin() below — the "make
 * live" step of plugin activation (issue #411). Keep this string in sync with the PHP side, same
 * lesson as issue #336/#361 for PROXY_CHANGED_EVENT/FIREWALL_RULE_CHANGED_EVENT.
 *
 * @type {string}
 */
const WORKERS_RELOAD_EVENT = 'workers.reload';

/** Budget for the `app:plugin:deactivate` rollback console call — same order of magnitude as the other one-off calls in php-command.js (issue #400). */
const PLUGIN_DEACTIVATE_TIMEOUT_MS = 30000;

/**
 * Session state reloadForPlugin() needs to restart the live worker processes: the shared
 * PhpContext (ports/keys, no appPort) plus the ports FrankenPHP is currently bound to, so a
 * restart can ask for the *same* ports back (see frankenphp.js#start's `preferred` param) instead
 * of drifting onto new ones the already-open BrowserWindow and reconnecting WsClient don't know
 * about. Set once at the end of start() below, updated after every successful reload.
 *
 * @type {{ phpContext: import('./env').PhpContext, frankenphpPort: number, wsPort: number } | null}
 */
let liveContext = null;

/**
 * Serializes reloadForPlugin() calls: a signal that arrives while one is already running does not
 * start a second, overlapping restart — it just records itself as `queuedPluginId` and rides the
 * in-flight run's promise. Once that run finishes, exactly one more run fires for the latest
 * queued plugin id (issue #411 idempotency requirement) rather than one per coalesced signal.
 *
 * @type {Promise<void> | null}
 */
let reloadInFlight = null;
/** @type {string | null} */
let queuedPluginId = null;

/**
 * Запускает все дочерние процессы и возвращает занятые ими порты.
 * Сначала зачищаются PID-файлы всех процессов-сирот от предыдущего сеанса — до того, как
 * запущен хоть один дочерний процесс текущего сеанса. frankenphp и messenger-consumer делят один
 * и тот же бинарник (frankenphp.exe); если зачистка messenger-consumer выполнялась бы лениво,
 * внутри его собственного start() (после того как frankenphp текущего сеанса уже запущен),
 * переиспользованный ОС PID мог бы совпасть с процессом текущего сеанса и убить его (issue #390).
 * Следом, там же и по той же причине "до первого PHP-процесса" — инвалидация устаревшего
 * скомпилированного контейнера Symfony (issue #386): и frankenphp, и messenger-consumer бутуют
 * одно и то же ядро, а APP_ENV=prod не проверяет свежесть ConfigCache сам, поэтому первый же бут
 * против устаревшего дампа запекает его *.bundles.php для всех последующих. Отпечаток сборки
 * фиксируется отдельно и только после успешного старта всех процессов (см. commitFingerprint
 * ниже) — если бы он писался заранее, падение где-то в середине старта считало бы апгрейд уже
 * обработанным. Ошибка инвалидации не перехватывается: пусть прервёт запуск и попадёт в лог
 * (native/crash-log.js) через catch в lifecycle/index.js, а не тихо продолжит работу против
 * устаревшего кэша. Meilisearch и qbittorrent-nox стартуют первыми (независимо друг от друга) —
 * их порты/ключи нужны FrankenPHP в env. Миграции и оба разовых консольных вызова
 * (messenger:setup-transports, app:search:reindex — оба идут через php-command.js, см. issue
 * #400) тоже участвуют в начальной зачистке сирот — тот же PID-файл (см. pid-tracker.js),
 * которым отмечается spawn консольной команды, должен быть проверен и убран до старта хоть
 * одного дочернего процесса текущего сеанса, иначе переиспользованный ОС PID мог бы совпасть с
 * процессом текущего сеанса.
 *
 * Doctrine-миграции (issue #392) прогоняются сразу после Meilisearch/qbittorrent и до старта
 * FrankenPHP — migrate не зависит от HTTP/поиска/очереди, только от DATABASE_URL, но схема
 * должна быть готова до того, как HTTP-воркер начнёт принимать запросы. На чистом профиле это
 * тот же путь: миграций ещё не применено ни одной, значит есть что применить, и migrate создаёт
 * схему с нуля. Провал (в т.ч. отказ по даунгрейду) прерывает запуск — см. MigrationBootstrapError.
 *
 * Если Meilisearch при старте вайпнул индекс из-за смены версии (issue #389) или если
 * migrations.run() реально применил хотя бы одну миграцию (issue #402 — Doctrine-миграции
 * меняют data.db сырым SQL в обход ORM-слушателей, которые диспатчат индексирующие сообщения,
 * поэтому без этого индекс молча расходится с каталогом), после поднятия FrankenPHP и
 * messenger-consumer автоматически прогоняется app:search:reindex — без этого приложение либо
 * стартует с пустым поиском, либо каталог расходится с индексом до ручного нажатия кнопки в
 * /settings. Ошибка переиндексации не блокирует старт приложения — только логируется.
 *
 * @param {((step: number, total: number, translationKey: string) => void) | undefined} onProgress
 *   translationKey is a native/translations/ key, not display text — the caller (native/lifecycle)
 *   resolves it for the current locale (issue #404).
 * @param {{ safeMode?: boolean }} [options]  safeMode (issue #403) — набор незакрытых стартов
 *                                             подряд, отслеживаемый native/lifecycle/index.js,
 *                                             попадает сюда как уже принятое пользователем решение
 * @returns {Promise<{ frankenphpPort: number, wsPort: number, meiliPort: number, qbittorrentPort: number }>}
 */
async function start(onProgress, { safeMode = false } = {}) {
    await Promise.all([
        frankenphp.killOrphan(),
        meilisearch.killOrphan(),
        messengerConsumer.killOrphan(),
        migrations.killOrphan(),
        phpCommand.killOrphan('messenger:setup-transports'),
        phpCommand.killOrphan('app:search:reindex'),
        qbittorrent.killOrphan(),
    ]);

    // Смена SAFE_MODE между запусками требует того же вайпа, что и смена сборки (issue #386) —
    // см. safe-mode.js#hasModeChanged: набор бандлов плагинов запекается в скомпилированный
    // контейнер, и без вайпа переключение режима не даст эффекта или "залипнет" после выхода.
    if (cacheInvalidation.hasBuildChanged() || safeModeState.hasModeChanged(safeMode)) {
        cacheInvalidation.invalidateCache();
    }

    const [{ port: meiliPort, key: meiliKey, wiped }, { webuiPort: qbittorrentPort }] = await Promise.all([
        meilisearch.start(),
        qbittorrent.start(),
    ]);

    // Один контекст на все PHP-процессы сеанса — см. env.js: набор путей и портов у них обязан
    // совпадать, поэтому он собирается здесь один раз, а не по месту каждым модулем. Миграции
    // стартуют до веб-воркера, поэтому appPort на этот момент ещё не существует — в PhpContext
    // он опционален (см. env.js), и OAUTH_CALLBACK_ORIGIN в их окружение не попадает.
    const phpContext = { qbittorrentPort, meiliPort, meiliKey, safeMode };

    if (onProgress) onProgress(1, TOTAL_STEPS, 'splash.step_migrations');
    const migrationsApplied = await migrations.run(phpContext);

    if (onProgress) onProgress(2, TOTAL_STEPS, 'splash.step_frankenphp');
    const { httpPort: frankenphpPort, wsPort } = await frankenphp.start(phpContext);
    if (onProgress) onProgress(3, TOTAL_STEPS, 'splash.step_messenger');

    // Тот же контекст, что у миграций, плюс порт поднятого веб-воркера — см. env.js.
    const workerContext = { ...phpContext, appPort: frankenphpPort };

    await messengerConsumer.start(workerContext);

    if (wiped || migrationsApplied) {
        if (onProgress) onProgress(4, TOTAL_STEPS, 'splash.step_reindex');
        try {
            await searchReindex.run(workerContext);
        } catch (err) {
            console.error('[search-reindex] не удалось переиндексировать каталог:', err.message);
        }
    }

    if (onProgress) onProgress(TOTAL_STEPS, TOTAL_STEPS, 'splash.step_done');

    cacheInvalidation.commitFingerprint();
    safeModeState.commitStartSuccess(safeMode);

    liveContext = { phpContext, frankenphpPort, wsPort };

    return { frankenphpPort, wsPort, meiliPort, qbittorrentPort };
}

/**
 * "Make live" step of plugin activation (issue #411): triggered by WORKERS_RELOAD_EVENT after a
 * plugin install's isolated cache warm-up succeeds (App\Service\Plugin\ZipPluginInstaller). That
 * warm-up only proves the container *compiles* with the new plugin — the already-running
 * FrankenPHP worker and messenger-consumer still hold the old one in memory, and the on-disk
 * cache is untouched, so neither picks up the plugin without this.
 *
 * Idempotent by serialization rather than by detecting "already active": concurrent/rapid signals
 * (e.g. two installs in a row) coalesce into a single extra run for the latest plugin id once the
 * in-flight one finishes, instead of overlapping restarts of the same child processes.
 *
 * @param {string} pluginId  only used for logging and as the rollback target on failure — the
 *                            restart itself always picks up whatever is currently on disk under
 *                            %app.plugins_dir%, not specifically this plugin
 * @returns {Promise<void>} never rejects — see performReload()'s own doc for why
 */
function reloadForPlugin(pluginId) {
    if (reloadInFlight) {
        queuedPluginId = pluginId;
        return reloadInFlight;
    }

    // Returning the coalesced run's promise from finally() (rather than just firing it and
    // forgetting) makes this promise settle only once that run also finishes — so a caller
    // awaiting reloadForPlugin() sees the fully-settled end state, and any signal arriving while
    // the coalesced run itself is in flight correctly joins it instead of starting a third,
    // overlapping run.
    reloadInFlight = performReload(pluginId).finally(() => {
        reloadInFlight = null;
        if (queuedPluginId !== null) {
            const next = queuedPluginId;
            queuedPluginId = null;
            return reloadForPlugin(next);
        }
        return undefined;
    });

    return reloadInFlight;
}

/**
 * Invalidates the real compiled-container cache and restarts FrankenPHP + messenger-consumer in
 * place, on the same ports (see frankenphp.js#start's `preferred` param) so the already-open
 * window and the reconnecting WsClient are unaffected.
 *
 * If the restarted worker never becomes healthy, both processes' own crash-loop backoff
 * (frankenphp.js/messenger-consumer.js `spawnProcess`) would otherwise keep retrying against the
 * same broken plugin forever — stop() is called again here specifically to break that loop (its
 * `stopping` guard prevents any backoff respawn already scheduled from firing) before rolling
 * back: remove the plugin via the `app:plugin:deactivate` console command (issue #411's rollback
 * requirement) and restart clean, into the pre-plugin state. Every step from here on is
 * best-effort and swallows its own errors — this function must never leave the supervisor's
 * crash-loop backoff to fight a plugin that is already known to be broken, and must never reject
 * (its caller is a fire-and-forget WS event handler with nothing better to do than log).
 *
 * @param {string} pluginId
 * @returns {Promise<void>}
 */
async function performReload(pluginId) {
    if (!liveContext) {
        console.error(`[supervisor] "${WORKERS_RELOAD_EVENT}" получен до завершения запуска — пропущен.`);
        return;
    }

    const { phpContext, frankenphpPort, wsPort } = liveContext;

    try {
        cacheInvalidation.invalidateCache();
        await messengerConsumer.stop();
        await frankenphp.stop();

        const started = await frankenphp.start(phpContext, { port: frankenphpPort, wsPort });
        liveContext = { phpContext, frankenphpPort: started.httpPort, wsPort: started.wsPort };
        await messengerConsumer.start({ ...phpContext, appPort: started.httpPort });

        events.emit('plugin-activated', { pluginId });
        return;
    } catch (err) {
        console.error(`[supervisor] активация плагина "${pluginId}" не удалась, откат:`, err.message);
    }

    try {
        await frankenphp.stop();
        await messengerConsumer.stop();

        try {
            await phpCommand.run('app:plugin:deactivate', [pluginId], phpContext, PLUGIN_DEACTIVATE_TIMEOUT_MS);
        } catch (removeErr) {
            console.error(`[supervisor] не удалось убрать плагин "${pluginId}" из реестра:`, removeErr.message);
        }

        cacheInvalidation.invalidateCache();

        const restarted = await frankenphp.start(phpContext, { port: frankenphpPort, wsPort });
        liveContext = { phpContext, frankenphpPort: restarted.httpPort, wsPort: restarted.wsPort };
        await messengerConsumer.start({ ...phpContext, appPort: restarted.httpPort });
    } catch (rollbackErr) {
        console.error('[supervisor] откат после неудачной активации плагина тоже не удался:', rollbackErr.message);
    }

    events.emit('plugin-activation-failed', { pluginId });
}

/**
 * Останавливает все дочерние процессы в правильном порядке:
 * сначала messenger-consumer и FrankenPHP (нет новых запросов и задач), затем
 * Meilisearch и qbittorrent-nox.
 *
 * @returns {Promise<void>}
 */
async function stop() {
    await messengerConsumer.stop();
    await frankenphp.stop();
    await Promise.all([meilisearch.stop(), qbittorrent.stop()]);
}

/**
 * Best-effort синхронный килл всех дочерних процессов на случай аварийного выхода Electron,
 * который не проходит через штатный stop() (см. process.on('exit') в lifecycle/index.js) —
 * дождаться асинхронного graceful-shutdown там уже нельзя.
 */
function killSync() {
    messengerConsumer.killSync();
    frankenphp.killSync();
    meilisearch.killSync();
    qbittorrent.killSync();
}

module.exports = { start, stop, killSync, reloadForPlugin, events, TOTAL_STEPS, WORKERS_RELOAD_EVENT };
