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
require('../protocols/app-media');
require('../accept-language');
require('../shell');
require('../dialog');
const supervisor       = require('../supervisor');
const safeModeState    = require('../supervisor/safe-mode');
const { createWindow } = require('../window');
const { createSplash } = require('../window/splash');
const tray             = require('../tray');
const wsClient         = require('../ws-client');
const proxy            = require('../proxy');
const firewall         = require('../firewall');
const paths            = require('../paths');
const { logCrash }     = require('../crash-log');
const { getLocale }    = require('../config');
const { MigrationBootstrapError } = require('../supervisor/migrations');
const { CacheInvalidationError } = require('../supervisor/cache-invalidation');

/**
 * The migration-failure and downgrade dialogs are the only places in the app where the user is
 * required to take action to recover (delete user data, check disk space, read a log) — see
 * issue #392 — so, unlike the rest of native/ (Russian-only today), they are localized using the
 * same locale native/accept-language.js negotiates with.
 *
 * @param {InstanceType<typeof MigrationBootstrapError>} err
 * @param {string} locale
 * @returns {{ title: string, message: string }}
 */
function buildMigrationErrorDialog(err, locale) {
    const isRu = locale.startsWith('ru');

    if (err.kind === 'downgrade') {
        const userDataDir = paths.getUserDataDir();
        return isRu
            ? {
                title: 'Несовместимая версия данных',
                message: `Каталог данных был создан более новой версией AnimeDB и несовместим с этой версией `
                    + `приложения.\n\nЧтобы продолжить, удалите папку с пользовательскими данными:\n${userDataDir}\n\n`
                    + 'и установите приложение заново. Обычное удаление приложения эту папку не затрагивает.',
            }
            : {
                title: 'Incompatible data version',
                message: 'The data folder was created by a newer version of AnimeDB and is incompatible with this '
                    + `version of the app.\n\nTo continue, delete the user data folder:\n${userDataDir}\n\n`
                    + 'and reinstall the app. The regular uninstaller does not remove this folder.',
            };
    }

    const details = [];
    if (err.detail) details.push((isRu ? 'Ошибка: ' : 'Error: ') + err.detail);
    if (err.logPath) details.push((isRu ? 'Лог: ' : 'Log: ') + err.logPath);
    if (err.backupPath) details.push((isRu ? 'Резервная копия: ' : 'Backup: ') + err.backupPath);
    const detailsBlock = details.length > 0 ? `\n\n${details.join('\n')}` : '';

    if (err.kind === 'backup-failed') {
        return isRu
            ? {
                title: 'Не удалось создать резервную копию базы данных',
                message: 'Перед обновлением схемы базы данных не удалось создать её резервную копию, поэтому '
                    + `обновление не выполнялось.${detailsBlock}`,
            }
            : {
                title: 'Database backup failed',
                message: 'Could not create a backup of the database before updating its schema, so the update was '
                    + `not attempted.${detailsBlock}`,
            };
    }

    return isRu
        ? {
            title: 'Не удалось обновить схему базы данных',
            message: 'Обновление схемы базы данных завершилось ошибкой. Резервная копия была восстановлена, '
                + `повторная попытка также не удалась.${detailsBlock}`,
        }
        : {
            title: 'Database schema update failed',
            message: 'Updating the database schema failed. The backup was restored and the update was retried '
                + `once, which also failed.${detailsBlock}`,
        };
}

/**
 * Shown before the kernel is started (issue #403), when the previous two launches in a row never
 * reached a successful start — see safe-mode.js#beginStartAttempt(). Localized the same way as
 * {@see buildMigrationErrorDialog}, for the same reason: recovering from this requires the user
 * to make a choice, not just acknowledge an error.
 *
 * @param {string} locale
 * @returns {{ title: string, message: string, buttons: [string, string] }}
 */
function buildSafeModeDialog(locale) {
    const isRu = locale.startsWith('ru');

    return isRu
        ? {
            title: 'Не удаётся запустить приложение',
            message: 'Приложение не смогло запуститься два раза подряд. Возможная причина — несовместимый '
                + 'плагин.\n\nМожно запустить приложение без плагинов, чтобы открыть его и удалить '
                + 'проблемный плагин. Плагины снова будут загружены при обычном перезапуске.',
            buttons: ['Запустить без плагинов', 'Выход'],
        }
        : {
            title: 'The app failed to start',
            message: 'The app failed to start two times in a row. An incompatible plugin may be the cause.\n\n'
                + 'You can start it without plugins to open it and remove the problematic one. Plugins are loaded '
                + 'again on the next regular restart.',
            buttons: ['Run without plugins', 'Exit'],
        };
}

let quitting   = false;
let mainWindow = null;

function onQuit() {
    quitting = true;
    app.quit();
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

        // Should the kernel be trusted this time? See safe-mode.js#beginStartAttempt() — two
        // unclosed starts in a row means the last two launches never made it to a successful
        // start, so ask before trying a third time the same way (issue #403).
        let safeMode = false;
        if (safeModeState.beginStartAttempt()) {
            const { title, message, buttons } = buildSafeModeDialog(getLocale());
            const choice = dialog.showMessageBoxSync({
                type:      'warning',
                buttons,
                defaultId: 0,
                cancelId:  1,
                title,
                message,
            });
            if (choice === 1) {
                app.quit();
                return;
            }
            safeMode = true;
        }

        const splash = createSplash();

        await new Promise(resolve => splash.once('ready-to-show', () => {
            splash.show();
            resolve();
        }));

        try {
            splash.webContents.send('splash-progress', { step: 0, total: supervisor.TOTAL_STEPS, text: 'Запуск Meilisearch...' });

            const { frankenphpPort, wsPort } = await supervisor.start((step, total, text) => {
                if (!splash.isDestroyed()) {
                    splash.webContents.send('splash-progress', { step, total, text });
                }
            }, { safeMode });

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
            logCrash(err);
            if (!splash.isDestroyed()) splash.close();
            if (err instanceof MigrationBootstrapError) {
                // Diagnosed failure — the dialog below already tells the user the exact cause, so
                // it must not count towards the safe-mode streak (see commitDiagnosedFailure()).
                safeModeState.commitDiagnosedFailure();
                const { title, message } = buildMigrationErrorDialog(err, getLocale());
                dialog.showErrorBox(title, message);
            } else if (err instanceof CacheInvalidationError) {
                safeModeState.commitDiagnosedFailure();
                dialog.showErrorBox('Ошибка запуска', err.message);
            } else {
                dialog.showErrorBox('Ошибка запуска', err.message);
            }
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
