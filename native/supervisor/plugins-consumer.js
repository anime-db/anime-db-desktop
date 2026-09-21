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

const { spawn }                       = require('child_process');
const { EventEmitter }                = require('events');
const path                            = require('path');
const paths                           = require('../paths');
const { buildCommonEnv }              = require('./env');
const { waitForProcessAlive }         = require('./healthcheck');
const { pruneOldLogs, openLogStream } = require('./logrotate');
const pidTracker                      = require('./pid-tracker');

const events = new EventEmitter();

// FrankenPHP's embedded PHP runtime doubles as the CLI interpreter — there is no separate
// php.exe binary bundled with the app (see .claude-docs/gotchas.md).
const BINARY  = path.join(__dirname, '..', '..', 'bin', 'frankenphp', 'frankenphp.exe');
const CONSOLE = path.join(__dirname, '..', '..', 'app', 'bin', 'console');

const LOG_PREFIX = 'plugins-consumer';
const LOG_MAX     = 7;

/** Задержки backoff при перезапуске: 1s, 2s, 4s, … до 30s. */
const BACKOFF = [1000, 2000, 4000, 8000, 16000, 30000];

let child     = null;
let stopping  = false;
let logStream = null;

/**
 * @param {import('./env').PhpContext} context
 * @returns {NodeJS.ProcessEnv}
 */
function buildEnv(context) {
    return buildCommonEnv(context);
}

/**
 * Запускает consumer и при падении перезапускает с backoff — тот же шаблон, что и
 * messenger-consumer.js#spawnProcess. Один долгоживущий процесс на весь сеанс приложения — без
 * --time-limit и без периодического перезапуска по таймеру. Если stopping === true — молча
 * прекращает перезапуски.
 *
 * Отдельный процесс, а не ещё один транспорт в командной строке messenger-consumer.js (issue
 * #701): та команда потребляет свои транспорты в порядке приоритета (issue #508), где длинная
 * задача плагина заняла бы единственный воркер целиком, и PushSyncMessage не пушился бы до
 * истечения app.sync.push_on_edit_ttl_seconds. Отдельный воркер под `plugins` избавляет от этого
 * компромисса ценой того, что задачи плагинов не участвуют в приоритете `async`/`media`/
 * `scheduler_downloads_poll` — они всегда параллельны им, а не после.
 *
 * `messenger:setup-transports` для транспорта `plugins` не запускается здесь отдельно — он общий
 * с транспортами `async`/`media` (одна и та же таблица `messenger_messages`) и уже выполняется
 * перед стартом этого процесса как часть messenger-consumer.js#start (см. supervisor/index.js).
 *
 * @param {import('./env').PhpContext} context
 * @param {number} backoffIdx
 */
function spawnProcess(context, backoffIdx = 0) {
    if (stopping) return;

    child = spawn(BINARY, ['php-cli', CONSOLE, 'messenger:consume', 'plugins'], {
        cwd: paths.getAppRootDir(),
        env: buildEnv(context),
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    pidTracker.writePid(LOG_PREFIX, child.pid);

    child.stdout.on('data', (d) => logStream.write(d));
    child.stderr.on('data', (d) => logStream.write(d));

    child.on('exit', (code) => {
        if (stopping) return;
        events.emit('exit', code);
        const delay = BACKOFF[Math.min(backoffIdx, BACKOFF.length - 1)];
        console.error(`[plugins-consumer] вышел с кодом ${code}, перезапуск через ${delay}ms`);
        setTimeout(() => spawnProcess(context, backoffIdx + 1), delay);
    });
}

/**
 * Убивает процесс-сироту, оставленный предыдущим сеансом (см. pid-tracker.js). Должен быть
 * вызван супервизором до того, как запущен хоть один дочерний процесс текущего сеанса — та же
 * причина, что и у messenger-consumer.js#killOrphan: этот процесс использует тот же
 * frankenphp.exe, что и остальные (issue #390).
 *
 * @returns {Promise<void>}
 */
function killOrphan() {
    return pidTracker.killOrphan(LOG_PREFIX, BINARY);
}

/**
 * Запускает plugins-consumer: спавнит процесс → ждёт, что он не упал сразу после старта.
 * Вызывающий код (supervisor/index.js) обязан дождаться messenger-consumer.js#start раньше —
 * именно тот вызов создаёт таблицу очереди, если её ещё нет.
 *
 * @param {import('./env').PhpContext} context
 * @returns {Promise<void>}
 */
async function start(context) {
    stopping = false;

    const logDir = path.join(paths.getRuntimeDir(), 'log');
    pruneOldLogs(logDir, LOG_PREFIX, LOG_MAX);
    logStream = openLogStream(logDir, LOG_PREFIX);

    spawnProcess(context);
    await waitForProcessAlive(child);
}

/**
 * Graceful shutdown: SIGTERM → 500ms → SIGKILL.
 *
 * @returns {Promise<void>}
 */
function stop() {
    stopping = true;
    if (!child) return Promise.resolve();

    const proc = child;
    child = null;

    return new Promise((resolve) => {
        const timer = setTimeout(() => proc.kill('SIGKILL'), 500);

        proc.on('exit', () => {
            clearTimeout(timer);
            if (logStream) {
                logStream.end();
                logStream = null;
            }
            pidTracker.clearPid(LOG_PREFIX);
            resolve();
        });

        proc.kill('SIGTERM');
    });
}

/**
 * Best-effort синхронный килл на случай аварийного выхода Electron, который не проходит через
 * штатный stop() (см. process.on('exit') в lifecycle/index.js) — дождаться асинхронного
 * graceful-shutdown там уже нельзя, поэтому сразу SIGKILL.
 */
function killSync() {
    if (!child) return;
    try {
        child.kill('SIGKILL');
    } catch {
        // процесс уже завершился
    }
}

module.exports = { start, stop, killSync, killOrphan, buildEnv, events };
