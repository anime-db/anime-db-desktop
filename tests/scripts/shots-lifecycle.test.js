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
const os   = require('os');
const path = require('path');

const {
    DEFAULT_TIMEOUT_MS, UNKNOWN_PAGE, LastPageTracker, resolveTimeoutMs,
    formatLastPageLine, formatTimeoutMessage, saveFailureArtifacts, RunWatchdog, KILL_GRACE_MS, PID_MARKER,
} = require('../../scripts/shots/lifecycle');

describe('resolveTimeoutMs', () => {
    test('defaults to a value below the 20 minute job limit', () => {
        expect(resolveTimeoutMs({})).toBe(DEFAULT_TIMEOUT_MS);
        expect(DEFAULT_TIMEOUT_MS).toBeLessThan(20 * 60 * 1000);
    });

    test('SHOTS_TIMEOUT_MS overrides it; garbage is ignored', () => {
        expect(resolveTimeoutMs({ SHOTS_TIMEOUT_MS: '1500' })).toBe(1500);
        expect(resolveTimeoutMs({ SHOTS_TIMEOUT_MS: 'abc' })).toBe(DEFAULT_TIMEOUT_MS);
        expect(resolveTimeoutMs({ SHOTS_TIMEOUT_MS: '-5' })).toBe(DEFAULT_TIMEOUT_MS);
    });
});

describe('LastPageTracker', () => {
    test('is unknown before any marker', () => {
        const tracker = new LastPageTracker();
        tracker.push('[shots] light/catalog.png\n');
        expect(tracker.describe()).toBe(UNKNOWN_PAGE);
    });

    test('keeps the latest marker, also across chunk boundaries', () => {
        const tracker = new LastPageTracker();
        tracker.push(`${formatLastPageLine('light/catalog (http://x/)')}\n[shots] light/catalog.png\n`);
        tracker.push(formatLastPageLine('light/sto'));
        tracker.push('rage (http://x/storage)\n');
        expect(tracker.describe()).toBe('light/storage (http://x/storage)');
    });

    test('a marker without trailing newline is still seen', () => {
        const tracker = new LastPageTracker();
        tracker.push(formatLastPageLine('dark/settings'));
        expect(tracker.describe()).toBe('dark/settings');
    });
});

test('formatTimeoutMessage names the timeout and the last page', () => {
    expect(formatTimeoutMessage(2500, 'light/catalog')).toBe(
        'прогон снимков превысил таймаут 3 с, последняя страница: light/catalog',
    );
});

describe('saveFailureArtifacts', () => {
    let dir;
    beforeEach(() => { dir = fs.mkdtempSync(path.join(os.tmpdir(), 'shots-')); });
    afterEach(() => { fs.rmSync(dir, { recursive: true, force: true }); });

    test('writes the PNG and the DOM dump of the failed page', async () => {
        const webContents = {
            capturePage:       async () => ({ toPNG: () => Buffer.from('png') }),
            executeJavaScript: async () => '<html>dom</html>',
        };
        await saveFailureArtifacts(webContents, path.join(dir, 'light'), 'catalog');

        expect(fs.readFileSync(path.join(dir, 'light', 'catalog.FAILED.png'), 'utf8')).toBe('png');
        expect(fs.readFileSync(path.join(dir, 'light', 'catalog.FAILED.html'), 'utf8')).toBe('<html>dom</html>');
    });

    test('a failing or hung snapshot neither throws nor blocks the DOM dump', async () => {
        const errorSpy = jest.spyOn(console, 'error').mockImplementation(() => {});
        const webContents = {
            capturePage:       () => new Promise(() => {}),
            executeJavaScript: async () => '<html>dom</html>',
        };
        await expect(saveFailureArtifacts(webContents, dir, 'p', 20)).resolves.toBeUndefined();

        expect(fs.existsSync(path.join(dir, 'p.FAILED.png'))).toBe(false);
        expect(fs.existsSync(path.join(dir, 'p.FAILED.html'))).toBe(true);
        errorSpy.mockRestore();
    });
});

test('LastPageTracker remembers the Electron pid', () => {
    const tracker = new LastPageTracker();
    tracker.push(`${PID_MARKER}4242\n`);
    expect(tracker.pid).toBe(4242);
});

describe('RunWatchdog', () => {
    let requestSnapshot;
    let killGroup;
    const make = (timeoutMs = 1000) => new RunWatchdog({ timeoutMs, requestSnapshot, killGroup });

    beforeEach(() => {
        jest.useFakeTimers();
        requestSnapshot = jest.fn();
        killGroup = jest.fn();
    });
    afterEach(() => { jest.useRealTimers(); });

    test('timeout: snapshot request, SIGKILL of the group after the grace period, result 1', () => {
        const watchdog = make();
        watchdog.start();
        jest.advanceTimersByTime(999);
        expect(requestSnapshot).not.toHaveBeenCalled();

        jest.advanceTimersByTime(1);
        expect(requestSnapshot).toHaveBeenCalledTimes(1);
        expect(killGroup).not.toHaveBeenCalled();

        jest.advanceTimersByTime(KILL_GRACE_MS);
        expect(killGroup).toHaveBeenCalledWith('SIGKILL');
        expect(watchdog.finish(null)).toBe(1);
    });

    test('a child that exits 0 after the timeout still fails the run and the group is killed', () => {
        const watchdog = make();
        watchdog.start();
        jest.advanceTimersByTime(1000);
        expect(watchdog.finish(0)).toBe(1);
        expect(killGroup).toHaveBeenCalledWith('SIGKILL');
        jest.advanceTimersByTime(KILL_GRACE_MS * 2);
        expect(killGroup).toHaveBeenCalledTimes(1);
    });

    test('normal exit before the timeout: no signals ever, code passed through', () => {
        const watchdog = make();
        watchdog.start();
        expect(watchdog.finish(3)).toBe(3);
        jest.advanceTimersByTime(1000 + KILL_GRACE_MS * 2);
        expect(requestSnapshot).not.toHaveBeenCalled();
        expect(killGroup).not.toHaveBeenCalled();
    });

    test('a signal-terminated child (null code) is a failure', () => {
        const watchdog = make();
        watchdog.start();
        expect(watchdog.finish(null)).toBe(1);
    });
});
