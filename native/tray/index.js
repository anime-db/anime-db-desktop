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

const { Tray, Menu, nativeImage } = require('electron');
const path = require('path');

let tray = null;

const ICONS = {
    idle:  path.join(__dirname, '..', '..', 'resources', 'tray-idle.ico'),
    busy:  path.join(__dirname, '..', '..', 'resources', 'tray-busy.ico'),
    error: path.join(__dirname, '..', '..', 'resources', 'tray-error.ico'),
};

/**
 * @param {import('electron').BrowserWindow} mainWindow
 * @param {Function} onQuit
 */
function create(mainWindow, onQuit) {
    const icon = nativeImage.createFromPath(ICONS.idle);
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

/**
 * Меняет иконку трея в соответствии с состоянием бэкенда.
 *
 * @param {'idle' | 'busy' | 'error'} state
 */
function setState(state) {
    const iconPath = ICONS[state];
    if (!tray || !iconPath) return;

    tray.setImage(nativeImage.createFromPath(iconPath));
}

module.exports = { create, setState };
