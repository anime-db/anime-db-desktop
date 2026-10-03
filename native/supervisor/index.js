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
const downloadsPoll     = require('./downloads-poll');
const frankenphp        = require('./frankenphp');
const importApply       = require('./import-apply');
const marketRefresh     = require('./market-refresh');
const meilisearch       = require('./meilisearch');
const messengerConsumer = require('./messenger-consumer');
const migrations        = require('./migrations');
const oauthCallback     = require('./oauth-callback');
const phpCommand        = require('./php-command');
const pluginReconcile   = require('./plugin-reconcile');
const pluginsConsumer   = require('./plugins-consumer');
const qbittorrent       = require('./qbittorrent');
const safeModeState     = require('./safe-mode');
const searchReindex     = require('./search-reindex');
const stagedImport      = require('./staged-import');

const events = new EventEmitter();
frankenphp.events.on('exit', (code) => events.emit('exit', code));
messengerConsumer.events.on('exit', (code) => events.emit('exit', code));
pluginsConsumer.events.on('exit', (code) => events.emit('exit', code));
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
 * The port FrankenPHP currently listens on, or null while it is down (startup, in the middle of
 * reloadForPlugin's restart). Read by oauth-callback.js's request handler on every request (issue
 * #871) — not cached there — so a GET /oauth/* always redirects to whatever port is live *right
 * now*, including across a reloadForPlugin() restart that happens to land on a different port.
 *
 * @type {number | null}
 */
let currentFrankenphpPort = null;

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
 * запущен хоть один дочерний процесс текущего сеанса. frankenphp, messenger-consumer и
 * plugins-consumer (issue #701) делят один и тот же бинарник (frankenphp.exe); если зачистка
 * messenger-consumer/plugins-consumer выполнялась бы лениво, внутри их собственного start()
 * (после того как frankenphp текущего сеанса уже запущен), переиспользованный ОС PID мог бы
 * совпасть с процессом текущего сеанса и убить его (issue #390).
 * Следом, там же и по той же причине "до первого PHP-процесса" — инвалидация устаревшего
 * скомпилированного контейнера Symfony (issue #386): и frankenphp, и messenger-consumer/
 * plugins-consumer бутуют одно и то же ядро, а APP_ENV=prod не проверяет свежесть ConfigCache
 * сам, поэтому первый же бут
 * против устаревшего дампа запекает его *.bundles.php для всех последующих. Отпечаток сборки
 * фиксируется отдельно и только после успешного старта всех процессов (см. commitFingerprint
 * ниже) — если бы он писался заранее, падение где-то в середине старта считало бы апгрейд уже
 * обработанным. Ошибка инвалидации не перехватывается: пусть прервёт запуск и попадёт в лог
 * (native/crash-log.js) через catch в lifecycle/index.js, а не тихо продолжит работу против
 * устаревшего кэша. Meilisearch и qbittorrent-nox стартуют первыми (независимо друг от друга) —
 * их порты/ключи нужны FrankenPHP в env. Миграции и разовые консольные вызовы
 * (messenger:setup-transports, app:search:reindex, app:plugin:reconcile, app:catalog:export —
 * все идут через php-command.js, см. issue #400) тоже участвуют в начальной зачистке сирот — тот
 * же PID-файл (см. pid-tracker.js), которым отмечается spawn консольной команды, должен быть
 * проверен и убран до старта хоть одного дочернего процесса текущего сеанса, иначе
 * переиспользованный ОС PID мог бы совпасть с процессом текущего сеанса. app:catalog:export
 * (issue #657) не участвует в самой последовательности старта — он запускается по требованию
 * из native/catalog-export/index.js, — но сирота от прошлого сеанса всё равно должен быть убран
 * здесь же, до первого дочернего процесса текущего.
 *
 * `app:downloads:poll` (issue #685) участвует в той же зачистке сирот, что и остальные разовые
 * консольные вызовы ниже, хотя запускается позже — после messenger-consumer, не блокируя splash
 * (см. комментарий у самого вызова).
 *
 * `app:import:staged-status` (issue #706, staged-import.js) — тот же принцип: запускается сразу
 * после миграций, но зачистка сироты этого одноразового вызова здесь же, до первого дочернего
 * процесса текущего сеанса.
 *
 * `app:queue:purge` (issue #707, import-apply.js) — тот же принцип: запускается только на APPLY-
 * вердикте импорта, до старта FrankenPHP, но зачистка сироты этого одноразового вызова здесь же.
 *
 * Doctrine-миграции (issue #392) прогоняются сразу после Meilisearch/qbittorrent и до старта
 * FrankenPHP — migrate не зависит от HTTP/поиска/очереди, только от DATABASE_URL, но схема
 * должна быть готова до того, как HTTP-воркер начнёт принимать запросы. На чистом профиле это
 * тот же путь: миграций ещё не применено ни одной, значит есть что применить, и migrate создаёт
 * схему с нуля. Провал (в т.ч. отказ по даунгрейду) прерывает запуск — см. MigrationBootstrapError.
 *
 * Если Meilisearch при старте вайпнул индекс из-за смены версии (issue #389), или если
 * migrations.run() реально применил хотя бы одну миграцию (issue #402 — Doctrine-миграции
 * меняют data.db сырым SQL в обход ORM-слушателей, которые диспатчат индексирующие сообщения,
 * поэтому без этого индекс молча расходится с каталогом), или если native/backup-restore/index.js
 * пометил (searchReindex.markRequired(), issue #681) необходимость переиндексации перед своим
 * relaunch — восстановленный снимок может отличаться от каталога, под который собран текущий
 * индекс, даже когда схема БД та же и оба предыдущих сигнала молчат, — после поднятия FrankenPHP и
 * messenger-consumer автоматически прогоняется app:search:reindex — без этого приложение либо
 * стартует с пустым поиском, либо каталог расходится с индексом до ручного нажатия кнопки в
 * /settings. Ошибка переиндексации не блокирует старт приложения — только логируется.
 *
 * @param {((step: number, total: number, translationKey: string) => void) | undefined} onProgress
 *   translationKey is a native/translations/ key, not display text — the caller (native/lifecycle)
 *   resolves it for the current locale (issue #404).
 * @param {{ safeMode?: boolean, confirmStagedImport?: (info: { stagedAt: string, sourceArchive: string }) => (Promise<boolean> | boolean) }} [options]
 *   safeMode (issue #403) — набор незакрытых стартов подряд, отслеживаемый
 *   native/lifecycle/index.js, попадает сюда как уже принятое пользователем решение.
 *   confirmStagedImport (issue #706) — колбэк-подтверждение для staging старше 24 часов
 *   (см. staged-import.js#decide); супервизор не должен знать про `dialog`, поэтому саму реализацию
 *   передаёт native/lifecycle/index.js, а в тестах подменяется стабом.
 * @returns {Promise<{ frankenphpPort: number, wsPort: number, meiliPort: number, qbittorrentPort: number, importFailed: boolean }>}
 *   importFailed (issue #707) — true when a staged import was applied but had to be rolled back;
 *   the app still finishes starting on the restored catalog, lifecycle/index.js just surfaces this
 *   to the user once the window exists.
 */
async function start(onProgress, { safeMode = false, confirmStagedImport } = {}) {
    await Promise.all([
        frankenphp.killOrphan(),
        meilisearch.killOrphan(),
        messengerConsumer.killOrphan(),
        migrations.killOrphan(),
        phpCommand.killOrphan('app:market:refresh'),
        phpCommand.killOrphan('messenger:setup-transports'),
        phpCommand.killOrphan('app:search:reindex'),
        phpCommand.killOrphan('app:plugin:reconcile'),
        phpCommand.killOrphan('app:catalog:export'),
        phpCommand.killOrphan('app:downloads:poll'),
        phpCommand.killOrphan('app:queue:purge'),
        pluginsConsumer.killOrphan(),
        qbittorrent.killOrphan(),
        stagedImport.killOrphan(),
    ]);

    // Смена SAFE_MODE между запусками требует того же вайпа, что и смена сборки (issue #386) —
    // см. safe-mode.js#hasModeChanged: набор бандлов плагинов запекается в скомпилированный
    // контейнер, и без вайпа переключение режима не даст эффекта или "залипнет" после выхода.
    const buildChanged = cacheInvalidation.hasBuildChanged();
    if (buildChanged || safeModeState.hasModeChanged(safeMode)) {
        cacheInvalidation.invalidateCache();
    }

    // Снимок маркета помечен CORE_VERSION, а не отпечатком сборки — смена SAFE_MODE в одиночку
    // его не портит, только реальный апдейт приложения (issue #440, epic #435 decision №6).
    if (buildChanged) {
        cacheInvalidation.invalidateMarketSnapshot();
    }

    const [{ port: meiliPort, key: meiliKey, wiped }, { webuiPort: qbittorrentPort }] = await Promise.all([
        meilisearch.start(),
        qbittorrent.start(),
    ]);

    // Постоянный OAuth-редирект-слушатель (issue #871) обязан быть поднят ДО первого PHP-процесса
    // сеанса — `$_SERVER['OAUTH_CALLBACK_ORIGIN']` фиксируется при старте PHP-процесса, а первым
    // идёт не веб-воркер, а миграции, см. phpContext ниже. Порт занят другой программой — не
    // фатально: приложение стартует как раньше, просто без фиксированного порта для OAuth, см.
    // buildCommonEnv()'s fallback в env.js.
    currentFrankenphpPort = null;
    const oauthCallbackBind = await oauthCallback.start(() => currentFrankenphpPort);
    const oauthCallbackFixedPort = oauthCallbackBind.ok;
    if (!oauthCallbackBind.ok) {
        console.error(
            `[supervisor] не удалось занять порт ${oauthCallback.OAUTH_FIXED_PORT} под фиксированный OAuth-редирект, используется порт веб-воркера как раньше:`,
            oauthCallbackBind.error.message,
        );
    }

    // Один контекст на все PHP-процессы сеанса — см. env.js: набор путей и портов у них обязан
    // совпадать, поэтому он собирается здесь один раз, а не по месту каждым модулем. Миграции
    // стартуют до веб-воркера, поэтому appPort на этот момент ещё не существует — в PhpContext он
    // опционален (см. env.js). oauthCallbackOrigin/oauthCallbackFixedPort, в отличие от appPort,
    // известны уже сейчас (bind выше), поэтому в их окружение попадают даже сейчас, до
    // веб-воркера — если только bind не ушёл в фолбэк, см. env.js#buildCommonEnv.
    const phpContext = {
        qbittorrentPort, meiliPort, meiliKey, safeMode, oauthCallbackFixedPort,
        ...(oauthCallbackFixedPort ? { oauthCallbackOrigin: `http://127.0.0.1:${oauthCallback.OAUTH_FIXED_PORT}` } : {}),
    };

    // php.ini обязан существовать ДО первого PHP-процесса сеанса, а первым идут миграции, а не
    // веб-воркер. Расширения в Windows-сборке — подгружаемые DLL (issue #477), путь к ним даёт
    // только extension_dir из php.ini, и без него боевой интерпретатор стартует вообще без
    // расширений: bin/console падает на vendor/composer/platform_check.php с «require the
    // following PHP extensions: gd, intl, openssl, pdo_sqlite, zip» ещё до первой строки Symfony.
    //
    // Раньше этот вызов жил только внутри frankenphp.start(), то есть на шаге 2 — на ЧИСТОМ
    // профиле миграции шага 1 успевали отработать раньше, чем появлялся php.ini, и весь запуск
    // ложился (issue #552). На втором и последующих запусках файл уже лежал от прошлого сеанса,
    // поэтому отказ был строго первозапускным и в разработке не воспроизводился.
    //
    // Вызов идемпотентен (см. native/php-ini.js#ensurePhpIni), и собственный вызов внутри
    // frankenphp.start() намеренно оставлен: он держит start() самодостаточным для пути
    // перезапуска воркера (reloadForPlugin), где через супервизор сюда никто не заходит.
    frankenphp.ensurePhpIni();

    if (onProgress) onProgress(1, TOTAL_STEPS, 'splash.step_migrations');
    const migrationsApplied = await migrations.run(phpContext);

    // Decides whether a staged catalog import (App\Service\Import\CatalogStageService, issue #669)
    // may be applied — same place as the migrations/schema-downgrade guard above, and for the same
    // reason: whatever happens next must not swap in a database this build cannot open. This is
    // only the decision (issue #706); the actual swap runs immediately below via import-apply.js
    // (issue #707) on an APPLY verdict, still before frankenphp.start(). A SKIP/REJECT verdict
    // never touches data.db, media/, or the working database's own migration guard above — it only
    // ever reads/removes import-staging/, via a staged copy of the schema check
    // (migrations.js#checkDumpSchema) pointed at the staged file, not the working one.
    const stagedImportDecision = await stagedImport.decide(phpContext, confirmStagedImport);

    // Runs the whole swap-and-bootstrap sequence (issue #707) here, before frankenphp.start() —
    // the only point where replacing data.db out from under the app is safe (see import-apply.js
    // for why, and for the load-bearing ordering of its own steps). A failed import is rolled back
    // internally and does not throw: importFailed just tells lifecycle/index.js to surface it to
    // the user once the window exists, the app otherwise finishing startup on the restored catalog
    // exactly as if nothing had been staged at all (acceptance criterion 6).
    let importFailed = false;
    if (stagedImportDecision.verdict === stagedImport.Verdict.APPLY) {
        if (onProgress) onProgress(1, TOTAL_STEPS, 'splash.step_import');
        const importResult = await importApply.apply(phpContext);
        importFailed = !importResult.applied;
    }

    // Rebuilds installed-plugins.php against the current build's manifest validation, same
    // buildChanged condition as the cache/market-snapshot invalidation above (issue #575) — an
    // index entry a previous, more permissive build accepted otherwise survives the upgrade
    // as-is, since readIndex() below never re-validates a manifest, only parses the pre-built
    // index. Must run before frankenphp.start(): the first request into the new worker already
    // reads the index, so reconciling after that point would let it observe the stale one. A
    // failure here is logged and does not block startup — the index is simply left as it was,
    // the same state as today, not deleted (readIndex() treats a missing file as "no plugins
    // installed").
    if (buildChanged) {
        try {
            await pluginReconcile.run(phpContext);
        } catch (err) {
            console.error('[plugin-reconcile] не удалось пересобрать индекс установленных плагинов:', err.message);
        }
    }

    if (onProgress) onProgress(2, TOTAL_STEPS, 'splash.step_frankenphp');
    const { httpPort: frankenphpPort, wsPort } = await frankenphp.start(phpContext);
    currentFrankenphpPort = frankenphpPort;
    if (onProgress) onProgress(3, TOTAL_STEPS, 'splash.step_messenger');

    // Тот же контекст, что у миграций, плюс порт поднятого веб-воркера — см. env.js.
    const workerContext = { ...phpContext, appPort: frankenphpPort };

    await messengerConsumer.start(workerContext);

    // Starts alongside messenger-consumer, under the same splash step above (issue #701, part 1
    // of #684) — a second long-lived `messenger:consume` worker dedicated to the `plugins`
    // transport (see app/config/packages/messenger.yaml), so a long-running plugin task can never
    // occupy the same worker `PushSyncMessage` depends on. Must start after messengerConsumer, not
    // before or in parallel with it: messenger-consumer.js#start is what runs
    // `messenger:setup-transports` and creates the shared queue table if it doesn't exist yet.
    await pluginsConsumer.start(workerContext);

    // Fired and forgotten, not awaited, same rationale as marketRefresh below (issue #685): a
    // download that finished while the app was closed must be linked without the appearing
    // window waiting on it. See downloads-poll.js for why this startup run is the only thing that
    // ever covers that case — App\Scheduler\DownloadsPollSchedule's own tick, running inside the
    // messenger-consumer process just started above, does not fire until its own interval elapses.
    downloadsPoll.run(workerContext).catch((err) => {
        console.error('[downloads-poll] не удалось выполнить стартовый прогон поллера загрузок:', err.message);
    });

    // Fired and forgotten, not awaited (issue #440, epic #435 decision №5): a market refresh is a
    // network fetch of the plugin registry (up to market-refresh.js's own TIMEOUT_MS), and the
    // splash/window must not wait on it the way it does wait on migrations/FrankenPHP/messenger
    // above. Any failure (offline, unreachable mirror) is just logged — the storefront's own
    // render-fallback (MarketController, issue #440) and the existing cached snapshot, if any,
    // cover the user-facing side.
    marketRefresh.run(workerContext).catch((err) => {
        console.error('[market-refresh] не удалось обновить снимок маркета:', err.message);
    });

    // Consumed unconditionally, before the wiped/migrationsApplied check below, so the marker
    // native/backup-restore/index.js sets ahead of a relaunch is always cleared on the very next
    // start — short-circuiting it behind `||` would skip consumeRequired() whenever wiped or
    // migrationsApplied already forces a reindex, leaving the marker to force a second, redundant
    // one on some later start.
    const reindexRequired = searchReindex.consumeRequired();
    if (wiped || migrationsApplied || reindexRequired) {
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

    return { frankenphpPort, wsPort, meiliPort, qbittorrentPort, importFailed };
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
 * Invalidates the real compiled-container cache and restarts FrankenPHP + messenger-consumer +
 * plugins-consumer (issue #701) in place, on the same ports (see frankenphp.js#start's
 * `preferred` param) so the already-open window and the reconnecting WsClient are unaffected.
 *
 * If the restarted worker never becomes healthy, all three processes' own crash-loop backoff
 * (frankenphp.js/messenger-consumer.js/plugins-consumer.js `spawnProcess`) would otherwise keep
 * retrying against the same broken plugin forever — stop() is called again here specifically to
 * break that loop (its `stopping` guard prevents any backoff respawn already scheduled from
 * firing) before rolling back: remove the plugin via the `app:plugin:deactivate` console command
 * (issue #411's rollback requirement) and restart clean, into the pre-plugin state. Every step
 * from here on is best-effort and swallows its own errors — this function must never leave the
 * supervisor's crash-loop backoff to fight a plugin that is already known to be broken, and must
 * never reject (its caller is a fire-and-forget WS event handler with nothing better to do than
 * log).
 *
 * `frankenphp.start()`/`messengerConsumer.start()`/`pluginsConsumer.start()` flip their own
 * `stopping` flag back to false as soon as they're called (see frankenphp.js#start), so if the
 * rollback restart itself fails (e.g. `app:plugin:deactivate` couldn't remove a locked plugin and
 * the pre-plugin process never becomes healthy either), the crash-loop backoff that `start()`
 * just armed is left running with `stopping === false` — the exact loop this function exists to
 * prevent, now fighting a state that's already known to be unrecoverable. The outer catch below
 * calls stop() on all three again (idempotent — see frankenphp.js#stop) so any respawn timer
 * already scheduled sees `stopping === true` and no-ops instead of firing (issue #424).
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
        await Promise.all([messengerConsumer.stop(), pluginsConsumer.stop()]);
        await frankenphp.stop();
        currentFrankenphpPort = null;

        const started = await frankenphp.start(phpContext, { port: frankenphpPort, wsPort });
        currentFrankenphpPort = started.httpPort;
        liveContext = { phpContext, frankenphpPort: started.httpPort, wsPort: started.wsPort };
        await messengerConsumer.start({ ...phpContext, appPort: started.httpPort });
        await pluginsConsumer.start({ ...phpContext, appPort: started.httpPort });

        events.emit('plugin-activated', { pluginId });
        return;
    } catch (err) {
        console.error(`[supervisor] активация плагина "${pluginId}" не удалась, откат:`, err.message);
    }

    try {
        await frankenphp.stop();
        currentFrankenphpPort = null;
        await Promise.all([messengerConsumer.stop(), pluginsConsumer.stop()]);

        try {
            await phpCommand.run('app:plugin:deactivate', [pluginId], phpContext, PLUGIN_DEACTIVATE_TIMEOUT_MS);
        } catch (removeErr) {
            console.error(`[supervisor] не удалось убрать плагин "${pluginId}" из реестра:`, removeErr.message);
        }

        cacheInvalidation.invalidateCache();

        const restarted = await frankenphp.start(phpContext, { port: frankenphpPort, wsPort });
        currentFrankenphpPort = restarted.httpPort;
        liveContext = { phpContext, frankenphpPort: restarted.httpPort, wsPort: restarted.wsPort };
        await messengerConsumer.start({ ...phpContext, appPort: restarted.httpPort });
        await pluginsConsumer.start({ ...phpContext, appPort: restarted.httpPort });
    } catch (rollbackErr) {
        console.error('[supervisor] откат после неудачной активации плагина тоже не удался:', rollbackErr.message);

        // Откатный start() выше мог успеть выставить stopping=false и заспавнить процесс до
        // своего падения — без этого их backoff-респаун (setTimeout(spawnProcess, …)) продолжил
        // бы бесконечно перезапускаться против заведомо битого состояния (issue #424).
        await Promise.all([frankenphp.stop(), messengerConsumer.stop(), pluginsConsumer.stop()]);
        console.error('[supervisor] процессы переведены в stopping — backoff-респаун остановлен.');
    }

    events.emit('plugin-activation-failed', { pluginId });
}

/**
 * Останавливает все дочерние процессы в правильном порядке:
 * сначала messenger-consumer, plugins-consumer (issue #701) и FrankenPHP (нет новых запросов и
 * задач), затем Meilisearch и qbittorrent-nox.
 *
 * @returns {Promise<void>}
 */
async function stop() {
    await Promise.all([messengerConsumer.stop(), pluginsConsumer.stop()]);
    await frankenphp.stop();
    currentFrankenphpPort = null;
    await Promise.all([meilisearch.stop(), qbittorrent.stop()]);
    await oauthCallback.stop();
}

/**
 * Best-effort синхронный килл всех дочерних процессов на случай аварийного выхода Electron,
 * который не проходит через штатный stop() (см. process.on('exit') в lifecycle/index.js) —
 * дождаться асинхронного graceful-shutdown там уже нельзя.
 */
function killSync() {
    messengerConsumer.killSync();
    pluginsConsumer.killSync();
    frankenphp.killSync();
    meilisearch.killSync();
    qbittorrent.killSync();
    oauthCallback.killSync();
}

/**
 * Snapshot of the running session's PhpContext (with appPort) for one-off console calls
 * triggered from outside start() itself — e.g. native/catalog-export/index.js (issue #657), which
 * spawns `app:catalog:export` on demand from an IPC handler rather than at a fixed point in the
 * startup sequence. Returns null before start() has finished (liveContext not set yet) so a
 * caller fails cleanly instead of spawning a process with an incomplete env.
 *
 * @returns {(import('./env').PhpContext & { appPort: number }) | null}
 */
function getWorkerContext() {
    return liveContext ? { ...liveContext.phpContext, appPort: liveContext.frankenphpPort } : null;
}

module.exports = { start, stop, killSync, reloadForPlugin, getWorkerContext, events, TOTAL_STEPS, WORKERS_RELOAD_EVENT };
