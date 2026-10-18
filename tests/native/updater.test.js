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
    app:    { exit: jest.fn(), getLocale: jest.fn(() => 'en-US') },
    dialog: { showMessageBox: jest.fn() },
}));
jest.mock('../../native/paths', () => ({
    getRuntimeDir:                   jest.fn(() => '/fake/runtime'),
    getUserDataDir:                  jest.fn(() => '/fake/userData-does-not-exist'),
    getNativeTranslationsDir:        jest.fn(() => require('path').join(__dirname, '../../native/translations')),
    getNativeTranslationsOverlayDir: jest.fn(() => '/fake/userData-does-not-exist/native-translations'),
}));

const { createUpdater, CHECK_INTERVAL_HOURS } = require('../../native/updater');

function setup({ isReleaseBuild = true } = {}) {
    const calls = [];
    const handlers = {};
    const autoUpdater = {
        on:          jest.fn((event, h) => { handlers[event] = h; }),
        setFeedURL:  jest.fn(),
        checkForUpdates: jest.fn(() => Promise.resolve(null)),
        install:     jest.fn(() => { calls.push('install'); return true; }),
        quitAndInstall: jest.fn(),
    };
    let resolveStop;
    const stopApp = jest.fn(() => {
        calls.push('stop:start');
        return new Promise((resolve) => { resolveStop = () => { calls.push('stop:end'); resolve(); }; });
    });
    const exitApp = jest.fn(() => calls.push('exit'));
    const dialog  = { showMessageBox: jest.fn(() => Promise.resolve({ response: 1 })) };
    const logger  = { info: jest.fn(), warn: jest.fn(), error: jest.fn(), debug: jest.fn() };
    const updater = createUpdater({ autoUpdater, stopApp, exitApp, dialog, getLocale: () => 'en', isReleaseBuild, logger });
    return { updater, autoUpdater, handlers, calls, stopApp, exitApp, dialog, logger, stopResolve: () => resolveStop() };
}

const tick = () => new Promise((r) => setImmediate(r));

describe('install ordering', () => {
    test('the installer starts only after supervisor.stop() has finished, never via quitAndInstall()', async () => {
        const s = setup();
        s.dialog.showMessageBox.mockResolvedValue({ response: 0 });
        s.updater.start();
        s.handlers['update-downloaded']({ version: '2.0.0' });
        await tick();

        expect(s.stopApp).toHaveBeenCalledTimes(1);
        expect(s.autoUpdater.install).not.toHaveBeenCalled();

        s.stopResolve();
        await tick();

        expect(s.calls).toEqual(['stop:start', 'stop:end', 'install', 'exit']);
        expect(s.autoUpdater.install).toHaveBeenCalledWith(true, true);
        expect(s.autoUpdater.quitAndInstall).not.toHaveBeenCalled();
        s.updater.stop();
    });

    test('install-on-quit is off for the whole installNow() and install() runs once, after stopApp() resolves', async () => {
        const s = setup();
        s.autoUpdater.autoInstallOnAppQuit = true;
        s.autoUpdater.install.mockImplementation(() => {
            s.calls.push(`install:${s.autoUpdater.autoInstallOnAppQuit}`);
            return true;
        });

        const done = s.updater.installNow();
        expect(s.autoUpdater.autoInstallOnAppQuit).toBe(false);
        expect(s.autoUpdater.install).not.toHaveBeenCalled();

        s.stopResolve();
        await done;

        expect(s.autoUpdater.install).toHaveBeenCalledTimes(1);
        expect(s.autoUpdater.install).toHaveBeenCalledWith(true, true);
        expect(s.calls).toEqual(['stop:start', 'stop:end', 'install:false', 'exit']);
    });

    test('isInstalling() is false before installNow(), true right after the call and still true when install() runs', async () => {
        const s = setup();
        const seen = [];
        s.autoUpdater.install.mockImplementation(() => {
            seen.push(s.updater.isInstalling());
            return true;
        });
        expect(s.updater.isInstalling()).toBe(false);

        const done = s.updater.installNow();
        expect(s.updater.isInstalling()).toBe(true);

        s.stopResolve();
        await done;

        expect(seen).toEqual([true]);
    });

    test('a failed stopApp() does not install and disables install-on-quit before exit', async () => {
        const s = setup();
        s.stopApp.mockImplementation(() => Promise.reject(new Error('stop failed')));
        s.autoUpdater.autoInstallOnAppQuit = true;
        s.exitApp.mockImplementation(() => s.calls.push(`exit:${s.autoUpdater.autoInstallOnAppQuit}`));

        await s.updater.installNow();

        expect(s.autoUpdater.install).not.toHaveBeenCalled();
        expect(s.calls).toEqual(['exit:false']);
        expect(s.logger.error).toHaveBeenCalled();
    });
});

