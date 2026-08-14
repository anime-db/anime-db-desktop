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
const qbittorrent       = require('./qbittorrent');
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
 * их порты/ключи нужны FrankenPHP в env. Миграции тоже участвуют в начальной зачистке сирот —
 * тот же PID-файл (см. pid-tracker.js), которым отмечается spawn консольной команды, должен быть
 * проверен и убран до старта хоть одного дочернего процесса текущего сеанса, иначе
 * переиспользованный ОС PID мог бы совпасть с процессом текущего сеанса.
 *
 * Doctrine-миграции (issue #392) прогоняются сразу после Meilisearch/qbittorrent и до старта
 * FrankenPHP — migrate не зависит от HTTP/поиска/очереди, только от DATABASE_URL, но схема
 * должна быть готова до того, как HTTP-воркер начнёт принимать запросы. На чистом профиле это
 * тот же путь: миграций ещё не применено ни одной, значит есть что применить, и migrate создаёт
 * схему с нуля. Провал (в т.ч. отказ по даунгрейду) прерывает запуск — см. MigrationBootstrapError.
 *
 * Если Meilisearch при старте вайпнул индекс из-за смены версии (issue #389), после
 * поднятия FrankenPHP и messenger-consumer автоматически прогоняется app:search:reindex —
 * без этого приложение стартует с пустым поиском до ручного нажатия кнопки в /settings.
 * Ошибка переиндексации не блокирует старт приложения — только логируется.
 *
 * @param {((step: number, total: number, text: string) => void) | undefined} onProgress
 * @returns {Promise<{ frankenphpPort: number, wsPort: number, meiliPort: number, qbittorrentPort: number }>}
 */
async function start(onProgress) {
    await Promise.all([
        frankenphp.killOrphan(),
        meilisearch.killOrphan(),
        messengerConsumer.killOrphan(),
        migrations.killOrphan(),
        qbittorrent.killOrphan(),
    ]);

    if (cacheInvalidation.hasBuildChanged()) {
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
    const phpContext = { qbittorrentPort, meiliPort, meiliKey };

    if (onProgress) onProgress(1, TOTAL_STEPS, 'Применение миграций...');
    await migrations.run(phpContext);

    if (onProgress) onProgress(2, TOTAL_STEPS, 'Запуск FrankenPHP...');
    const { httpPort: frankenphpPort, wsPort } = await frankenphp.start(meiliPort, meiliKey, qbittorrentPort);
    if (onProgress) onProgress(3, TOTAL_STEPS, 'Запуск обработчика фоновых задач...');

    // Тот же контекст, что у миграций, плюс порт поднятого веб-воркера — см. env.js.
    const workerContext = { ...phpContext, appPort: frankenphpPort };

    await messengerConsumer.start(workerContext);

    if (wiped) {
        if (onProgress) onProgress(4, TOTAL_STEPS, 'Обновление поискового индекса...');
        try {
            await searchReindex.run(workerContext);
        } catch (err) {
            console.error('[search-reindex] не удалось переиндексировать каталог:', err.message);
        }
    }

    if (onProgress) onProgress(TOTAL_STEPS, TOTAL_STEPS, 'Готово');

    cacheInvalidation.commitFingerprint();

    return { frankenphpPort, wsPort, meiliPort, qbittorrentPort };
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

module.exports = { start, stop, killSync, events, TOTAL_STEPS };
