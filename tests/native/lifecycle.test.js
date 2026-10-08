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

jest.mock('electron', () => ({
    app: {
        requestSingleInstanceLock: jest.fn(() => true),
        on:         jest.fn(),
        whenReady:  jest.fn(() => Promise.resolve()),
        quit:       jest.fn(),
        exit:       jest.fn(),
        relaunch:   jest.fn(),
        getPath:    jest.fn(() => '/fake/userData'),
        getLocale:  jest.fn(() => 'ru-RU'),
    },
    dialog:  { showErrorBox: jest.fn(), showMessageBoxSync: jest.fn() },
    session: { defaultSession: {} },
    nativeTheme: { themeSource: 'system', shouldUseDarkColors: false, on: jest.fn() },
}));
jest.mock('fs', () => ({
    mkdirSync:      jest.fn(),
    appendFileSync: jest.fn(),
    existsSync:     jest.fn(() => false),
    // native/i18n reads the real native/translations/*.json catalogs off disk, so the mock
    // delegates rather than stubbing it out — everything else in this suite only ever calls
    // existsSync (config.js) or appendFileSync/mkdirSync (crash-log.js).
    readFileSync:   jest.requireActual('fs').readFileSync,
}));
jest.mock('../../native/protocols/app-media', () => ({}));
jest.mock('../../native/accept-language', () => ({}));
jest.mock('../../native/content-security-policy', () => ({}));
jest.mock('../../native/shell', () => ({ configure: jest.fn() }));
jest.mock('../../native/dialog', () => ({}));
jest.mock('../../native/catalog-export', () => ({}));
jest.mock('../../native/catalog-import', () => ({}));
jest.mock('../../native/import-v1', () => ({}));
jest.mock('../../native/backup-restore', () => ({}));
jest.mock('../../native/supervisor', () => ({
    start:              jest.fn(() => Promise.resolve({ frankenphpPort: 8000, wsPort: 8001 })),
    stop:               jest.fn(() => Promise.resolve()),
    killSync:           jest.fn(),
    reloadForPlugin:    jest.fn(() => Promise.resolve()),
    WORKERS_RELOAD_EVENT: 'workers.reload',
    events:             { on: jest.fn() },
}));
jest.mock('../../native/supervisor/safe-mode', () => ({
    beginStartAttempt:      jest.fn(() => false),
    commitDiagnosedFailure: jest.fn(),
}));
jest.mock('../../native/window', () => ({ createWindow: jest.fn() }));
jest.mock('../../native/window/splash', () => ({ createSplash: jest.fn() }));
jest.mock('../../native/tray', () => ({ create: jest.fn(), setState: jest.fn() }));
jest.mock('../../native/ws-client', () => ({ connect: jest.fn(), disconnect: jest.fn(), on: jest.fn() }));
jest.mock('../../native/proxy', () => ({
    registerProxyAuthHandler: jest.fn(),
    applyProxy:                jest.fn(() => Promise.resolve()),
    PROXY_CHANGED_EVENT:       'proxy.changed',
}));
jest.mock('../../native/firewall', () => ({
    applyIncomingConnections:      jest.fn(() => Promise.resolve()),
    FIREWALL_RULE_CHANGED_EVENT:   'firewall.rule.changed',
}));
jest.mock('../../native/theme', () => ({
    applyThemeSource:            jest.fn(),
    registerThemeUpdateHandler:  jest.fn(),
    THEME_CHANGED_EVENT:         'theme.changed',
}));

/**
 * Loads a fresh copy of lifecycle/index.js and every mocked dependency it pulls in, recording the
 * handlers it registers via app.on()/process.on() instead of letting them actually attach to the
 * real process — the module registers its startup logic as a side effect of being required, so
 * each test needs its own isolated instance of both the module and its mocks.
 *
 * @returns {object}
 */