describe('release-build gate', () => {
    test('a build without the release flag neither configures nor checks, and the tray check says "disabled"', async () => {
        jest.useFakeTimers();
        const s = setup({ isReleaseBuild: false });
        s.updater.start();
        jest.advanceTimersByTime(CHECK_INTERVAL_HOURS * 3600 * 1000 * 2);
        jest.useRealTimers();

        expect(s.autoUpdater.checkForUpdates).not.toHaveBeenCalled();
        expect(s.autoUpdater.on).not.toHaveBeenCalled();

        await s.updater.checkManually();
        expect(s.autoUpdater.checkForUpdates).not.toHaveBeenCalled();
        expect(s.dialog.showMessageBox.mock.calls[0][0].message).toBe('Auto-update is disabled in test builds');
    });

    test('a release build checks at start and then every 6 hours', () => {
        jest.useFakeTimers();
        const s = setup();
        s.updater.start();
        expect(CHECK_INTERVAL_HOURS).toBe(6);
        expect(s.autoUpdater.checkForUpdates).toHaveBeenCalledTimes(1);
        jest.advanceTimersByTime(6 * 3600 * 1000);
        expect(s.autoUpdater.checkForUpdates).toHaveBeenCalledTimes(2);
        s.updater.stop();
        jest.advanceTimersByTime(12 * 3600 * 1000);
        expect(s.autoUpdater.checkForUpdates).toHaveBeenCalledTimes(2);
        jest.useRealTimers();
    });

    test('configures the github provider without prereleases and with background download', () => {
        const s = setup();
        s.updater.start();
        expect(s.autoUpdater.setFeedURL).toHaveBeenCalledWith({ provider: 'github', owner: 'anime-db', repo: 'anime-db-desktop' });
        expect(s.autoUpdater.allowPrerelease).toBe(false);
        expect(s.autoUpdater.autoDownload).toBe(true);
        expect(s.autoUpdater.logger).toBe(s.logger);
        s.updater.stop();
    });
});

describe('relaunch path', () => {
    test('disableInstallOnQuit() turns autoInstallOnAppQuit off', () => {
        const s = setup();
        s.updater.start();
        expect(s.autoUpdater.autoInstallOnAppQuit).toBe(true);
        s.updater.disableInstallOnQuit();
        expect(s.autoUpdater.autoInstallOnAppQuit).toBe(false);
        s.updater.stop();
    });
});

describe('relaunch vs install', () => {
    test('"Restart now" after disableInstallOnQuit() does not install, keeps isInstalling() false and leaves the exit to relaunch()', async () => {
        const s = setup();
        s.updater.disableInstallOnQuit();
        await s.updater.installNow();

        expect(s.autoUpdater.install).not.toHaveBeenCalled();
        expect(s.updater.isInstalling()).toBe(false);
        expect(s.exitApp).not.toHaveBeenCalled();
    });

    test('a relaunch() that starts while installNow() waits for stopApp() cancels install() and exits once', async () => {
        const s = setup();
        const done = s.updater.installNow();
        expect(s.updater.isInstalling()).toBe(true);
        s.updater.disableInstallOnQuit();
        s.stopResolve();
        await done;

        expect(s.autoUpdater.install).not.toHaveBeenCalled();
        expect(s.updater.isInstalling()).toBe(false);
        expect(s.exitApp).toHaveBeenCalledTimes(1);
    });
});

describe('ready dialog', () => {
    test('is async, shown once per version for automatic checks, but again for a manual check', async () => {
        const s = setup();
        s.updater.start();
        s.handlers['update-downloaded']({ version: '2.0.0' });
        await tick();
        s.handlers['update-downloaded']({ version: '2.0.0' });
        await tick();
        expect(s.dialog.showMessageBox).toHaveBeenCalledTimes(1);
        const opts = s.dialog.showMessageBox.mock.calls[0][0];
        expect(opts.message).toBe('Update v2.0.0 is ready');
        expect(opts.buttons).toEqual(['Restart now', 'Later']);

        s.autoUpdater.checkForUpdates.mockResolvedValue({ isUpdateAvailable: true, updateInfo: { version: '2.0.0' } });
        await s.updater.checkManually();
        expect(s.dialog.showMessageBox).toHaveBeenCalledTimes(2);
        expect(s.dialog.showMessageBox.mock.calls[1][0].message).toBe('Update v2.0.0 is ready');
        s.updater.stop();
    });

    test('"Later" does not stop or install anything', async () => {
        const s = setup();
        s.updater.start();
        s.handlers['update-downloaded']({ version: '2.0.0' });
        await tick();
        expect(s.stopApp).not.toHaveBeenCalled();
        expect(s.autoUpdater.install).not.toHaveBeenCalled();
        s.updater.stop();
    });
});

