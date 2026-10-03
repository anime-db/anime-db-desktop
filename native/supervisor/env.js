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

const { app } = require('electron');
const paths = require('../paths');
const { getOrCreateAppSecret } = require('../config');

/**
 * Общий контекст сеанса, от которого зависит окружение любого PHP-процесса. Собирается один раз
 * в supervisor/index.js и передаётся дальше целиком — именно объектом, а не набором позиционных
 * аргументов: несколько полей — числа-порты, и при позиционной передаче их перестановка
 * никак не проявляется ни в линте, ни в типах, а ломает только рантайм.
 *
 * @typedef {object} PhpContext
 * @property {number} [appPort]        порт веб-воркера FrankenPHP. Используется напрямую только
 *                                      для APP_PORT веб-воркера (buildWebWorkerEnv). С issue #871
 *                                      НЕ является больше источником OAUTH_CALLBACK_ORIGIN, кроме
 *                                      как в фолбэке — см. oauthCallbackOrigin ниже. Опционален:
 *                                      миграции схемы стартуют ДО веб-воркера (issue #392), и на
 *                                      тот момент порта ещё не существует
 * @property {string} [oauthCallbackOrigin]  origin для OAuth-редиректа, вычисленный один раз при
 *                                      bind постоянного слушателя native/supervisor/oauth-callback.js
 *                                      (issue #871) — `http://127.0.0.1:41813` при успешном bind.
 *                                      Присутствует во ВСЕХ процессах сеанса, включая стартующие
 *                                      до веб-воркера (миграции и т.д.), раз оно известно ещё до
 *                                      первого PHP-процесса. Если порт 41813 занят другой
 *                                      программой, это поле не задаётся вовсе — buildCommonEnv()
 *                                      тогда откатывается к старому поведению: берёт
 *                                      OAUTH_CALLBACK_ORIGIN из context.appPort, то есть всё ещё
 *                                      отсутствует у процессов, стартующих до веб-воркера
 * @property {boolean} [oauthCallbackFixedPort]  true — слушатель на 41813 поднят, false — фолбэк
 *                                      активен (порт занят). Задаётся один раз при bind и попадает
 *                                      во ВСЕ процессы как OAUTH_CALLBACK_FIXED_PORT ('1'/'0'),
 *                                      независимо от того, попало ли в этот же процесс
 *                                      OAUTH_CALLBACK_ORIGIN — страница настроек плагина
 *                                      (PluginSettingsController) показывает предупреждение именно
 *                                      по этому флагу, не называя источник
 * @property {number} qbittorrentPort  WebUI-порт qbittorrent-nox
 * @property {number} meiliPort        порт Meilisearch
 * @property {string} meiliKey         master-key Meilisearch
 * @property {boolean} [safeMode]      true — ядро стартует в safe mode (issue #403): в env
 *                                      попадает SAFE_MODE=1, из-за которого
 *                                      Kernel::installedPluginsRegistry() отдаёт пустой список
 *                                      плагинов, не обращаясь к их реестру
 */

/**
 * Единственный источник истины для набора переменных окружения, общих для ВСЕХ дочерних
 * PHP-процессов приложения — FrankenPHP, обработчик фоновых задач (messenger-consumer),
 * разовая переиндексация (search-reindex) и любые будущие консольные вызовы (например,
 * doctrine:migrations:migrate, messenger:setup-transports).
 * Каждый такой процесс обязан получать идентичный набор путей к пользовательским данным — новый
 * спавн PHP не должен собирать env самостоятельно, а должен использовать этот модуль.
 *
 * @param {PhpContext} context
 * @returns {NodeJS.ProcessEnv}
 */