function loadLifecycle() {
    jest.resetModules();

    const appHandlers     = {};
    const processHandlers = {};
    const wsClientHandlers = {};
    const supervisorEventHandlers = {};

    jest.spyOn(process, 'on').mockImplementation((event, handler) => {
        processHandlers[event] = handler;
        return process;
    });

    const { app, dialog }  = require('electron');
    app.on.mockImplementation((event, handler) => { appHandlers[event] = handler; });

    const supervisor   = require('../../native/supervisor');
    supervisor.events.on.mockImplementation((event, handler) => { supervisorEventHandlers[event] = handler; });
    const migrations   = require('../../native/supervisor/migrations');
    const cacheInvalidation = require('../../native/supervisor/cache-invalidation');
    const safeModeState = require('../../native/supervisor/safe-mode');
    const { createWindow } = require('../../native/window');
    const { createSplash } = require('../../native/window/splash');
    const tray        = require('../../native/tray');
    const wsClient     = require('../../native/ws-client');
    wsClient.on.mockImplementation((event, handler) => { wsClientHandlers[event] = handler; });
    const proxy        = require('../../native/proxy');
    const firewall      = require('../../native/firewall');
    const theme         = require('../../native/theme');

    const fakeWindow = {
        isMinimized: jest.fn(() => false),
        isDestroyed: jest.fn(() => false),
        show:        jest.fn(),
        hide:        jest.fn(),
        focus:       jest.fn(),
        restore:     jest.fn(),
        on:          jest.fn(),
        webContents: { send: jest.fn() },
    };
    createWindow.mockReturnValue(fakeWindow);

    const fakeSplash = {
        once:         jest.fn((event, cb) => cb()),
        show:         jest.fn(),
        close:        jest.fn(),
        isDestroyed:  jest.fn(() => false),
        webContents:  { send: jest.fn() },
    };
    createSplash.mockReturnValue(fakeSplash);

    const lifecycle = require('../../native/lifecycle');

    return {
        app, dialog, supervisor, migrations, cacheInvalidation, safeModeState, createWindow, createSplash, tray,
        wsClient, proxy, firewall, theme, appHandlers, processHandlers, wsClientHandlers, supervisorEventHandlers,
        fakeWindow, fakeSplash, relaunch: lifecycle.relaunch,
    };
}

afterEach(() => {
    jest.restoreAllMocks();
});

describe('single-instance lock', () => {
    test('a second instance quits immediately without registering startup handlers', () => {
        jest.resetModules();
        jest.spyOn(process, 'on').mockImplementation(() => process);

        const { app } = require('electron');
        app.requestSingleInstanceLock.mockReturnValue(false);
        const { registerProxyAuthHandler } = require('../../native/proxy');

        require('../../native/lifecycle');

        expect(app.quit).toHaveBeenCalledTimes(1);
        expect(app.on).not.toHaveBeenCalledWith('second-instance', expect.any(Function));
        expect(registerProxyAuthHandler).not.toHaveBeenCalled();
    });

    test('the primary instance registers a second-instance handler and starts the supervisor', async () => {
        const { supervisor, proxy } = loadLifecycle();

        expect(proxy.registerProxyAuthHandler).toHaveBeenCalledTimes(1);
        await new Promise((r) => setTimeout(r, 500));
        expect(supervisor.start).toHaveBeenCalledTimes(1);
    });

    test('a second launch attempt shows, restores and focuses the existing window', async () => {
        const { appHandlers, fakeWindow } = loadLifecycle();

        await new Promise((r) => setTimeout(r, 500));

        fakeWindow.isMinimized.mockReturnValue(true);
        appHandlers['second-instance']();

        expect(fakeWindow.restore).toHaveBeenCalledTimes(1);
        expect(fakeWindow.show).toHaveBeenCalledTimes(1);
        expect(fakeWindow.focus).toHaveBeenCalledTimes(1);
    });

    test('a second-instance signal before the window exists is a no-op, not a crash', () => {
        const { appHandlers, fakeWindow } = loadLifecycle();

        expect(() => appHandlers['second-instance']()).not.toThrow();
        expect(fakeWindow.show).not.toHaveBeenCalled();
    });
});

