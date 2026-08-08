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

const http = require('http');

/**
 * Опрашивает GET <path> на 127.0.0.1:port до получения 200 или истечения таймаута.
 *
 * @param {number} port
 * @param {{ intervalMs?: number, timeoutMs?: number, path?: string }} options
 * @returns {Promise<number>} резолвится портом при успехе
 */
function waitForHealth(port, { intervalMs = 200, timeoutMs = 30000, path = '/health' } = {}) {
    return new Promise((resolve, reject) => {
        const deadline = Date.now() + timeoutMs;

        const check = () => {
            http.get(`http://127.0.0.1:${port}${path}`, (res) => {
                if (res.statusCode === 200) return resolve(port);
                retry();
            }).on('error', retry);
        };

        const retry = () => {
            if (Date.now() > deadline) {
                return reject(new Error(`/health не ответил за ${timeoutMs}ms на порту ${port}`));
            }
            setTimeout(check, intervalMs);
        };

        check();
    });
}

/**
 * Waits graceMs and resolves if the process is still alive by then — used for processes
 * without an HTTP endpoint to catch immediate startup failures (e.g. wrong CLI arguments).
 * Rejects early if the process exits before the grace period elapses.
 *
 * @param {import('child_process').ChildProcess} child
 * @param {number} graceMs
 * @returns {Promise<void>}
 */
function waitForProcessAlive(child, graceMs = 1000) {
    return new Promise((resolve, reject) => {
        const onExit = (code) => {
            clearTimeout(timer);
            reject(new Error(`процесс завершился до истечения проверки готовности (код ${code})`));
        };

        const timer = setTimeout(() => {
            child.removeListener('exit', onExit);
            resolve();
        }, graceMs);

        child.once('exit', onExit);
    });
}

module.exports = { waitForHealth, waitForProcessAlive };