function buildCommonEnv({ meiliPort, meiliKey, qbittorrentPort, appPort, oauthCallbackOrigin, oauthCallbackFixedPort, safeMode }) {
    // oauthCallbackOrigin wins when set (fixed-port listener bound, issue #871) — it is known
    // before any PHP process starts, so it is identical for every process of the session. The
    // fallback (deriving from appPort) is only reached when the fixed port was unavailable, and
    // reproduces the pre-#871 behavior exactly, including being absent for processes that start
    // before the web worker (appPort undefined at that point).
    const oauthCallbackOriginResolved = oauthCallbackOrigin
        ?? (appPort === undefined ? undefined : `http://127.0.0.1:${appPort}`);

    return {
        ...process.env,
        APP_ROOT:                paths.getAppRootDir(),
        APP_ENV:                 'prod',
        APP_SECRET:              getOrCreateAppSecret(),
        CORE_VERSION:            app.getVersion(),
        DATABASE_URL:            `sqlite:///${paths.getDbPath()}`,
        QUEUE_DATABASE_URL:      `sqlite:///${paths.getQueueDbPath()}`,
        MESSENGER_TRANSPORT_DSN: 'doctrine://queue?auto_setup=0',
        PHPRC:                   paths.getPhpIniDir(),
        APP_RUNTIME_DIR:         paths.getRuntimeDir(),
        MEDIA_DIR:               paths.getMediaDir(),
        IMPORT_STAGING_DIR:      paths.getImportStagingDir(),
        IMPORT_REJECTION_PATH:   paths.getImportRejectionPath(),
        IMPORT_APPLIED_PATH:     paths.getImportAppliedPath(),
        CONFIG_PATH:             paths.getConfigPath(),
        PLUGINS_CONFIG_PATH:     paths.getPluginsConfigPath(),
        PLUGINS_DIR:             paths.getPluginsDir(),
        NATIVE_TRANSLATIONS_DIR:         paths.getNativeTranslationsDir(),
        NATIVE_TRANSLATIONS_OVERLAY_DIR: paths.getNativeTranslationsOverlayDir(),
        BACKUPS_DIR:             paths.getBackupsDir(),
        MARKET_REGISTRY_CACHE_PATH: paths.getMarketRegistryCachePath(),
        MARKET_SNAPSHOT_CACHE_PATH: paths.getMarketSnapshotCachePath(),
        MARKET_REFRESH_LOCK_PATH: paths.getMarketRefreshLockPath(),
        MEILISEARCH_URL:         `http://127.0.0.1:${meiliPort}`,
        MEILISEARCH_KEY:         meiliKey,
        QBITTORRENT_URL:         `http://127.0.0.1:${qbittorrentPort}`,
        FFPROBE_BIN:             paths.getFfprobeBinPath(),
        ...(oauthCallbackOriginResolved === undefined ? {} : { OAUTH_CALLBACK_ORIGIN: oauthCallbackOriginResolved }),
        ...(oauthCallbackFixedPort === undefined ? {} : { OAUTH_CALLBACK_FIXED_PORT: oauthCallbackFixedPort ? '1' : '0' }),
        ...(safeMode ? { SAFE_MODE: '1' } : {}),
    };
}

/**
 * Общий env плюс переменные, осмысленные только для веб-воркера FrankenPHP (HTTP/WS-сервер).
 * Контекстно-зависимые переменные добавляются явно, а не молчаливо отсутствуют в env
 * консольных/фоновых процессов.
 *
 * `APP_PORT` берётся из `context.appPort` — порта самого FrankenPHP. С issue #871
 * `OAUTH_CALLBACK_ORIGIN` в buildCommonEnv() больше НЕ привязан к этому же `appPort`: при успешном
 * bind постоянного слушателя (native/supervisor/oauth-callback.js) origin — порт 41813, а не порт
 * веб-воркера, и оба значения расходятся намеренно. Только в фолбэке (порт 41813 занят)
 * OAUTH_CALLBACK_ORIGIN снова берётся из этого же `context.appPort`, как было до issue #871.
 *
 * @param {PhpContext & { appPort: number }} context  для веб-воркера appPort обязателен
 * @param {number} wsPort  порт WebSocket-сервера; поднимает его только веб-воркер
 * @returns {NodeJS.ProcessEnv}
 */
function buildWebWorkerEnv(context, wsPort) {
    return {
        ...buildCommonEnv(context),
        APP_PORT: String(context.appPort),
        WS_PORT:  String(wsPort),
    };
}

module.exports = { buildCommonEnv, buildWebWorkerEnv };