describe('safe mode prompt (issue #403)', () => {
    test('does not prompt and starts normally when beginStartAttempt() reports no unclosed streak', async () => {
        const { supervisor, dialog, safeModeState } = loadLifecycle();
        safeModeState.beginStartAttempt.mockReturnValue(false);

        await new Promise((r) => setTimeout(r, 500));

        expect(dialog.showMessageBoxSync).not.toHaveBeenCalled();
        expect(supervisor.start).toHaveBeenCalledWith(expect.any(Function), { safeMode: false, confirmStagedImport: expect.any(Function) });
    });

    test('two unclosed starts in a row prompt a dialog before the kernel starts', async () => {
        const { supervisor, dialog, safeModeState } = loadLifecycle();
        safeModeState.beginStartAttempt.mockReturnValue(true);
        dialog.showMessageBoxSync.mockReturnValue(0);

        await new Promise((r) => setTimeout(r, 500));

        expect(dialog.showMessageBoxSync).toHaveBeenCalledTimes(1);
        expect(supervisor.start).toHaveBeenCalledWith(expect.any(Function), { safeMode: true, confirmStagedImport: expect.any(Function) });
    });

    test('choosing "Exit" quits without ever starting the kernel', async () => {
        const { app, supervisor, dialog, safeModeState, createSplash } = loadLifecycle();
        safeModeState.beginStartAttempt.mockReturnValue(true);
        dialog.showMessageBoxSync.mockReturnValue(1);

        await new Promise((r) => setTimeout(r, 500));

        expect(supervisor.start).not.toHaveBeenCalled();
        expect(createSplash).not.toHaveBeenCalled();
        expect(app.quit).toHaveBeenCalledTimes(1);
    });

    test('shows Russian text for a "ru" locale', async () => {
        const { dialog, safeModeState } = loadLifecycle();
        safeModeState.beginStartAttempt.mockReturnValue(true);
        dialog.showMessageBoxSync.mockReturnValue(0);

        await new Promise((r) => setTimeout(r, 500));

        expect(dialog.showMessageBoxSync).toHaveBeenCalledWith(
            expect.objectContaining({
                title:   'Не удаётся запустить приложение',
                buttons: ['Запустить без плагинов', 'Выход'],
            }),
        );
    });

    test('shows English text for an "en" locale', async () => {
        const { app, dialog, safeModeState } = loadLifecycle();
        app.getLocale.mockReturnValue('en-US');
        safeModeState.beginStartAttempt.mockReturnValue(true);
        dialog.showMessageBoxSync.mockReturnValue(0);

        await new Promise((r) => setTimeout(r, 500));

        expect(dialog.showMessageBoxSync).toHaveBeenCalledWith(
            expect.objectContaining({
                title:   'The app failed to start',
                buttons: ['Run without plugins', 'Exit'],
            }),
        );
    });

    // issue #177: mapOsLocaleToAppLocale() falls back unmapped OS locales to "ru", not "en" —
    // a manual locale.startsWith('ru') check (the pre-fix implementation) would get this wrong.
    test('falls back to Russian text for an unmapped "kk" locale', async () => {
        const { app, dialog, safeModeState } = loadLifecycle();
        app.getLocale.mockReturnValue('kk-KZ');
        safeModeState.beginStartAttempt.mockReturnValue(true);
        dialog.showMessageBoxSync.mockReturnValue(0);

        await new Promise((r) => setTimeout(r, 500));

        expect(dialog.showMessageBoxSync).toHaveBeenCalledWith(
            expect.objectContaining({ title: 'Не удаётся запустить приложение' }),
        );
    });
});

// Issue #706: the supervisor's startup decision step (native/supervisor/staged-import.js) takes a
// confirmStagedImport callback instead of importing `dialog` itself — lifecycle/index.js supplies
// the actual implementation, so these scenarios are exercised here rather than against the
// supervisor mock.
describe('staged import stale confirmation (issue #706)', () => {
    function getConfirmCallback(supervisor) {
        const [, options] = supervisor.start.mock.calls[0];
        return options.confirmStagedImport;
    }

    test('asks via a dialog naming the source archive and staged date, and confirms on the "Apply" button', async () => {
        const { supervisor, dialog } = loadLifecycle();
        await new Promise((r) => setTimeout(r, 500));
        dialog.showMessageBoxSync.mockReturnValue(0);

        const confirmed = await getConfirmCallback(supervisor)({ stagedAt: '2026-09-18T12:34:56Z', sourceArchive: 'catalog-export.zip' });

        expect(confirmed).toBe(true);
        expect(dialog.showMessageBoxSync).toHaveBeenCalledWith(
            expect.objectContaining({
                buttons: ['Применить импорт', 'Отклонить'],
                message: expect.stringContaining('catalog-export.zip'),
            }),
        );
    });

    test('declines on the "Discard" button', async () => {
        const { supervisor, dialog } = loadLifecycle();
        await new Promise((r) => setTimeout(r, 500));
        dialog.showMessageBoxSync.mockReturnValue(1);

        const confirmed = await getConfirmCallback(supervisor)({ stagedAt: '2026-09-18T12:34:56Z', sourceArchive: 'catalog-export.zip' });

        expect(confirmed).toBe(false);
    });

    test('declines when the dialog is dismissed without a button choice (cancelId)', async () => {
        const { supervisor, dialog } = loadLifecycle();
        await new Promise((r) => setTimeout(r, 500));
        dialog.showMessageBoxSync.mockReturnValue(undefined);

        const confirmed = await getConfirmCallback(supervisor)({ stagedAt: '2026-09-18T12:34:56Z', sourceArchive: 'catalog-export.zip' });

        expect(confirmed).toBe(false);
    });

    test('shows English button text for an "en" locale', async () => {
        const { app, supervisor, dialog } = loadLifecycle();
        app.getLocale.mockReturnValue('en-US');
        await new Promise((r) => setTimeout(r, 500));
        dialog.showMessageBoxSync.mockReturnValue(0);

        await getConfirmCallback(supervisor)({ stagedAt: '2026-09-18T12:34:56Z', sourceArchive: 'catalog-export.zip' });

        expect(dialog.showMessageBoxSync).toHaveBeenCalledWith(
            expect.objectContaining({ buttons: ['Apply import', 'Discard'] }),
        );
    });
});

