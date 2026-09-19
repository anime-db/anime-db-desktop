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
 * аргументов: три из четырёх полей — числа-порты, и при позиционной передаче их перестановка
 * никак не проявляется ни в линте, ни в типах, а ломает только рантайм.
 *
 * @typedef {object} PhpContext
 * @property {number} [appPort]        порт веб-воркера FrankenPHP; нужен для
 *                                      OAUTH_CALLBACK_ORIGIN даже процессам, которые сами
 *                                      HTTP не поднимают. Опционален: миграции схемы стартуют
 *                                      ДО веб-воркера (issue #392), и на тот момент порта ещё
 *                                      не существует — тогда OAUTH_CALLBACK_ORIGIN в env не
 *                                      попадает вовсе. Отсутствие ключа безопасно: значение
 *                                      резолвится Symfony лениво, при обращении, а консольные
 *                                      команды схемы к OAuth не обращаются
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
function buildCommonEnv({ meiliPort, meiliKey, qbittorrentPort, appPort, safeMode }) {
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
        CONFIG_PATH:             paths.getConfigPath(),
        PLUGINS_CONFIG_PATH:     paths.getPluginsConfigPath(),
        PLUGINS_DIR:             paths.getPluginsDir(),
        NATIVE_TRANSLATIONS_DIR:         paths.getNativeTranslationsDir(),
        NATIVE_TRANSLATIONS_OVERLAY_DIR: paths.getNativeTranslationsOverlayDir(),
        MARKET_REGISTRY_CACHE_PATH: paths.getMarketRegistryCachePath(),
        MARKET_SNAPSHOT_CACHE_PATH: paths.getMarketSnapshotCachePath(),
        MARKET_REFRESH_LOCK_PATH: paths.getMarketRefreshLockPath(),
        MEILISEARCH_URL:         `http://127.0.0.1:${meiliPort}`,
        MEILISEARCH_KEY:         meiliKey,
        QBITTORRENT_URL:         `http://127.0.0.1:${qbittorrentPort}`,
        // Только для процессов, стартующих после веб-воркера — см. PhpContext.appPort.
        ...(appPort === undefined ? {} : { OAUTH_CALLBACK_ORIGIN: `http://127.0.0.1:${appPort}` }),
        ...(safeMode ? { SAFE_MODE: '1' } : {}),
    };
}

/**
 * Общий env плюс переменные, осмысленные только для веб-воркера FrankenPHP (HTTP/WS-сервер).
 * Контекстно-зависимые переменные добавляются явно, а не молчаливо отсутствуют в env
 * консольных/фоновых процессов.
 *
 * `APP_PORT` берётся из того же `context.appPort`, что и `OAUTH_CALLBACK_ORIGIN` в
 * buildCommonEnv() — отдельным параметром его не принимаем, иначе два значения одного и того же
 * порта могли бы разъехаться.
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
