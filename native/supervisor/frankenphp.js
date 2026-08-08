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

const { app }      = require('electron');
const { spawn }    = require('child_process');
const { EventEmitter } = require('events');
const fs           = require('fs');
const path         = require('path');
const paths        = require('../paths');
const { getOrCreateAppSecret } = require('../config');
const { findFreePort }    = require('./port');
const { waitForHealth }   = require('./healthcheck');
const { pruneOldLogs, openLogStream } = require('./logrotate');

const events = new EventEmitter();

const BINARY = path.join(__dirname, '..', '..', 'bin', 'frankenphp', 'frankenphp.exe');
const CADDYFILE = path.join(__dirname, '..', '..', 'app', 'Caddyfile');
const PHP_INI_TEMPLATE = path.join(__dirname, '..', '..', 'bin', 'php', 'php.ini.template');

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
 * Создаёт php.ini в AppData, если его ещё нет.
 * Подставляет системный часовой пояс вместо {{TIMEZONE}}.
 */
function ensurePhpIni() {
    const iniPath = paths.getPhpIniPath();
    if (fs.existsSync(iniPath)) return;

    const template = fs.readFileSync(PHP_INI_TEMPLATE, 'utf8');
    const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    const ini = template.replace('{{TIMEZONE}}', timezone);

    fs.mkdirSync(paths.getPhpIniDir(), { recursive: true });
    fs.writeFileSync(iniPath, ini, 'utf8');
}

function buildEnv(appPort, wsPort, meiliPort, meiliKey, qbittorrentPort) {
    return {
        ...process.env,
        APP_PORT:                String(appPort),
        WS_PORT:                 String(wsPort),
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
        CONFIG_PATH:             paths.getConfigPath(),
        PLUGINS_CONFIG_PATH:     paths.getPluginsConfigPath(),
        PLUGINS_DIR:             paths.getPluginsDir(),
        MEILISEARCH_URL:         `http://127.0.0.1:${meiliPort}`,
        MEILISEARCH_KEY:         meiliKey,
        OAUTH_CALLBACK_ORIGIN:   `http://127.0.0.1:${appPort}`,
        QBITTORRENT_URL:         `http://127.0.0.1:${qbittorrentPort}`,
    };
}

/**
 * Запускает FrankenPHP и при падении перезапускает с backoff.
 * Если stopping === true — молча прекращает перезапуски.
 */
function spawnProcess(appPort, wsPort, meiliPort, meiliKey, qbittorrentPort, backoffIdx = 0) {
    if (stopping) return;

    fs.mkdirSync(paths.getRuntimeDir(), { recursive: true });

    child = spawn(BINARY, ['run', '--config', CADDYFILE], {
        cwd: paths.getAppRootDir(),
        env: buildEnv(appPort, wsPort, meiliPort, meiliKey, qbittorrentPort),
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    child.stdout.on('data', (d) => logStream.write(d));
    child.stderr.on('data', (d) => logStream.write(d));

    child.on('exit', (code) => {
        if (stopping) return;
        events.emit('exit', code);
        const delay = BACKOFF[Math.min(backoffIdx, BACKOFF.length - 1)];
        console.error(`[frankenphp] вышел с кодом ${code}, перезапуск через ${delay}ms`);
        setTimeout(() => spawnProcess(appPort, wsPort, meiliPort, meiliKey, qbittorrentPort, backoffIdx + 1), delay);
    });
}

/**
 * Запускает FrankenPHP: ищет порты → создаёт php.ini → спавнит процесс →
 * ждёт /health → возвращает порты.
 *
 * @param {number} meiliPort  порт Meilisearch
 * @param {string} meiliKey   master-key Meilisearch
 * @param {number} qbittorrentPort  WebUI-порт qbittorrent-nox
 * @returns {Promise<{ httpPort: number, wsPort: number }>}
 */
async function start(meiliPort, meiliKey, qbittorrentPort) {
    stopping = false;
    ensurePhpIni();

    const logDir = path.join(paths.getRuntimeDir(), 'log');
    pruneOldLogs(logDir, LOG_PREFIX, LOG_MAX);
    logStream = openLogStream(logDir, LOG_PREFIX);

    port   = await findFreePort(8000);
    wsPort = await findFreePort(port + 1);
    spawnProcess(port, wsPort, meiliPort, meiliKey, qbittorrentPort);
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
            resolve();
        });

        proc.kill('SIGTERM');
    });
}

module.exports = { start, stop, buildEnv, events };