describe('migration bootstrap errors', () => {
    test('a downgrade error shows a localized dialog naming the user data folder and quits without opening the window', async () => {
        const { supervisor, migrations, dialog, app, createWindow, fakeSplash, safeModeState } = loadLifecycle();
        supervisor.start.mockRejectedValue(new migrations.MigrationBootstrapError('downgrade'));

        await new Promise((r) => setTimeout(r, 500));

        expect(dialog.showErrorBox).toHaveBeenCalledWith(
            expect.any(String),
            expect.stringContaining('/fake/userData'),
        );
        expect(fakeSplash.close).toHaveBeenCalled();
        expect(createWindow).not.toHaveBeenCalled();
        expect(app.quit).toHaveBeenCalled();
        // Diagnosed failure — must not count towards the safe-mode streak (issue #403 review).
        expect(safeModeState.commitDiagnosedFailure).toHaveBeenCalledTimes(1);
    });

    test('a migrate-failed error shows a dialog including the underlying error detail, log and backup paths', async () => {
        const { supervisor, migrations, dialog, safeModeState } = loadLifecycle();
        supervisor.start.mockRejectedValue(
            new migrations.MigrationBootstrapError('migrate-failed', 'boom', '/fake/userData/backups/data-1.db', '/fake/userData/var/log/migrations-2026-08-14.log'),
        );

        await new Promise((r) => setTimeout(r, 500));

        expect(dialog.showErrorBox).toHaveBeenCalledWith(
            expect.any(String),
            expect.stringContaining('boom'),
        );
        const [, message] = dialog.showErrorBox.mock.calls[0];
        expect(message).toContain('/fake/userData/backups/data-1.db');
        expect(message).toContain('/fake/userData/var/log/migrations-2026-08-14.log');
        expect(safeModeState.commitDiagnosedFailure).toHaveBeenCalledTimes(1);
    });
});

// Issue #707: a staged import that had to be rolled back must not stop the app from starting
// (supervisor.start() already restored the previous catalog and its index) — it only shows a
// dialog telling the user, the same way the migration-bootstrap dialogs above do for their own
// failures, but without quitting the app afterwards.
describe('staged import rollback (issue #707)', () => {
    test('shows a dialog and still opens the window when supervisor.start() reports importFailed:true', async () => {
        const { supervisor, dialog, app, createWindow } = loadLifecycle();
        supervisor.start.mockResolvedValue({ frankenphpPort: 8000, wsPort: 8001, importFailed: true });

        await new Promise((r) => setTimeout(r, 500));

        expect(dialog.showErrorBox).toHaveBeenCalledWith(
            'Не удалось применить импорт',
            'Импорт каталога не был применён из-за ошибки. Прежний каталог и его поисковый индекс восстановлены, приложение запущено в обычном режиме.',
        );
        expect(createWindow).toHaveBeenCalledTimes(1);
        expect(app.quit).not.toHaveBeenCalled();
    });

    test('shows no dialog when supervisor.start() reports importFailed:false', async () => {
        const { supervisor, dialog } = loadLifecycle();
        supervisor.start.mockResolvedValue({ frankenphpPort: 8000, wsPort: 8001, importFailed: false });

        await new Promise((r) => setTimeout(r, 500));

        expect(dialog.showErrorBox).not.toHaveBeenCalled();
    });

    test('shows English text for an "en" locale', async () => {
        const { app, supervisor, dialog } = loadLifecycle();
        app.getLocale.mockReturnValue('en-US');
        supervisor.start.mockResolvedValue({ frankenphpPort: 8000, wsPort: 8001, importFailed: true });

        await new Promise((r) => setTimeout(r, 500));

        expect(dialog.showErrorBox).toHaveBeenCalledWith(
            'Import failed',
            'The catalog import could not be applied due to an error. The previous catalog and its search index have been restored, and the app has started normally.',
        );
    });
});

