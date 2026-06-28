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

const { app, dialog } = require('electron');
const supervisor       = require('../supervisor');
const { createWindow } = require('../window');
const { createSplash } = require('../window/splash');

app.whenReady().then(async () => {
    const splash = createSplash();

    await new Promise(resolve => splash.once('ready-to-show', () => {
        splash.show();
        resolve();
    }));

    try {
        splash.webContents.send('splash-progress', { step: 0, text: 'Запуск Meilisearch...' });

        const { frankenphpPort } = await supervisor.start((step, text) => {
            if (!splash.isDestroyed()) {
                splash.webContents.send('splash-progress', { step, text });
            }
        });

        await new Promise(r => setTimeout(r, 400));
        splash.close();
        createWindow(frankenphpPort);
    } catch (err) {
        dialog.showErrorBox('Ошибка запуска', err.message);
        if (!splash.isDestroyed()) splash.close();
        app.quit();
    }
});

app.on('window-all-closed', () => {
    if (process.platform !== 'darwin') app.quit();
});

app.on('before-quit', (event) => {
    event.preventDefault();
    supervisor.stop().then(() => app.exit(0));
});
