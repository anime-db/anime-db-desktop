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

const { execFile, spawnSync } = require('child_process');
const fs   = require('fs');
const path = require('path');
const paths = require('../paths');

/**
 * @param {string} name
 * @returns {string}
 */
function pidFilePath(name) {
    return path.join(paths.getRuntimeDir(), 'pids', `${name}.pid`);
}

/**
 * Сохраняет PID только что запущенного дочернего процесса, чтобы будущий killOrphan()
 * (следующий старт приложения) мог найти и убить его, даже если этот сеанс так и не дошёл до
 * штатного stop() — падение, принудительное завершение, инсталлятор, закрывший только
 * AnimeDB.exe (issue #390).
 *
 * @param {string} name
 * @param {number} pid
 */
function writePid(name, pid) {
    const file = pidFilePath(name);
    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.writeFileSync(file, String(pid), 'utf8');
}

/**
 * Удаляет файл-трекер после штатного stop() — на следующем старте убирать нечего.
 *
 * @param {string} name
 */
function clearPid(name) {
    const file = pidFilePath(name);
    if (fs.existsSync(file)) fs.rmSync(file);
}

/**
 * Подтверждает, что PID всё ещё принадлежит ожидаемому бинарнику, прежде чем его убивать — сам
 * по себе PID недостаточен, ОС может переиспользовать его для другого процесса между сеансами.
 *
 * @param {number} pid
 * @param {string} binaryName
 * @returns {Promise<boolean>}
 */
function isRunningAs(pid, binaryName) {
    return new Promise((resolve) => {
        execFile(
            'tasklist',
            ['/FI', `PID eq ${pid}`, '/FO', 'CSV', '/NH'],
            { windowsHide: true },
            (err, stdout) => {
                if (err) {
                    resolve(false);
                    return;
                }
                resolve(String(stdout).toLowerCase().includes(binaryName.toLowerCase()));
            },
        );
    });
}

/**
 * `/T` завершает всё дерево процесса, а не только сам PID: внук, запущенный из PHP (внешний
 * процесс консьюмера), иначе переживает закрытие приложения и держит открытыми файлы (issue #757).
 *
 * @param {number} pid
 * @returns {string[]}
 */
function taskkillArgs(pid) {
    return ['/PID', String(pid), '/T', '/F'];
}

/**
 * Завершает процесс вместе с потомками. На Windows — `taskkill /T /F`; на остальных платформах —
 * SIGKILL всей группе процессов (`-pid`), с откатом на одиночный PID, если процесс не лидер группы.
 *
 * @param {number} pid
 * @returns {Promise<void>}
 */
function killTree(pid) {
    return new Promise((resolve) => {
        if (process.platform === 'win32') {
            execFile('taskkill', taskkillArgs(pid), { windowsHide: true }, () => resolve());
            return;
        }
        killPosixGroup(pid);
        resolve();
    });
}

/**
 * Синхронный вариант killTree() для аварийного выхода, где дожидаться асинхронщины нельзя.
 *
 * @param {number} pid
 */
function killTreeSync(pid) {
    if (process.platform === 'win32') {
        spawnSync('taskkill', taskkillArgs(pid), { windowsHide: true });
        return;
    }
    killPosixGroup(pid);
}

/**
 * @param {number} pid
 */
function killPosixGroup(pid) {
    try {
        process.kill(-pid, 'SIGKILL');
    } catch {
        try {
            process.kill(pid, 'SIGKILL');
        } catch {
            // процесс уже завершился
        }
    }
}

/**
 * Убивает процесс-сироту от предыдущего сеанса, который так и не дошёл до штатного stop() —
 * падение, принудительное завершение из диспетчера задач, инсталлятор, закрывший только
 * AnimeDB.exe и оставивший дочерние процессы висеть (issue #390). Читает PID, оставленный
 * writePid(), проверяет, что это всё ещё ожидаемый бинарник, принудительно завершает его и в
 * любом случае очищает файл-трекер. No-op на любой платформе, кроме Windows — приложение
 * поставляется только под неё (см. .claude-docs/architecture.md).
 *
 * @param {string} name
 * @param {string} binaryPath
 * @returns {Promise<void>}
 */
async function killOrphan(name, binaryPath) {
    const file = pidFilePath(name);
    if (!fs.existsSync(file)) return;

    const pid = parseInt(fs.readFileSync(file, 'utf8').trim(), 10);
    clearPid(name);

    if (process.platform !== 'win32' || !Number.isInteger(pid) || pid <= 0) return;

    if (await isRunningAs(pid, path.basename(binaryPath))) {
        await killTree(pid);
    }
}

module.exports = { writePid, clearPid, killOrphan, killTree, killTreeSync, pidFilePath };