describe('cache invalidation errors (issue #403 review)', () => {
    test('a CacheInvalidationError does not count towards the safe-mode streak', async () => {
        const { supervisor, cacheInvalidation, dialog, app, safeModeState } = loadLifecycle();
        supervisor.start.mockRejectedValue(new cacheInvalidation.CacheInvalidationError('EPERM: locked'));

        await new Promise((r) => setTimeout(r, 500));

        expect(dialog.showErrorBox).toHaveBeenCalledWith('Ошибка запуска', 'EPERM: locked');
        expect(app.quit).toHaveBeenCalled();
        expect(safeModeState.commitDiagnosedFailure).toHaveBeenCalledTimes(1);
    });

    test('an undiagnosed startup error does count towards the safe-mode streak', async () => {
        const { supervisor, dialog, safeModeState } = loadLifecycle();
        supervisor.start.mockRejectedValue(new Error('unexplained crash'));

        await new Promise((r) => setTimeout(r, 500));

        expect(dialog.showErrorBox).toHaveBeenCalledWith('Ошибка запуска', 'unexplained crash');
        expect(safeModeState.commitDiagnosedFailure).not.toHaveBeenCalled();
    });
});

describe('relaunch (issue #656)', () => {
    test('relaunch() restarts the app via app.relaunch() + app.quit(), which gracefully tears down the supervisor before exiting', async () => {
        const { app, wsClient, supervisor, appHandlers, relaunch } = loadLifecycle();
        await new Promise((r) => setTimeout(r, 500));

        relaunch();

        expect(app.relaunch).toHaveBeenCalledTimes(1);
        expect(app.quit).toHaveBeenCalledTimes(1);

        appHandlers['before-quit']({ preventDefault: jest.fn() });
        await new Promise((r) => setTimeout(r, 0));

        expect(wsClient.disconnect).toHaveBeenCalledTimes(1);
        expect(supervisor.stop).toHaveBeenCalledTimes(1);
        expect(app.exit).toHaveBeenCalledWith(0);
    });

    test('relaunch() bypasses the tray-hide close handler, even for a window already hidden to tray', async () => {
        const { relaunch, fakeWindow } = loadLifecycle();
        await new Promise((r) => setTimeout(r, 500));

        const closeHandler = fakeWindow.on.mock.calls.find(([event]) => event === 'close')[1];

        relaunch();

        const preventDefault = jest.fn();
        closeHandler({ preventDefault });

        expect(preventDefault).not.toHaveBeenCalled();
        expect(fakeWindow.hide).not.toHaveBeenCalled();
    });
});

