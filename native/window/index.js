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

const { BrowserWindow, Menu, screen, shell } = require('electron');
const path = require('path');

let win = null;

const DEFAULT_WIDTH = 1200;
const DEFAULT_HEIGHT = 800;

/**
 * Возвращает размер окна по умолчанию, не превышающий рабочую область основного
 * дисплея (экран минус панель задач). На экранах меньше 1200×800 (например,
 * 1024×768) окно по умолчанию иначе создаётся больше рабочей области и уезжает
 * за край при первом запуске.
 *
 * @returns {{width: number, height: number}}
 */
function getDefaultWindowSize() {
    const { width, height } = screen.getPrimaryDisplay().workAreaSize;
    return {
        width: Math.min(DEFAULT_WIDTH, width),
        height: Math.min(DEFAULT_HEIGHT, height),
    };
}

/**
 * Проверяет, ведёт ли url на собственный локальный backend приложения
 * (127.0.0.1:{port}), а не на внешний origin.
 *
 * @param {string} url
 * @param {number} port
 * @returns {boolean}
 */
function isLocalUrl(url, port) {
    try {
        const parsed = new URL(url);
        return parsed.hostname === '127.0.0.1' && parsed.port === String(port);
    } catch {
        return false;
    }
}

const EXTERNAL_SCHEMES = ['http:', 'https:'];

/**
 * Открывает url в системном браузере, только если схема — http/https.
 * shell.openExternal — опасный sink: file://, smb:// и произвольные
 * зарегистрированные в ОС протоколы могут привести к утечке SMB-учёток
 * или запуску стороннего обработчика (Electron security guide).
 *
 * @param {string} url
 */
function openExternal(url) {
    let parsed;
    try {
        parsed = new URL(url);
    } catch {
        return;
    }
    if (EXTERNAL_SCHEMES.includes(parsed.protocol)) {
        shell.openExternal(url);
    }
}

/**
 * Перехватывает навигацию окна (top-level, редиректы и новые окна) на внешний
 * origin и открывает её в системном браузере вместо окна приложения. Нужно для
 * OAuth (RFC 8252 — страница авторизации провайдера должна открываться в
 * системном браузере, а не в webview) и для любых внешних ссылок вообще
 * (issue #310). Локальный backend (127.0.0.1:{port}) навигацию не трогает.
 *
 * @param {import('electron').BrowserWindow} browserWindow
 * @param {number} port
 */
function interceptExternalNavigation(browserWindow, port) {
    const { webContents } = browserWindow;

    webContents.on('will-navigate', (event, url) => {
        if (!isLocalUrl(url, port)) {
            event.preventDefault();
            openExternal(url);
        }
    });

    webContents.on('will-redirect', (event, url) => {
        if (!isLocalUrl(url, port)) {
            event.preventDefault();
            openExternal(url);
        }
    });

    webContents.setWindowOpenHandler(({ url }) => {
        if (!isLocalUrl(url, port)) {
            openExternal(url);
        }
        return { action: 'deny' };
    });
}

/**
 * Создаёт главное окно и загружает Symfony-приложение по порту. Preload с
 * contextIsolation даёт странице доступ к shell.openPath() через window.animeDb
 * (issue #105), не открывая ей произвольный доступ к Node.js.
 *
 * @param {number} port
 */
function createWindow(port) {
    // Electron's default application menu (File/Edit/View/Window/Help) has no items relevant
    // to AnimeDB and exposes View → Toggle Developer Tools in release builds. It is removed
    // instead of replaced: on Windows (the only build target, see build.win in package.json)
    // copy/paste in input fields is handled natively by Chromium and does not depend on menu
    // roles. If a macOS build is ever added, an application menu becomes mandatory there and
    // will need to be set explicitly.
    Menu.setApplicationMenu(null);

    win = new BrowserWindow({
        ...getDefaultWindowSize(),
        minWidth: 1000,
        minHeight: 640,
        webPreferences: {
            preload: path.join(__dirname, 'preload.js'),
            contextIsolation: true,
            nodeIntegration: false,
        },
    });
    interceptExternalNavigation(win, port);
    win.loadURL(`http://127.0.0.1:${port}`);
    win.on('closed', () => { win = null; });
    return win;
}

module.exports = { createWindow };
