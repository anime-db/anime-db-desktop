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

const { spawn }    = require('child_process');
const { EventEmitter } = require('events');
const fs           = require('fs');
const path         = require('path');
const paths        = require('../paths');
const phpIni       = require('../php-ini');
const { buildWebWorkerEnv } = require('./env');
const { findFreePort }    = require('./port');
const { waitForHealth }   = require('./healthcheck');
const { pruneOldLogs, openLogStream } = require('./logrotate');
const pidTracker          = require('./pid-tracker');

const events = new EventEmitter();

const BINARY = path.join(__dirname, '..', '..', 'bin', 'frankenphp', 'frankenphp.exe');
const CADDYFILE = path.join(__dirname, '..', '..', 'app', 'Caddyfile');

const LOG_PREFIX  = 'frankenphp';
const LOG_MAX     = 7;

/** Задержки backoff при перезапуске: 1s, 2s, 4s, … до 30s. */
const BACKOFF = [1000, 2000, 4000, 8000, 16000, 30000];

let child     = null;
let stopping  = false;
let port      = null;
let wsPort    = null;
let logStream = null;

/**
 * Создаёт php.ini в AppData, если его ещё нет. Подставляет системный часовой пояс вместо
 * {{TIMEZONE}} и путь к DLL расширений вместо {{EXTENSION_DIR}} — вычисляется от __dirname этого
 * модуля, поэтому сам расчёт остаётся верным после переноса каталога приложения.
 *
 * Само рендеринг+дозапись директив — в native/php-ini.js (общий код, не завязанный на Electron);
 * здесь только Electron-специфичные пути (paths.getPhpIniPath()/getPhpIniDir()) и расчёт
 * extensionDir для текущей установки.
 */
function ensurePhpIni() {
    const extensionDir = path.join(__dirname, '..', '..', 'bin', 'frankenphp', 'ext').replace(/\\/g, '/');

    phpIni.ensurePhpIni({
        iniPath: paths.getPhpIniPath(),
        iniDir:  paths.getPhpIniDir(),
        extensionDir,
    });
}

/**
 * @param {import('./env').PhpContext} context
 * @param {number} wsPort
 * @returns {NodeJS.ProcessEnv}
 */
function buildEnv(context, wsPort) {
    return buildWebWorkerEnv(context, wsPort);
}

/**
 * Запускает FrankenPHP и при падении перезапускает с backoff.
 * Если stopping === true — молча прекращает перезапуски.
 *
 * @param {import('./env').PhpContext} context
 * @param {number} wsPort
 * @param {number} backoffIdx
 */
function spawnProcess(context, wsPort, backoffIdx = 0) {
    if (stopping) return;

    fs.mkdirSync(paths.getRuntimeDir(), { recursive: true });

    child = spawn(BINARY, ['run', '--config', CADDYFILE], {
        cwd: paths.getAppRootDir(),
        env: buildEnv(context, wsPort),
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    pidTracker.writePid(LOG_PREFIX, child.pid);

    child.stdout.on('data', (d) => logStream.write(d));
    child.stderr.on('data', (d) => logStream.write(d));

    child.on('exit', (code) => {
        if (stopping) return;
        events.emit('exit', code);
        const delay = BACKOFF[Math.min(backoffIdx, BACKOFF.length - 1)];
        console.error(`[frankenphp] вышел с кодом ${code}, перезапуск через ${delay}ms`);
        setTimeout(() => spawnProcess(context, wsPort, backoffIdx + 1), delay);
    });
}

/**
 * Убивает процесс-сироту, оставленный предыдущим сеансом (см. pid-tracker.js). Должен быть
 * вызван супервизором до того, как запущен хоть один дочерний процесс текущего сеанса — иначе
 * PID, переиспользованный ОС для процесса на том же бинарнике, пройдёт проверку имени образа и
 * killOrphan() убьёт только что запущенный процесс текущего сеанса (issue #390).
 *
 * @returns {Promise<void>}
 */
function killOrphan() {
    return pidTracker.killOrphan(LOG_PREFIX, BINARY);
}

/**
 * Запускает FrankenPHP: ищет порты → создаёт php.ini → спавнит процесс → ждёт /health →
 * возвращает порты. Инвалидация устаревшего кэша скомпилированного контейнера выполняется
 * супервизором раньше — до запуска любого PHP-процесса, см. supervisor/index.js.
 *
 * @param {import('./env').PhpContext} context  общий контекст сеанса, собранный супервизором
 *                                               (issue #391) — appPort в нём ещё не задан, он
 *                                               появляется только после findFreePort() ниже
 * @param {{ port?: number, wsPort?: number }} [preferred]  желаемые порты вместо поиска с 8000 —
 *                                               используется супервизором при живом рестарте
 *                                               воркера после активации плагина (issue #411):
 *                                               окно уже открыто на старом порту
 *                                               (native/window/index.js грузит URL один раз при
 *                                               создании, без повторной навигации), и WS-клиент
 *                                               переподключается на старый _port сам
 *                                               (native/ws-client.js), поэтому рестарт обязан
 *                                               вернуться на те же порты, а не искать новые.
 *                                               findFreePort(port) сам пробует именно этот порт
 *                                               первым — он почти наверняка свободен сразу после
 *                                               await stop()
 * @returns {Promise<{ httpPort: number, wsPort: number }>}
 */
async function start(context, { port: preferredPort, wsPort: preferredWsPort } = {}) {
    stopping = false;
    ensurePhpIni();

    const logDir = path.join(paths.getRuntimeDir(), 'log');
    pruneOldLogs(logDir, LOG_PREFIX, LOG_MAX);
    logStream = openLogStream(logDir, LOG_PREFIX);

    port   = await findFreePort(preferredPort ?? 8000);
    wsPort = await findFreePort(preferredWsPort ?? (port + 1));
    spawnProcess({ ...context, appPort: port }, wsPort);
    await waitForHealth(port);
    return { httpPort: port, wsPort };
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

module.exports = { start, stop, killSync, killOrphan, buildEnv, ensurePhpIni, events };
