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

const { Tray, Menu, nativeImage } = require('electron');
const path = require('path');

let tray = null;

/**
 * @param {import('electron').BrowserWindow} mainWindow
 * @param {Function} onQuit
 */
function create(mainWindow, onQuit) {
    const icon = nativeImage.createFromPath(path.join(__dirname, '..', '..', 'resources', 'favicon.ico'));
    tray = new Tray(icon);
    tray.setToolTip('AnimeDB');

    const menu = Menu.buildFromTemplate([
        { label: 'Открыть', click: () => mainWindow.show() },
        { type: 'separator' },
        { label: 'Выход', click: onQuit },
    ]);
    tray.setContextMenu(menu);
    tray.on('double-click', () => mainWindow.show());
}

module.exports = { create };
