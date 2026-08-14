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

const { app, dialog, session } = require('electron');
const fs   = require('fs');
const path = require('path');
require('../protocols/app-media');
require('../accept-language');
require('../shell');
require('../dialog');
const paths            = require('../paths');
const supervisor       = require('../supervisor');
const { createWindow } = require('../window');
const { createSplash } = require('../window/splash');
const tray             = require('../tray');
const wsClient         = require('../ws-client');
const proxy            = require('../proxy');
const firewall         = require('../firewall');
const { todayStr }     = require('../supervisor/logrotate');

let quitting   = false;
let mainWindow = null;

function onQuit() {
    quitting = true;
    app.quit();
}

/**
 * Синхронно дописывает стек необработанного исключения в лог главного процесса. Дочерние
 * процессы уже логируются через logrotate.js в своих супервизорах, но у главного процесса
 * своего лога не было — console.error() в собранном GUI-приложении никуда не попадает (issue
 * #390, отзыв ревьюера). Best-effort: если запись не удалась (например, каталог недоступен),
 * молча продолжаем — показать диалог и выйти важнее, чем сам факт логирования.
 *
 * @param {Error} err
 */
function logCrash(err) {
    try {
        const logDir = path.join(paths.getRuntimeDir(), 'log');
        fs.mkdirSync(logDir, { recursive: true });
        const file = path.join(logDir, `main-${todayStr()}.log`);
        fs.appendFileSync(file, `[${new Date().toISOString()}] ${err && err.stack ? err.stack : String(err)}\n`);
    } catch {
        // см. комментарий выше — лог необязателен, диалог и выход обязательны
    }
}

const gotLock = app.requestSingleInstanceLock();

if (!gotLock) {
    // Уже запущен другой экземпляр — этот выходит немедленно, не трогая его дочерние процессы.
    app.quit();
} else {
    /**
     * Второй запуск (уже открыт другой экземпляр) поднимает существующее окно вместо старта
     * второй копии — Electron сам перенаправляет вызов сюда, во второй процесс, благодаря
     * requestSingleInstanceLock() выше (issue #390).
     */
    app.on('second-instance', () => {
        if (!mainWindow) return;
        if (mainWindow.isMinimized()) mainWindow.restore();
        mainWindow.show();
        mainWindow.focus();
    });

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

            mainWindow = createWindow(frankenphpPort);

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

    /**
     * Страховка для путей завершения, которые не проходят через before-quit — например
     * process.exit(), вызванный откуда-то ещё в главном процессе. Дождаться асинхронного
     * supervisor.stop() в обработчике 'exit' нельзя, поэтому сразу SIGKILL (issue #390).
     */
    process.on('exit', () => {
        supervisor.killSync();
    });

    process.on('SIGTERM', onQuit);
    process.on('SIGINT', onQuit);

    process.on('uncaughtException', (err) => {
        logCrash(err);
        supervisor.killSync();
        dialog.showErrorBox('Необработанная ошибка', err && err.stack ? err.stack : String(err));
        app.exit(1);
    });
}
