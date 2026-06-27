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
const fs           = require('fs');
const path         = require('path');
const paths        = require('../paths');
const { findFreePort }  = require('./port');
const { waitForHealth } = require('./healthcheck');

const BINARY = path.join(__dirname, '..', '..', 'bin', 'frankenphp', 'frankenphp.exe');
const CADDYFILE = path.join(__dirname, '..', '..', 'app', 'Caddyfile');
const PHP_INI_TEMPLATE = path.join(__dirname, '..', '..', 'bin', 'php', 'php.ini.template');

/** Задержки backoff при перезапуске: 1s, 2s, 4s, … до 30s. */
const BACKOFF = [1000, 2000, 4000, 8000, 16000, 30000];

let child    = null;
let stopping = false;
let port     = null;

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

function buildEnv(appPort) {
    return {
        ...process.env,
        APP_PORT:        String(appPort),
        APP_ROOT:        paths.getAppRootDir(),
        APP_ENV:         'prod',
        DATABASE_URL:    `sqlite:///${paths.getDbPath()}`,
        PHPRC:           paths.getPhpIniDir(),
        APP_RUNTIME_DIR: paths.getRuntimeDir(),
        // MEILISEARCH_URL / MEILISEARCH_KEY добавляются в Таске 8
    };
}

/**
 * Запускает FrankenPHP и при падении перезапускает с backoff.
 * Если stopping === true — молча прекращает перезапуски.
 */
function spawnProcess(appPort, backoffIdx = 0) {
    if (stopping) return;

    fs.mkdirSync(paths.getRuntimeDir(), { recursive: true });

    child = spawn(BINARY, ['run', '--config', CADDYFILE], {
        cwd: paths.getAppRootDir(),
        env: buildEnv(appPort),
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    child.stdout.on('data', (d) => process.stdout.write(`[frankenphp] ${d}`));
    child.stderr.on('data', (d) => process.stderr.write(`[frankenphp] ${d}`));

    child.on('exit', (code) => {
        if (stopping) return;
        const delay = BACKOFF[Math.min(backoffIdx, BACKOFF.length - 1)];
        console.error(`[frankenphp] вышел с кодом ${code}, перезапуск через ${delay}ms`);
        setTimeout(() => spawnProcess(appPort, backoffIdx + 1), delay);
    });
}

/**
 * Запускает FrankenPHP: ищет порт → создаёт php.ini → спавнит процесс →
 * ждёт /health → возвращает порт.
 *
 * @returns {Promise<number>}
 */
async function start() {
    stopping = false;
    ensurePhpIni();
    port = await findFreePort(8000);
    spawnProcess(port);
    await waitForHealth(port);
    return port;
}

/**
 * Graceful shutdown: SIGTERM → 500ms → SIGKILL.
 */
function stop() {
    stopping = true;
    if (!child) return;

    child.kill('SIGTERM');
    const timer = setTimeout(() => {
        if (child) child.kill('SIGKILL');
    }, 500);

    child.on('exit', () => clearTimeout(timer));
    child = null;
}

module.exports = { start, stop };
