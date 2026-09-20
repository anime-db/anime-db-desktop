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

const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('animeDb', {
    openPath:   (targetPath) => ipcRenderer.invoke('shell:open-path', targetPath),
    pickFolder: () => ipcRenderer.invoke('dialog:pick-folder'),
    pickFile:   (filters) => ipcRenderer.invoke('dialog:pick-file', filters),
    onNotification: (callback) => ipcRenderer.on('app-notification', (_event, data) => callback(data)),
    catalogExportStart:  (destinationDir) => ipcRenderer.invoke('catalog:export-start', destinationDir),
    catalogExportCancel: () => ipcRenderer.invoke('catalog:export-cancel'),
});
