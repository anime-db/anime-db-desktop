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

const fs   = require('fs');
const path = require('path');
const { app, dialog } = require('electron');
const paths = require('../paths');
const i18n  = require('../i18n');
const { getLocale } = require('../config');
const { todayStr, pruneOldLogs } = require('../supervisor/logrotate');

/** Периодичность автоматической проверки обновлений, в часах. */
const CHECK_INTERVAL_HOURS = 6;

/** Сколько файлов updater-<дата>.log хранить. */
const LOG_MAX = 7;

/** Поле package.json, которое scripts/build.js штампует на каждой сборке (true — сборка по тегу vX.Y.Z). */
const RELEASE_BUILD_FIELD = 'releaseBuild';

/**
 * Логгер electron-updater в файл <runtimeDir>/log/updater-<дата>.log: console в GUI-сборке никуда
 * не выводится. Best-effort — сбой записи не должен ломать апдейтер.
 *
 * @returns {{ info: Function, warn: Function, error: Function, debug: Function }}
 */
function createFileLogger() {
    const write = (level) => (...args) => {
        try {
            const logDir = path.join(paths.getRuntimeDir(), 'log');
            fs.mkdirSync(logDir, { recursive: true });
            const text = args.map(a => (a && a.stack ? a.stack : String(a))).join(' ');
            fs.appendFileSync(
                path.join(logDir, `updater-${todayStr()}.log`),
                `[${new Date().toISOString()}] [${level}] ${text}\n`,
            );
        } catch {
            // лог необязателен
        }
    };
    return { info: write('info'), warn: write('warn'), error: write('error'), debug: write('debug') };
}

/**
 * @returns {boolean} true, если эта сборка собрана по релизному тегу (см. scripts/build.js)
 */
function readReleaseBuildFlag() {
    try {
        return require('../../package.json')[RELEASE_BUILD_FIELD] === true;
    } catch {
        return false;
    }
}

/**
 * @param {object} deps
 * @param {object} deps.autoUpdater          экземпляр electron-updater
 * @param {Function} deps.stopApp            async: graceful-остановка (ws, watcher, supervisor.stop())
 * @param {Function} [deps.exitApp]          завершает процесс (app.exit(0))
 * @param {object} [deps.dialog]
 * @param {Function} [deps.getLocale]
 * @param {boolean} [deps.isReleaseBuild]
 * @param {object} [deps.logger]
 */
