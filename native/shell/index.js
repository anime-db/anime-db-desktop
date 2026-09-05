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

const fs   = require('fs');
const path = require('path');
const http = require('http');
const { ipcMain, shell } = require('electron');

let backendPort = null;

const STORAGE_LIST_TIMEOUT_MS = 5000;

/**
 * Запоминает порт backend'а, с которого будет запрашиваться список хранилищ. Должна
 * вызываться после того, как backend поднялся, и до того, как рендерер сможет дойти до
 * shell:open-path.
 *
 * @param {number} port
 */
function configure(port) {
    backendPort = port;
}

/**
 * Запрашивает у backend'а список путей настроенных хранилищ. Рендерер не может повлиять
 * на этот список — он приходит из собственного запроса главного процесса на 127.0.0.1, а
 * не из аргумента, переданного через IPC-канал.
 *
 * @returns {Promise<string[]>}
 */
function fetchStoragePaths() {
    return new Promise((resolve, reject) => {
        if (backendPort === null) {
            reject(new Error('backend port is not configured'));
            return;
        }

        const request = http.get(`http://127.0.0.1:${backendPort}/storage/paths`, (res) => {
            if (res.statusCode !== 200) {
                res.resume();
                reject(new Error(`unexpected response status: ${res.statusCode}`));
                return;
            }

            let body = '';
            res.setEncoding('utf8');
            res.on('data', (chunk) => { body += chunk; });
            res.on('end', () => {
                try {
                    const parsed = JSON.parse(body);
                    resolve(Array.isArray(parsed.paths) ? parsed.paths : []);
                } catch (err) {
                    reject(err);
                }
            });
        });

        request.on('error', reject);
        request.setTimeout(STORAGE_LIST_TIMEOUT_MS, () => {
            request.destroy(new Error(`request timed out after ${STORAGE_LIST_TIMEOUT_MS}ms`));
        });
    });
}

/**
 * Разрешает targetPath (симлинки и `..`) и возвращает этот канонический путь, если он
 * указывает на существующий каталог, совпадающий с одним из storagePaths или лежащий
 * внутри него. Возвращает null, если проверка не пройдена.
 *
 * Открывать нужно именно возвращённый канонический путь, а не исходный targetPath —
 * между проверкой и открытием симлинк на диске может смениться и указывать уже в другое
 * место.
 *
 * @param {string} targetPath
 * @param {string[]} storagePaths
 * @returns {string|null}
 */
function resolveInsideConfiguredStorage(targetPath, storagePaths) {
    let realTarget;
    try {
        realTarget = fs.realpathSync(targetPath);
        if (!fs.statSync(realTarget).isDirectory()) return null;
    } catch {
        return null;
    }

    const isInside = storagePaths.some((storagePath) => {
        let realStorage;
        try {
            realStorage = fs.realpathSync(storagePath);
        } catch {
            return false;
        }

        const relative = path.relative(realStorage, realTarget);
        return relative === '' || (!relative.startsWith('..') && !path.isAbsolute(relative));
    });

    return isInside ? realTarget : null;
}

/**
 * Открывает системный проводник на targetPath — но только если после проверки путь
 * оказывается существующим каталогом внутри одного из хранилищ, которые пользователь
 * ранее настроил (issue #593). window.animeDb.openPath() доступен любому скрипту в
 * рендерере независимо от того, что отрендерено в HTML страницы, поэтому проверка
 * происходит здесь, в главном процессе, а не полагается на происхождение вызова.
 *
 * @param {import('electron').IpcMainInvokeEvent} _event
 * @param {string} targetPath
 * @returns {Promise<string>}
 */
async function openStoragePath(_event, targetPath) {
    let storagePaths;
    try {
        storagePaths = await fetchStoragePaths();
    } catch (err) {
        console.error(`[shell] failed to fetch the configured storage list: ${err.message}`);
        throw new Error('storage list is unavailable');
    }

    const resolvedPath = resolveInsideConfiguredStorage(targetPath, storagePaths);
    if (resolvedPath === null) {
        console.error(`[shell] rejected path outside configured storages or not an existing directory: ${targetPath}`);
        throw new Error('path is not an existing directory inside a configured storage');
    }

    return shell.openPath(resolvedPath);
}

ipcMain.handle('shell:open-path', openStoragePath);

module.exports = { openStoragePath, configure };
