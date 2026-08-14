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
const frankenphp        = require('./frankenphp');
const meilisearch       = require('./meilisearch');
const messengerConsumer = require('./messenger-consumer');
const qbittorrent       = require('./qbittorrent');

const events = new EventEmitter();
frankenphp.events.on('exit', (code) => events.emit('exit', code));
messengerConsumer.events.on('exit', (code) => events.emit('exit', code));
qbittorrent.events.on('exit', (code) => events.emit('exit', code));

/**
 * Запускает все дочерние процессы и возвращает занятые ими порты.
 * Сначала зачищаются PID-файлы всех процессов-сирот от предыдущего сеанса — до того, как
 * запущен хоть один дочерний процесс текущего сеанса. frankenphp и messenger-consumer делят один
 * и тот же бинарник (frankenphp.exe); если зачистка messenger-consumer выполнялась бы лениво,
 * внутри его собственного start() (после того как frankenphp текущего сеанса уже запущен),
 * переиспользованный ОС PID мог бы совпасть с процессом текущего сеанса и убить его (issue #390).
 * Meilisearch и qbittorrent-nox стартуют первыми (независимо друг от друга) — их
 * порты/ключи нужны FrankenPHP в env.
 *
 * @param {((step: number, text: string) => void) | undefined} onProgress
 * @returns {Promise<{ frankenphpPort: number, wsPort: number, meiliPort: number, qbittorrentPort: number }>}
 */
async function start(onProgress) {
    await Promise.all([
        frankenphp.killOrphan(),
        meilisearch.killOrphan(),
        messengerConsumer.killOrphan(),
        qbittorrent.killOrphan(),
    ]);

    const [{ port: meiliPort, key: meiliKey }, { webuiPort: qbittorrentPort }] = await Promise.all([
        meilisearch.start(),
        qbittorrent.start(),
    ]);
    if (onProgress) onProgress(1, 'Запуск FrankenPHP...');
    const { httpPort: frankenphpPort, wsPort } = await frankenphp.start(meiliPort, meiliKey, qbittorrentPort);
    if (onProgress) onProgress(2, 'Запуск обработчика фоновых задач...');
    await messengerConsumer.start(meiliPort, meiliKey);
    if (onProgress) onProgress(3, 'Готово');
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

module.exports = { start, stop, killSync, events };
