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

// Loads the real scan.js with the global WebSocket replaced by a spy class, the same pattern
// backup.test.js uses for its own shared-socket module. scan.js has no exports (window.ScanWatcher
// is its only surface), so the connection and dispatch behaviour is driven directly through the
// captured MockWebSocket instance and window.ScanWatcher.watch()/unwatch().

class MockWebSocket {
    constructor(url) {
        this.url = url;
        MockWebSocket.instances.push(this);
    }

    close() {}
}
MockWebSocket.instances = [];

function loadScanModule() {
    jest.isolateModules(() => {
        require('../../app/assets/js/scan.js');
    });
}

function currentSocket() {
    return MockWebSocket.instances[MockWebSocket.instances.length - 1];
}

beforeEach(() => {
    jest.resetModules();
    jest.useFakeTimers();
    MockWebSocket.instances = [];
    global.WebSocket = MockWebSocket;
});

afterEach(() => {
    jest.useRealTimers();
    delete global.WebSocket;
    delete window.ScanWatcher;
});

test('several watch() calls share a single WebSocket connection', () => {
    loadScanModule();

    window.ScanWatcher.watch('1', {});
    window.ScanWatcher.watch('2', {});
    window.ScanWatcher.watch('3', {});

    expect(MockWebSocket.instances).toHaveLength(1);
});

test('an incoming scan.progress event is dispatched only to the subscriber for that storage', () => {
    loadScanModule();

    const onProgress1 = jest.fn();
    const onProgress2 = jest.fn();
    window.ScanWatcher.watch('1', { onProgress: onProgress1 });
    window.ScanWatcher.watch('2', { onProgress: onProgress2 });

    currentSocket().onmessage({
        data: JSON.stringify({ event: 'scan.progress', data: { storage_id: '2', percent: 50 } }),
    });

    expect(onProgress1).not.toHaveBeenCalled();
    expect(onProgress2).toHaveBeenCalledWith({ storage_id: '2', percent: 50 });
});

test('scan.done and scan.failed are routed to their own callbacks, not onProgress', () => {
    loadScanModule();

    const callbacks = { onProgress: jest.fn(), onDone: jest.fn(), onFailed: jest.fn() };
    window.ScanWatcher.watch('1', callbacks);

    currentSocket().onmessage({
        data: JSON.stringify({ event: 'scan.done', data: { storage_id: '1', items: [] } }),
    });
    currentSocket().onmessage({
        data: JSON.stringify({ event: 'scan.failed', data: { storage_id: '1', reason: 'boom' } }),
    });

    expect(callbacks.onProgress).not.toHaveBeenCalled();
    expect(callbacks.onDone).toHaveBeenCalledWith({ storage_id: '1', items: [] });
    expect(callbacks.onFailed).toHaveBeenCalledWith({ storage_id: '1', reason: 'boom' });
});

test('unwatch() drops the subscription so later events for that storage are ignored', () => {
    loadScanModule();

    const onProgress = jest.fn();
    window.ScanWatcher.watch('1', { onProgress });
    window.ScanWatcher.unwatch('1');

    currentSocket().onmessage({
        data: JSON.stringify({ event: 'scan.progress', data: { storage_id: '1', percent: 10 } }),
    });

    expect(onProgress).not.toHaveBeenCalled();
});

test('a malformed message is ignored instead of throwing', () => {
    loadScanModule();
    window.ScanWatcher.watch('1', { onProgress: jest.fn() });

    expect(() => currentSocket().onmessage({ data: 'not json' })).not.toThrow();
});

// The connection is one per page, not one per watch() call — a disconnect must reconnect it and
// keep serving every subscriber registered before the drop, not just whichever watch() happens to
// fire next.
test('losing the connection schedules a reconnect that opens a new socket and keeps existing subscriptions alive', () => {
    loadScanModule();

    const onProgress = jest.fn();
    window.ScanWatcher.watch('1', { onProgress });
    const firstSocket = currentSocket();

    firstSocket.onclose();
    expect(MockWebSocket.instances).toHaveLength(1);

    jest.advanceTimersByTime(1000);
    expect(MockWebSocket.instances).toHaveLength(2);

    currentSocket().onmessage({
        data: JSON.stringify({ event: 'scan.progress', data: { storage_id: '1', percent: 75 } }),
    });
    expect(onProgress).toHaveBeenCalledWith({ storage_id: '1', percent: 75 });
});

test('a fresh watch() call while disconnected does not open a second socket before the reconnect delay elapses', () => {
    loadScanModule();

    window.ScanWatcher.watch('1', {});
    currentSocket().onclose();
    expect(MockWebSocket.instances).toHaveLength(1);

    window.ScanWatcher.watch('2', {});
    expect(MockWebSocket.instances).toHaveLength(1);

    jest.advanceTimersByTime(1000);
    expect(MockWebSocket.instances).toHaveLength(2);
});