describe('errors', () => {
    test('a background check error is only logged, a manual one is shown with its text', async () => {
        const s = setup();
        s.autoUpdater.checkForUpdates.mockRejectedValue(new Error('rate limit exceeded'));
        s.updater.start();
        await tick();
        expect(s.logger.error).toHaveBeenCalled();
        expect(s.dialog.showMessageBox).not.toHaveBeenCalled();

        await s.updater.checkManually();
        expect(s.dialog.showMessageBox.mock.calls[0][0].message).toContain('rate limit exceeded');
        s.updater.stop();
    });

    test('a rejected downloadPromise of a background check is logged and not left unhandled', async () => {
        const s = setup();
        const err = new Error('sha512 checksum mismatch');
        const downloadPromise = Promise.reject(err);
        s.autoUpdater.checkForUpdates.mockResolvedValue({ isUpdateAvailable: true, updateInfo: { version: '2.0.0' }, downloadPromise });
        const unhandled = jest.fn();
        process.on('unhandledRejection', unhandled);
        s.updater.start();
        await tick();
        await tick();
        process.removeListener('unhandledRejection', unhandled);

        expect(unhandled).not.toHaveBeenCalled();
        expect(s.logger.error).toHaveBeenCalledWith(err);
        expect(s.dialog.showMessageBox).not.toHaveBeenCalled();
        s.updater.stop();
    });

    test('a rejected downloadPromise of a manual check is logged and shown in the error dialog', async () => {
        const s = setup();
        const err = new Error('sha512 checksum mismatch');
        s.autoUpdater.checkForUpdates.mockResolvedValue({ isUpdateAvailable: true, updateInfo: { version: '2.0.0' }, downloadPromise: Promise.reject(err) });
        const unhandled = jest.fn();
        process.on('unhandledRejection', unhandled);
        await s.updater.checkManually();
        await tick();
        await tick();
        process.removeListener('unhandledRejection', unhandled);

        expect(unhandled).not.toHaveBeenCalled();
        expect(s.logger.error).toHaveBeenCalledWith(err);
        const messages = s.dialog.showMessageBox.mock.calls.map(c => c[0].message);
        expect(messages.some(m => m.includes('sha512 checksum mismatch'))).toBe(true);
    });

    test('manual check reports "no updates" and "downloading"', async () => {
        const s = setup();
        s.updater.start();
        await s.updater.checkManually();
        expect(s.dialog.showMessageBox.mock.calls[0][0].message).toBe('No updates available');

        s.autoUpdater.checkForUpdates.mockResolvedValue({ isUpdateAvailable: true, updateInfo: { version: '3.0.0' } });
        await s.updater.checkManually();
        expect(s.dialog.showMessageBox.mock.calls[1][0].message).toBe('Downloading update v3.0.0...');
        s.updater.stop();
    });
});

describe('release build flag in package.json', () => {
    afterEach(() => {
        jest.dontMock('../../package.json');
        jest.dontMock('electron-updater');
    });

    function initWithPackage(pkg) {
        const fakeUpdater = {
            on:              jest.fn(),
            setFeedURL:      jest.fn(),
            checkForUpdates: jest.fn(() => Promise.resolve(null)),
        };
        let updaterModule;
        jest.isolateModules(() => {
            jest.doMock('../../package.json', () => pkg);
            jest.doMock('electron-updater', () => ({ autoUpdater: fakeUpdater }), { virtual: true });
            updaterModule = require('../../native/updater');
            updaterModule.init(jest.fn(() => Promise.resolve()));
        });
        return { updaterModule, fakeUpdater };
    }

    test.each([
        ['is missing', { name: 'x' }],
        ['is false', { name: 'x', releaseBuild: false }],
    ])('does not start the updater when the flag %s', (_label, pkg) => {
        const { updaterModule, fakeUpdater } = initWithPackage(pkg);
        updaterModule.start();
        expect(fakeUpdater.on).not.toHaveBeenCalled();
        expect(fakeUpdater.setFeedURL).not.toHaveBeenCalled();
        expect(fakeUpdater.checkForUpdates).not.toHaveBeenCalled();
        updaterModule.stop();
    });

    test('the module facade reports isInstalling() of the initialised instance and false without init', async () => {
        const fakeUpdater = {
            on:              jest.fn(),
            setFeedURL:      jest.fn(),
            checkForUpdates: jest.fn(() => Promise.resolve(null)),
            install:         jest.fn(() => true),
        };
        let updaterModule;
        let resolveStop;
        let instance;
        jest.isolateModules(() => {
            jest.doMock('../../package.json', () => ({ name: 'x', releaseBuild: true }));
            jest.doMock('electron-updater', () => ({ autoUpdater: fakeUpdater }), { virtual: true });
            updaterModule = require('../../native/updater');
            expect(updaterModule.isInstalling()).toBe(false);
            instance = updaterModule.init(jest.fn(() => new Promise((resolve) => { resolveStop = resolve; })));
        });
        expect(updaterModule.isInstalling()).toBe(false);

        const done = instance.installNow();
        expect(updaterModule.isInstalling()).toBe(true);

        resolveStop();
        await done;
    });

    test('starts the updater when the flag is true', () => {
        const { updaterModule, fakeUpdater } = initWithPackage({ name: 'x', releaseBuild: true });
        updaterModule.start();
        expect(fakeUpdater.checkForUpdates).toHaveBeenCalled();
        updaterModule.stop();
    });
});
