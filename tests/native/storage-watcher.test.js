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

const { createStorageWatcher } = require('../../native/storage-watcher');

const flush = async () => { for (let i = 0; i < 20; i++) await Promise.resolve(); };

function setup({ platform = 'linux', list = [{ id: 1, path: '/mnt/a' }], availability = {} } = {}) {
    const state = { available: { ...availability }, hang: new Set() };
    const access = jest.fn((p) => {
        if (state.hang.has(p)) return new Promise(() => {});
        return state.available[p] === false ? Promise.reject(new Error('ENOENT')) : Promise.resolve();
    });
    const listStorages = jest.fn(() => Promise.resolve(list));
    const connected = jest.fn(() => Promise.resolve());
    const watcher = createStorageWatcher({ listStorages, connected, access, platform, timeoutMs: 1000, log: jest.fn() });
    const tick = async (ms = 15000) => { jest.advanceTimersByTime(ms); await flush(); await watcher.idle(); await flush(); };
    return { state, access, listStorages, connected, watcher, tick };
}

describe('storage watcher', () => {
    beforeEach(() => jest.useFakeTimers());
    afterEach(() => jest.useRealTimers());

    test('first poll is a baseline and triggers nothing', async () => {
        const t = setup({ availability: { '/mnt/a': true } });
        await t.watcher.start();
        await flush();
        await flush();
        expect(t.connected).not.toHaveBeenCalled();
        t.watcher.stop();
    });

    test('unavailable -> available fires once after 2 identical polls', async () => {
        const t = setup({ availability: { '/mnt/a': false } });
        await t.watcher.start();
        await flush();
        t.state.available['/mnt/a'] = true;
        await t.tick();
        expect(t.connected).not.toHaveBeenCalled();
        await t.tick();
        expect(t.connected).toHaveBeenCalledTimes(1);
        expect(t.connected).toHaveBeenCalledWith('1');
        await t.tick();
        expect(t.connected).toHaveBeenCalledTimes(1);
        t.watcher.stop();
    });

    test('a single blip between two unavailable polls gives no signal', async () => {
        const t = setup({ availability: { '/mnt/a': false } });
        await t.watcher.start();
        await flush();
        t.state.available['/mnt/a'] = true;
        await t.tick();
        t.state.available['/mnt/a'] = false;
        await t.tick();
        await t.tick();
        expect(t.connected).not.toHaveBeenCalled();
        t.watcher.stop();
    });

    test('available -> unavailable gives no signal', async () => {
        const t = setup({ availability: { '/mnt/a': true } });
        await t.watcher.start();
        await flush();
        t.state.available['/mnt/a'] = false;
        await t.tick();
        await t.tick();
        expect(t.connected).not.toHaveBeenCalled();
        t.watcher.stop();
    });

    test('does not start a new check while the previous one of that root is pending', async () => {
        const t = setup();
        t.state.hang.add('/mnt/a');
        await t.watcher.start();
        await flush();
        await t.tick(1000);
        await t.tick();
        await t.tick();
        expect(t.access).toHaveBeenCalledTimes(1);
        t.watcher.stop();
    });

    test('a hanging root does not delay other roots', async () => {
        const t = setup({ list: [{ id: 1, path: '/hang' }, { id: 2, path: '/ok' }], availability: { '/ok': false } });
        t.state.hang.add('/hang');
        await t.watcher.start();
        await flush();
        t.state.available['/ok'] = true;
        await t.tick();
        await t.tick();
        expect(t.connected).toHaveBeenCalledWith('2');
        t.watcher.stop();
    });

    test('windows: new drive letter signals every unavailable storage, sequentially', async () => {
        const t = setup({
            platform: 'win32',
            list: [{ id: 1, path: 'E:\\s' }, { id: 2, path: 'F:\\s' }, { id: 3, path: 'C:\\s' }],
            availability: { 'E:\\s': false, 'F:\\s': false, 'E:\\': false },
        });
        let running = 0;
        let maxRunning = 0;
        t.connected.mockImplementation(async () => {
            running++; maxRunning = Math.max(maxRunning, running);
            await Promise.resolve();
            running--;
        });
        await t.watcher.start();
        await flush();
        t.state.available['E:\\'] = true;
        await t.tick();
        await t.tick();
        expect(t.connected.mock.calls.map((c) => c[0]).sort()).toEqual(['1', '2']);
        expect(maxRunning).toBe(1);
        t.watcher.stop();
    });

    test('windows: drive-letter checks do not overlap while pending', async () => {
        const t = setup({ platform: 'win32', list: [] });
        t.state.hang.add('Z:\\');
        await t.watcher.start();
        await flush();
        await t.tick();
        await t.tick();
        expect(t.access.mock.calls.filter((c) => c[0] === 'Z:\\')).toHaveLength(1);
        expect(t.access.mock.calls.filter((c) => c[0] === 'A:\\')).toHaveLength(3);
        t.watcher.stop();
    });

    test('non-windows: drive letters are not checked', async () => {
        const t = setup({ list: [] });
        await t.watcher.start();
        await flush();
        await t.tick();
        expect(t.access).not.toHaveBeenCalled();
        t.watcher.stop();
    });

    test('list is re-read at start, on refresh() and every 5 minutes', async () => {
        const t = setup();
        await t.watcher.start();
        await flush();
        expect(t.listStorages).toHaveBeenCalledTimes(1);
        await t.watcher.refresh();
        expect(t.listStorages).toHaveBeenCalledTimes(2);
        await t.tick(5 * 60 * 1000);
        expect(t.listStorages).toHaveBeenCalledTimes(3);
        t.watcher.stop();
    });

    test('a failing list keeps the previous one', async () => {
        const t = setup({ availability: { '/mnt/a': false } });
        await t.watcher.start();
        await flush();
        t.listStorages.mockRejectedValue(new Error('boom'));
        await t.watcher.refresh();
        t.state.available['/mnt/a'] = true;
        await t.tick();
        await t.tick();
        expect(t.connected).toHaveBeenCalledWith('1');
        t.watcher.stop();
    });

    test('pause stops polling and suppresses signals', async () => {
        const t = setup({ availability: { '/mnt/a': false } });
        await t.watcher.start();
        await flush();
        t.state.available['/mnt/a'] = true;
        await t.tick(); // first confirmation of the transition
        t.watcher.pause();
        const calls = t.access.mock.calls.length;
        await t.tick();
        await t.tick();
        await t.tick();
        expect(t.access).toHaveBeenCalledTimes(calls);
        expect(t.connected).not.toHaveBeenCalled();
        t.watcher.stop();
    });

    test('resume rebuilds the baseline and then signals a real transition once', async () => {
        const t = setup({ availability: { '/mnt/a': false } });
        await t.watcher.start();
        await flush();
        t.watcher.pause();
        await t.tick();
        t.watcher.resume();
        await t.tick(); // baseline (unavailable)
        t.state.available['/mnt/a'] = true;
        await t.tick();
        expect(t.connected).not.toHaveBeenCalled();
        await t.tick();
        expect(t.connected).toHaveBeenCalledTimes(1);
        expect(t.connected).toHaveBeenCalledWith('1');
        await t.tick();
        expect(t.connected).toHaveBeenCalledTimes(1);
        t.watcher.stop();
    });

    test('pause during a pending poll gives no signal when the check settles', async () => {
        const t = setup({ availability: { '/mnt/a': false } });
        await t.watcher.start();
        await flush();
        t.state.available['/mnt/a'] = true;
        await t.tick(); // first confirmation of the transition
        let settle;
        t.access.mockImplementationOnce(() => new Promise((resolve) => { settle = resolve; }));
        jest.advanceTimersByTime(15000); // poll started, access still pending
        await flush();
        t.watcher.pause();
        settle();
        await flush();
        await t.watcher.idle();
        await flush();
        expect(t.connected).not.toHaveBeenCalled();
        t.watcher.stop();
    });

    test('stop clears timers', async () => {
        const t = setup();
        await t.watcher.start();
        await flush();
        t.watcher.stop();
        expect(jest.getTimerCount()).toBe(0);
    });
});