describe('abnormal-exit cleanup', () => {
    test('process exit runs a synchronous best-effort kill of all child processes', () => {
        const { processHandlers, supervisor } = loadLifecycle();

        processHandlers.exit();

        expect(supervisor.killSync).toHaveBeenCalledTimes(1);
    });

    test('SIGTERM/SIGINT trigger the same graceful quit path as the tray "Выход" action', () => {
        const { processHandlers, app } = loadLifecycle();

        processHandlers.SIGTERM();
        expect(app.quit).toHaveBeenCalledTimes(1);

        processHandlers.SIGINT();
        expect(app.quit).toHaveBeenCalledTimes(2);
    });

    test('an uncaught exception logs the crash, shows a dialog and force-kills children before exiting', () => {
        const { processHandlers, supervisor, app, dialog } = loadLifecycle();
        const fs = require('fs');

        processHandlers.uncaughtException(new Error('boom'));

        expect(fs.appendFileSync).toHaveBeenCalledWith(
            expect.stringContaining('main-'),
            expect.stringContaining('boom'),
        );
        expect(dialog.showErrorBox).toHaveBeenCalledWith(expect.any(String), expect.stringContaining('boom'));
        expect(supervisor.killSync).toHaveBeenCalledTimes(1);
        expect(app.exit).toHaveBeenCalledWith(1);
    });

    test('an unhandled rejection logs the crash, shows a dialog and force-kills children before exiting', () => {
        const { processHandlers, supervisor, app, dialog } = loadLifecycle();
        const fs = require('fs');

        processHandlers.unhandledRejection(new Error('boom'));

        expect(fs.appendFileSync).toHaveBeenCalledWith(
            expect.stringContaining('main-'),
            expect.stringContaining('boom'),
        );
        expect(dialog.showErrorBox).toHaveBeenCalledWith(expect.any(String), expect.stringContaining('boom'));
        expect(supervisor.killSync).toHaveBeenCalledTimes(1);
        expect(app.exit).toHaveBeenCalledWith(1);
    });

    test('an unhandled rejection with a non-Error reason still shows a dialog without throwing', () => {
        const { processHandlers, supervisor, app, dialog } = loadLifecycle();

        expect(() => processHandlers.unhandledRejection('boom')).not.toThrow();

        expect(dialog.showErrorBox).toHaveBeenCalledWith(expect.any(String), 'boom');
        expect(supervisor.killSync).toHaveBeenCalledTimes(1);
        expect(app.exit).toHaveBeenCalledWith(1);
    });
});

describe('startup failure', () => {
    test('a rejected supervisor.start() logs the crash before showing the dialog and quitting', async () => {
        const { supervisor, app, dialog } = loadLifecycle();
        supervisor.start.mockRejectedValue(new Error('boom-startup'));
        const fs = require('fs');

        await new Promise((r) => setTimeout(r, 500));

        expect(fs.appendFileSync).toHaveBeenCalledWith(
            expect.stringContaining('main-'),
            expect.stringContaining('boom-startup'),
        );
        expect(dialog.showErrorBox).toHaveBeenCalledWith('Ошибка запуска', 'boom-startup');
        expect(app.quit).toHaveBeenCalledTimes(1);
    });
});

// issue #404: native/ text is now sourced from native/translations/ via native/i18n, resolved
// through the same getLocale() the rest of the app already uses.
describe('locale-aware text', () => {
    test('splash progress and the startup-error dialog show Russian text for a "ru" locale', async () => {
        const { supervisor, dialog, fakeSplash } = loadLifecycle();
        supervisor.start.mockRejectedValue(new Error('boom-ru'));

        await new Promise((r) => setTimeout(r, 500));

        expect(fakeSplash.webContents.send).toHaveBeenCalledWith(
            'splash-progress',
            expect.objectContaining({ step: 0, text: 'Запуск Meilisearch...' }),
        );
        expect(dialog.showErrorBox).toHaveBeenCalledWith('Ошибка запуска', 'boom-ru');
    });

    test('splash progress and the startup-error dialog show English text for an "en" locale', async () => {
        const { app, supervisor, dialog, fakeSplash } = loadLifecycle();
        app.getLocale.mockReturnValue('en-US');
        supervisor.start.mockRejectedValue(new Error('boom-en'));

        await new Promise((r) => setTimeout(r, 500));

        expect(fakeSplash.webContents.send).toHaveBeenCalledWith(
            'splash-progress',
            expect.objectContaining({ step: 0, text: 'Starting Meilisearch...' }),
        );
        expect(dialog.showErrorBox).toHaveBeenCalledWith('Startup error', 'boom-en');
    });

    test('a downgrade migration dialog is shown in English for an "en" locale', async () => {
        const { app, supervisor, migrations, dialog } = loadLifecycle();
        app.getLocale.mockReturnValue('en-US');
        supervisor.start.mockRejectedValue(new migrations.MigrationBootstrapError('downgrade'));

        await new Promise((r) => setTimeout(r, 500));

        expect(dialog.showErrorBox).toHaveBeenCalledWith(
            'Incompatible data version',
            expect.stringContaining('/fake/userData'),
        );
    });
});

