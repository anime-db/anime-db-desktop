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

const { BrowserWindow } = require('electron');

let win = null;

/**
 * Создаёт главное окно и загружает Symfony-приложение по порту.
 *
 * @param {number} port
 */
function createWindow(port) {
    win = new BrowserWindow({ width: 1200, height: 800 });
    win.loadURL(`http://127.0.0.1:${port}`);
    win.on('closed', () => { win = null; });
}

module.exports = { createWindow };