function createUpdater({
    autoUpdater,
    stopApp,
    exitApp = () => app.exit(0),
    dialog: dlg = dialog,
    getLocale: locale = getLocale,
    isReleaseBuild = readReleaseBuildFlag(),
    logger = createFileLogger(),
}) {
    const shownVersions = new Set();
    let downloadedVersion = null;
    let manualCheck = false;
    let shownDuringManual = false;
    let dialogOpen = false;
    let timer = null;
    let started = false;
    let installing = false;
    let installBlocked = false;

    const t = (key, params) => i18n.t(key, locale(), params);

    /**
     * «Перезапустить сейчас»: инсталлятор стартует строго ПОСЛЕ завершения supervisor.stop() —
     * иначе NSIS убьёт его дочерние процессы, держащие файлы каталога установки. Прямой
     * autoUpdater.quitAndInstall() не используется: он спавнит инсталлятор синхронно, до stop().
     */
    async function installNow() {
        // Установка заблокирована: идёт relaunch() или остановка не удалась. Обновление останется скачанным.
        if (installing || installBlocked) return;
        installing = true;
        // Штатная установка на 'quit' (в т.ч. после неудачной остановки) не должна стартовать инсталлятор раньше install() ниже.
        autoUpdater.autoInstallOnAppQuit = false;
        try {
            await stopApp();
            // Установка могла быть заблокирована, пока шла остановка: install() не вызываем, выход доводим штатно.
            if (installBlocked) {
                installing = false;
                exitApp();
                return;
            }
            // install() синхронен и сам помечает, что установка уже запущена, поэтому событие
            // 'quit' от exitApp() ниже второй раз инсталлятор не стартует.
            if (!autoUpdater.install(true, true)) {
                logger.error('Installer was not started');
            }
        } catch (err) {
            logger.error(err);
        }
        exitApp();
    }

    async function showReadyDialog(version) {
        if (dialogOpen) return;
        dialogOpen = true;
        shownVersions.add(version);
        try {
            const { response } = await dlg.showMessageBox({
                type:      'info',
                buttons:   [t('updater.restart_now'), t('updater.later')],
                defaultId: 0,
                cancelId:  1,
                title:     t('updater.title'),
                message:   t('updater.ready', { version }),
            });
            if (response === 0) await installNow();
        } catch (err) {
            logger.error(err);
        } finally {
            dialogOpen = false;
        }
    }

    function showInfo(message, type = 'info') {
        return dlg.showMessageBox({ type, buttons: ['OK'], title: t('updater.title'), message })
            .catch(err => logger.error(err));
    }

    function onDownloaded(info) {
        downloadedVersion = info.version;
        if (manualCheck) {
            shownDuringManual = true;
            showReadyDialog(info.version);
        } else if (!shownVersions.has(info.version)) {
            showReadyDialog(info.version);
        }
    }

    function configure() {
        autoUpdater.logger = logger;
        autoUpdater.allowPrerelease = false;
        autoUpdater.autoDownload = true;
        autoUpdater.autoInstallOnAppQuit = true;
        autoUpdater.setFeedURL({ provider: 'github', owner: 'anime-db', repo: 'anime-db-desktop' });
        autoUpdater.on('update-downloaded', onDownloaded);
        autoUpdater.on('error', err => logger.error(err));
    }

    /** Фоновая проверка: ошибки только в лог. */
    async function backgroundCheck() {
        try {
            const result = await autoUpdater.checkForUpdates();
            // Ошибка загрузки (autoDownload) приходит отклонённым downloadPromise — без обработчика это unhandledRejection.
            if (result && result.downloadPromise) {
                result.downloadPromise.catch(err => logger.error(err));
            }
        } catch (err) {
            logger.error(err);
        }
    }

    /** Проверка из трея: результат всегда виден пользователю. */
    async function checkManually() {
        if (!isReleaseBuild) {
            await showInfo(t('updater.disabled'));
            return;
        }
        manualCheck = true;
        shownDuringManual = false;
        try {
            const result = await autoUpdater.checkForUpdates();
            if (result && result.downloadPromise) {
                result.downloadPromise.catch(err => {
                    logger.error(err);
                    return showInfo(t('updater.error', { message: err && err.message ? err.message : String(err) }), 'error');
                });
            }
            const info = result && result.updateInfo;
            if (!result || !result.isUpdateAvailable || !info) {
                await showInfo(t('updater.up_to_date'));
            } else if (downloadedVersion === info.version) {
                if (!shownDuringManual) await showReadyDialog(info.version);
            } else {
                await showInfo(t('updater.downloading', { version: info.version }));
            }
        } catch (err) {
            logger.error(err);
            await showInfo(t('updater.error', { message: err && err.message ? err.message : String(err) }), 'error');
        } finally {
            manualCheck = false;
        }
    }

    /** Запускает автопроверки (сразу и раз в CHECK_INTERVAL_HOURS). Для тестовой сборки — no-op. */
    function start() {
        if (!isReleaseBuild || started) return;
        started = true;
        try {
            pruneOldLogs(path.join(paths.getRuntimeDir(), 'log'), 'updater', LOG_MAX);
        } catch {
            // лог необязателен
        }
        configure();
        backgroundCheck();
        timer = setInterval(backgroundCheck, CHECK_INTERVAL_HOURS * 3600 * 1000);
        if (timer.unref) timer.unref();
    }

    function stop() {
        if (timer) clearInterval(timer);
        timer = null;
    }

    /** Путь relaunch() и неудачной остановки: установка при выходе блокируется (иначе инсталлятор убил бы новый экземпляр). */
    function disableInstallOnQuit() {
        installBlocked = true;
        if (started) autoUpdater.autoInstallOnAppQuit = false;
    }

    return { start, stop, checkManually, disableInstallOnQuit, installNow, isInstalling: () => installing, isReleaseBuild };
}

let instance = null;

/**
 * Синглтон для lifecycle; electron-updater подгружается лениво и только в релизной сборке.
 *
 * @param {Function} stopApp
 */
function init(stopApp) {
    const isReleaseBuild = readReleaseBuildFlag();
    instance = createUpdater({
        autoUpdater: isReleaseBuild ? require('electron-updater').autoUpdater : null,
        stopApp,
        isReleaseBuild,
    });
    return instance;
}

module.exports = {
    CHECK_INTERVAL_HOURS,
    RELEASE_BUILD_FIELD,
    createUpdater,
    init,
    start:                () => instance && instance.start(),
    stop:                 () => instance && instance.stop(),
    checkManually:        () => (instance ? instance.checkManually() : Promise.resolve()),
    disableInstallOnQuit: () => instance && instance.disableInstallOnQuit(),
    isInstalling:         () => Boolean(instance && instance.isInstalling()),
};
