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
require('../content-security-policy');
const shell = require('../shell');
require('../dialog');
require('../catalog-export');
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
const i18n             = require('../i18n');
const { MigrationBootstrapError } = require('../supervisor/migrations');
const { CacheInvalidationError } = require('../supervisor/cache-invalidation');

/**
 * The migration-failure and downgrade dialogs are the only places in the app where the user is
 * required to take action to recover (delete user data, check disk space, read a log) — see
 * issue #392.
 *
 * @param {InstanceType<typeof MigrationBootstrapError>} err
 * @param {string} locale
 * @returns {{ title: string, message: string }}
 */
function buildMigrationErrorDialog(err, locale) {
    if (err.kind === 'downgrade') {
        return {
            title:   i18n.t('dialog.migration_downgrade_title', locale),
            message: i18n.t('dialog.migration_downgrade_message', locale, { userDataDir: paths.getUserDataDir() }),
        };
    }

    const details = [];
    if (err.detail) details.push(i18n.t('dialog.migration_detail_error', locale, { detail: err.detail }));
    if (err.logPath) details.push(i18n.t('dialog.migration_detail_log', locale, { logPath: err.logPath }));
    if (err.backupPath) details.push(i18n.t('dialog.migration_detail_backup', locale, { backupPath: err.backupPath }));
    const detailsBlock = details.length > 0 ? `\n\n${details.join('\n')}` : '';

    if (err.kind === 'backup-failed') {
        return {
            title:   i18n.t('dialog.migration_backup_failed_title', locale),
            message: i18n.t('dialog.migration_backup_failed_message', locale, { details: detailsBlock }),
        };
    }

    return {
        title:   i18n.t('dialog.migration_schema_failed_title', locale),
        message: i18n.t('dialog.migration_schema_failed_message', locale, { details: detailsBlock }),
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
    return {
        title:   i18n.t('dialog.safe_mode_title', locale),
        message: i18n.t('dialog.safe_mode_message', locale),
        buttons: [i18n.t('dialog.safe_mode_run', locale), i18n.t('dialog.safe_mode_exit', locale)],
    };
}

let quitting   = false;
let mainWindow = null;

function onQuit() {
    quitting = true;
    app.quit();
}

/**
 * Перезапускает приложение. app.relaunch() лишь планирует повторный запуск после выхода —
 * само завершение идёт через app.quit(), который эмитит before-quit ниже: тот обходит
 * mainWindow.on('close', ...) (иначе прячущий окно в трей вместо закрытия) и делает
 * упорядоченную graceful-остановку (wsClient.disconnect() + supervisor.stop()) перед
 * app.exit(0) — вместо аварийного supervisor.killSync() из process.on('exit').
 *
 * @returns {void}
 */
function relaunch() {
    quitting = true;
    app.relaunch();
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
            const startupLocale = getLocale();
            splash.webContents.send('splash-progress', {
                step:  0,
                total: supervisor.TOTAL_STEPS,
                text:  i18n.t('splash.step_meilisearch', startupLocale),
            });

            const { frankenphpPort, wsPort } = await supervisor.start((step, total, key) => {
                if (!splash.isDestroyed()) {
                    splash.webContents.send('splash-progress', { step, total, text: i18n.t(key, startupLocale) });
                }
            }, { safeMode });

            shell.configure(frankenphpPort);
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
                            i18n.t('dialog.firewall_error_title', getLocale()),
                            i18n.t('dialog.firewall_error_message', getLocale(), { message: err.message }),
                        );
                    });
                }
                if (event === supervisor.WORKERS_RELOAD_EVENT) {
                    // reloadForPlugin() never rejects (see supervisor/index.js#performReload) —
                    // this catch is only a defensive backstop.
                    supervisor.reloadForPlugin(data.pluginId).catch((err) => {
                        console.error('[supervisor] не удалось активировать плагин:', err);
                    });
                }
            });

            supervisor.events.on('exit', () => tray.setState('error'));

            // Both the isolated warm-up (#222) and the reconcile step it runs after already
            // validated the plugin on disk — a failure here is specifically the live restart not
            // coming back healthy, which performReload() has already rolled back from by the time
            // this fires (issue #411). Routed as a non-blocking in-app notification rather than a
            // blocking dialog (issue #417) — the 'app-notification' channel is generic so a future
            // notification history/list can reuse it for other event sources.
            supervisor.events.on('plugin-activation-failed', ({ pluginId }) => {
                if (mainWindow && !mainWindow.isDestroyed()) {
                    mainWindow.webContents.send('app-notification', {
                        type:    'plugin-activation-failed',
                        pluginId,
                        title:   i18n.t('notification.plugin_activation_failed_title', getLocale()),
                        message: i18n.t('notification.plugin_activation_failed_message', getLocale(), { pluginId }),
                    });
                }
            });
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
                dialog.showErrorBox(i18n.t('dialog.startup_error_title', getLocale()), err.message);
            } else {
                dialog.showErrorBox(i18n.t('dialog.startup_error_title', getLocale()), err.message);
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
        dialog.showErrorBox(i18n.t('dialog.uncaught_error_title', getLocale()), err && err.stack ? err.stack : String(err));
        app.exit(1);
    });

    /**
     * The startup sequence in app.whenReady().then(...) above has no .catch() — a rejection
     * before the inner try (e.g. in beginStartAttempt(), the safe-mode dialog, or createSplash())
     * would otherwise be an unhandled rejection that uncaughtException does not catch, leaving the
     * app silently stuck with no dialog and no crash log entry (issue #645).
     */
    process.on('unhandledRejection', (reason) => {
        logCrash(reason);
        supervisor.killSync();
        dialog.showErrorBox(
            i18n.t('dialog.uncaught_error_title', getLocale()),
            reason && reason.stack ? reason.stack : String(reason),
        );
        app.exit(1);
    });
}

module.exports = { relaunch };
