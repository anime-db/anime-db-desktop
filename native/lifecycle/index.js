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

const { app, dialog, session } = require('electron');
require('../protocols/app-media');
require('../accept-language');
require('../shell');
require('../dialog');
const supervisor       = require('../supervisor');
const { createWindow } = require('../window');
const { createSplash } = require('../window/splash');
const tray             = require('../tray');
const wsClient         = require('../ws-client');
const proxy            = require('../proxy');
const firewall         = require('../firewall');

let quitting = false;

function onQuit() {
    quitting = true;
    app.quit();
}

proxy.registerProxyAuthHandler();

app.whenReady().then(async () => {
    await proxy.applyProxy(session.defaultSession);

    const splash = createSplash();

    await new Promise(resolve => splash.once('ready-to-show', () => {
        splash.show();
        resolve();
    }));

    try {
        splash.webContents.send('splash-progress', { step: 0, text: 'Запуск Meilisearch...' });

        const { frankenphpPort, wsPort } = await supervisor.start((step, text) => {
            if (!splash.isDestroyed()) {
                splash.webContents.send('splash-progress', { step, text });
            }
        });

        wsClient.connect(wsPort);

        await new Promise(r => setTimeout(r, 400));
        splash.close();

        const mainWindow = createWindow(frankenphpPort);

        mainWindow.on('close', (e) => {
            if (!quitting) {
                e.preventDefault();
                mainWindow.hide();
            }
        });

        tray.create(mainWindow, onQuit);

        wsClient.on('backend-event', ({ event, data }) => {
            if (event === 'backend.status') tray.setState(data.state);
            if (event === proxy.PROXY_CHANGED_EVENT) {
                proxy.applyProxy(session.defaultSession).catch((err) => {
                    console.error('[proxy] не удалось применить настройки прокси:', err);
                });
            }
            if (event === firewall.FIREWALL_RULE_CHANGED_EVENT) {
                firewall.applyIncomingConnections(Boolean(data.enabled)).catch((err) => {
                    dialog.showErrorBox(
                        'Брандмауэр Windows',
                        `Не удалось изменить правило для входящих подключений торрент-клиента: ${err.message}`,
                    );
                });
            }
        });

        supervisor.events.on('exit', () => tray.setState('error'));
    } catch (err) {
        dialog.showErrorBox('Ошибка запуска', err.message);
        if (!splash.isDestroyed()) splash.close();
        app.quit();
    }
});

app.on('before-quit', (event) => {
    quitting = true;
    event.preventDefault();
    wsClient.disconnect();
    supervisor.stop().then(() => app.exit(0));
});