// issue #411: PHP publishes WORKERS_RELOAD_EVENT over /ws after a plugin install's isolated
// cache warm-up succeeds; lifecycle/index.js routes it to supervisor.reloadForPlugin() the same
// way it already routes proxy.changed/firewall.rule.changed.
describe('plugin activation (issue #411)', () => {
    test('routes WORKERS_RELOAD_EVENT to supervisor.reloadForPlugin() with the plugin id', async () => {
        const { supervisor, wsClientHandlers } = loadLifecycle();
        await new Promise((r) => setTimeout(r, 500));

        wsClientHandlers['backend-event']({ event: 'workers.reload', data: { pluginId: 'animedb-shikimori' } });

        expect(supervisor.reloadForPlugin).toHaveBeenCalledWith('animedb-shikimori');
    });

    test('a rejected reloadForPlugin() is logged, not thrown', async () => {
        const { supervisor, wsClientHandlers } = loadLifecycle();
        supervisor.reloadForPlugin.mockRejectedValue(new Error('boom'));
        const consoleError = jest.spyOn(console, 'error').mockImplementation(() => {});
        await new Promise((r) => setTimeout(r, 500));

        expect(() => wsClientHandlers['backend-event']({ event: 'workers.reload', data: { pluginId: 'animedb-shikimori' } })).not.toThrow();
        await new Promise((r) => setTimeout(r, 0));

        expect(consoleError).toHaveBeenCalledWith('[supervisor] не удалось активировать плагин:', expect.any(Error));
        consoleError.mockRestore();
    });

    // issue #417: a blocking dialog.showErrorBox is the wrong surface for a background event —
    // it interrupts the user for something that has already been rolled back to a working state.
    // The event is now routed to the window as a non-blocking, localized in-app notification.
    test('a plugin-activation-failed event sends a localized in-app notification naming the plugin, not a blocking dialog', async () => {
        const { dialog, fakeWindow, supervisorEventHandlers } = loadLifecycle();
        await new Promise((r) => setTimeout(r, 500));

        supervisorEventHandlers['plugin-activation-failed']({ pluginId: 'animedb-shikimori' });

        expect(fakeWindow.webContents.send).toHaveBeenCalledWith('app-notification', {
            type:    'plugin-activation-failed',
            pluginId: 'animedb-shikimori',
            title:   'Активация плагина',
            message: 'Не удалось активировать плагин «animedb-shikimori». Приложение восстановлено в рабочее состояние без него.',
        });
        expect(dialog.showErrorBox).not.toHaveBeenCalled();
    });

    test('a plugin-activation-failed event after the window is destroyed does not throw', async () => {
        const { fakeWindow, supervisorEventHandlers } = loadLifecycle();
        await new Promise((r) => setTimeout(r, 500));
        fakeWindow.isDestroyed.mockReturnValue(true);

        expect(() => supervisorEventHandlers['plugin-activation-failed']({ pluginId: 'animedb-shikimori' })).not.toThrow();
        expect(fakeWindow.webContents.send).not.toHaveBeenCalled();
    });
});

// issue #816: nativeTheme.themeSource must follow themePreference from config.json, set before
// the safe-mode dialog and before createSplash() — otherwise the splash's own prefers-color-scheme
// (see native/splash/splash.html) would still reflect the previous/default theme.
describe('theme (issue #816)', () => {
    test('applyThemeSource() runs before createSplash()', async () => {
        const { theme, createSplash } = loadLifecycle();
        await new Promise((r) => setTimeout(r, 500));

        expect(theme.applyThemeSource).toHaveBeenCalledTimes(1);
        expect(theme.applyThemeSource.mock.invocationCallOrder[0])
            .toBeLessThan(createSplash.mock.invocationCallOrder[0]);
    });

    test('registerThemeUpdateHandler() is called with a callback returning the live splash and main window', async () => {
        const { theme, fakeSplash, fakeWindow } = loadLifecycle();
        await new Promise((r) => setTimeout(r, 500));

        expect(theme.registerThemeUpdateHandler).toHaveBeenCalledWith(expect.any(Function));
        const getWindows = theme.registerThemeUpdateHandler.mock.calls[0][0];
        expect(getWindows()).toEqual(expect.arrayContaining([fakeSplash, fakeWindow]));
    });

    test('routes THEME_CHANGED_EVENT to theme.applyThemeSource() again', async () => {
        const { theme, wsClientHandlers } = loadLifecycle();
        await new Promise((r) => setTimeout(r, 500));
        theme.applyThemeSource.mockClear();

        wsClientHandlers['backend-event']({ event: 'theme.changed', data: { theme: 'dark' } });

        expect(theme.applyThemeSource).toHaveBeenCalledTimes(1);
    });
});
