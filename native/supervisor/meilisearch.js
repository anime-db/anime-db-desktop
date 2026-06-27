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

const { spawn }         = require('child_process');
const crypto            = require('crypto');
const fs                = require('fs');
const path              = require('path');
const paths             = require('../paths');
const { findFreePort }  = require('./port');
const { waitForHealth } = require('./healthcheck');

const BINARY   = path.join(__dirname, '..', '..', 'bin', 'meilisearch', 'meilisearch.exe');
const VERSIONS = path.join(__dirname, '..', '..', 'scripts', 'versions.json');

/** Задержки backoff при перезапуске: 1s, 2s, 4s, … до 30s. */
const BACKOFF = [1000, 2000, 4000, 8000, 16000, 30000];

let child    = null;
let stopping = false;
let port     = null;

/**
 * Читает master-key из файла или генерирует UUID и сохраняет.
 *
 * @returns {string}
 */
function ensureMasterKey() {
    const keyPath = paths.getMeilisearchKeyPath();
    if (fs.existsSync(keyPath)) {
        return fs.readFileSync(keyPath, 'utf8').trim();
    }
    const key = crypto.randomUUID();
    fs.mkdirSync(path.dirname(keyPath), { recursive: true });
    fs.writeFileSync(keyPath, key, 'utf8');
    return key;
}

/**
 * Сравнивает версию из scripts/versions.json с версией в AppData/meilisearch/VERSION.
 * При несовпадении удаляет data.ms/ для переиндексации.
 */
function checkVersionAndWipe() {
    const dataDir    = paths.getMeilisearchDataDir();
    const versionFile = path.join(dataDir, 'VERSION');

    if (!fs.existsSync(versionFile)) return;

    const { meilisearch: expected } = JSON.parse(fs.readFileSync(VERSIONS, 'utf8'));
    const actual = fs.readFileSync(versionFile, 'utf8').trim();

    if (actual !== expected) {
        console.error(`[meilisearch] версия сменилась (${actual} → ${expected}), вайп data.ms/`);
        const dataMs = path.join(dataDir, 'data.ms');
        if (fs.existsSync(dataMs)) {
            fs.rmSync(dataMs, { recursive: true, force: true });
        }
    }
}

/**
 * Запускает Meilisearch и при падении перезапускает с backoff.
 */
function spawnProcess(appPort, masterKey, backoffIdx = 0) {
    if (stopping) return;

    const dataDir = paths.getMeilisearchDataDir();
    fs.mkdirSync(dataDir, { recursive: true });

    child = spawn(BINARY, [
        '--db-path',     dataDir,
        '--http-addr',   `127.0.0.1:${appPort}`,
        '--master-key',  masterKey,
        '--no-analytics',
    ], {
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    child.stdout.on('data', (d) => process.stdout.write(`[meilisearch] ${d}`));
    child.stderr.on('data', (d) => process.stderr.write(`[meilisearch] ${d}`));

    child.on('exit', (code) => {
        if (stopping) return;
        const delay = BACKOFF[Math.min(backoffIdx, BACKOFF.length - 1)];
        console.error(`[meilisearch] вышел с кодом ${code}, перезапуск через ${delay}ms`);
        setTimeout(() => spawnProcess(appPort, masterKey, backoffIdx + 1), delay);
    });
}

/**
 * Запускает Meilisearch: генерирует/читает key → проверяет версию →
 * ищет порт → спавнит процесс → ждёт /health → возвращает { port, key }.
 *
 * @returns {Promise<{ port: number, key: string }>}
 */
async function start() {
    stopping = false;
    const masterKey = ensureMasterKey();
    checkVersionAndWipe();
    port = await findFreePort(7700);
    spawnProcess(port, masterKey);
    await waitForHealth(port);
    return { port, key: masterKey };
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
