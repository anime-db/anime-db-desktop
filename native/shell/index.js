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

const { ipcMain, shell } = require('electron');

/**
 * Открывает системный проводник на пути, который пользователь ранее указал как хранилище
 * аниме (Storage::path). Путь приходит из HTML, отрендеренного собственным Symfony-бэкендом
 * (issue #105), поэтому он не рассматривается как произвольный ввод веб-контента.
 *
 * @param {import('electron').IpcMainInvokeEvent} _event
 * @param {string} targetPath
 * @returns {Promise<string>}
 */
function openStoragePath(_event, targetPath) {
    return shell.openPath(targetPath);
}

ipcMain.handle('shell:open-path', openStoragePath);

module.exports = { openStoragePath };
