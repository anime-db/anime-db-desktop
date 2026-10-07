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

/*
 * Electron main-process entry of the E2E run (launched by launch.js through Playwright, or by
 * session.js). Like scripts/shots/capture.js it skips native/index.js — the supervisor there
 * starts Windows binaries — but it does load the real preload and the real IPC handlers
 * (native/dialog), so `window.animeDb` and its dialogs behave as in the app.
 */

const { app, BrowserWindow } = require('electron');
const path = require('path');

const PORT = process.env.E2E_PORT;

if (!PORT) {
    console.error('E2E_PORT must be set — this script is launched by scripts/e2e');
    app.exit(1);
}

if (process.env.E2E_USER_DATA_DIR) {
    app.setPath('userData', process.env.E2E_USER_DATA_DIR);
}

require('../../native/dialog');

app.whenReady().then(() => {
    const win = new BrowserWindow({
        width: 1280,
        height: 900,
        webPreferences: {
            preload: path.join(__dirname, '..', '..', 'native', 'window', 'preload.js'),
            contextIsolation: true,
            nodeIntegration: false,
        },
    });
    win.loadURL(`http://127.0.0.1:${PORT}`);
});

app.on('window-all-closed', () => app.quit());
