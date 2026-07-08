/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

'use strict';

const { spawn }    = require('child_process');
const { EventEmitter } = require('events');
const path         = require('path');
const paths        = require('../paths');
const { getOrCreateAppSecret } = require('../config');
const { waitForProcessAlive }  = require('./healthcheck');
const { pruneOldLogs, openLogStream } = require('./logrotate');

const events = new EventEmitter();

// FrankenPHP's embedded PHP runtime doubles as the CLI interpreter — there is no separate
// php.exe binary bundled with the app (see .claude-docs/gotchas.md).
const BINARY  = path.join(__dirname, '..', '..', 'bin', 'frankenphp', 'frankenphp.exe');
const CONSOLE = path.join(__dirname, '..', '..', 'app', 'bin', 'console');

const LOG_PREFIX = 'messenger-consumer';
const LOG_MAX     = 7;

/** Задержки backoff при перезапуске: 1s, 2s, 4s, … до 30s. */
const BACKOFF = [1000, 2000, 4000, 8000, 16000, 30000];

let child     = null;
let stopping  = false;
let logStream = null;

function buildEnv(meiliPort, meiliKey) {
    return {
        ...process.env,
        APP_ROOT:                paths.getAppRootDir(),
        APP_ENV:                 'prod',
        APP_SECRET:              getOrCreateAppSecret(),
        DATABASE_URL:            `sqlite:///${paths.getDbPath()}`,
        QUEUE_DATABASE_URL:      `sqlite:///${paths.getQueueDbPath()}`,
        MESSENGER_TRANSPORT_DSN: 'doctrine://queue?auto_setup=0',
        PHPRC:                   paths.getPhpIniDir(),
        APP_RUNTIME_DIR:         paths.getRuntimeDir(),
        MEDIA_DIR:               paths.getMediaDir(),
        CONFIG_PATH:             paths.getConfigPath(),
        MEILISEARCH_URL:         `http://127.0.0.1:${meiliPort}`,
        MEILISEARCH_KEY:         meiliKey,
    };
}

/**
 * Запускает consumer и при падении перезапускает с backoff. Один долгоживущий процесс на
 * весь сеанс приложения — без --time-limit и без периодического перезапуска по таймеру.
 * Если stopping === true — молча прекращает перезапуски.
 */
function spawnProcess(meiliPort, meiliKey, backoffIdx = 0) {
    if (stopping) return;

    child = spawn(BINARY, ['php-cli', CONSOLE, 'messenger:consume', 'async'], {
        cwd: paths.getAppRootDir(),
        env: buildEnv(meiliPort, meiliKey),
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    child.stdout.on('data', (d) => logStream.write(d));
    child.stderr.on('data', (d) => logStream.write(d));

    child.on('exit', (code) => {
        if (stopping) return;
        events.emit('exit', code);
        const delay = BACKOFF[Math.min(backoffIdx, BACKOFF.length - 1)];
        console.error(`[messenger-consumer] вышел с кодом ${code}, перезапуск через ${delay}ms`);
        setTimeout(() => spawnProcess(meiliPort, meiliKey, backoffIdx + 1), delay);
    });
}

/**
 * Запускает messenger-consumer: спавнит процесс → ждёт, что он не упал сразу после старта.
 *
 * @param {number} meiliPort  порт Meilisearch
 * @param {string} meiliKey   master-key Meilisearch
 * @returns {Promise<void>}
 */
async function start(meiliPort, meiliKey) {
    stopping = false;

    const logDir = path.join(paths.getRuntimeDir(), 'log');
    pruneOldLogs(logDir, LOG_PREFIX, LOG_MAX);
    logStream = openLogStream(logDir, LOG_PREFIX);

    spawnProcess(meiliPort, meiliKey);
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
            resolve();
        });

        proc.kill('SIGTERM');
    });
}

module.exports = { start, stop, buildEnv, events };
