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

const { EventEmitter } = require('events');

let mockSocket;

function makeMockSocket() {
    return {
        onmessage: null,
        onclose:   null,
        onerror:   null,
        close:     jest.fn(),
    };
}

global.WebSocket = jest.fn(() => {
    mockSocket = makeMockSocket();
    return mockSocket;
});

// Re-require the module fresh each test via jest module isolation
beforeEach(() => {
    jest.resetModules();
    global.WebSocket.mockClear();
});

describe('WsClient', () => {
    test('exports an EventEmitter instance', () => {
        const client = require('../../native/ws-client');
        expect(client).toBeInstanceOf(EventEmitter);
    });

    test('connect() opens a WebSocket to the correct URL', () => {
        const client = require('../../native/ws-client');
        client.connect(9000);
        expect(global.WebSocket).toHaveBeenCalledWith('ws://127.0.0.1:9000/ws');
    });

    test('emits backend-event when a valid JSON message arrives', () => {
        const client = require('../../native/ws-client');
        client.connect(9000);

        const handler = jest.fn();
        client.on('backend-event', handler);

        const payload = { event: 'backend.status', data: { state: 'idle' } };
        mockSocket.onmessage({ data: JSON.stringify(payload) });

        expect(handler).toHaveBeenCalledWith(payload);
    });

    test('ignores malformed (non-JSON) messages', () => {
        const client = require('../../native/ws-client');
        client.connect(9000);

        const handler = jest.fn();
        client.on('backend-event', handler);

        expect(() => mockSocket.onmessage({ data: 'not json' })).not.toThrow();
        expect(handler).not.toHaveBeenCalled();
    });

    test('disconnect() closes the socket and stops reconnection', () => {
        jest.useFakeTimers();
        const client = require('../../native/ws-client');
        client.connect(9000);

        client.disconnect();
        expect(mockSocket.close).toHaveBeenCalled();

        // onclose should not trigger reconnect after disconnect
        const prevCallCount = global.WebSocket.mock.calls.length;
        mockSocket.onclose();
        jest.runAllTimers();
        expect(global.WebSocket.mock.calls.length).toBe(prevCallCount);

        jest.useRealTimers();
    });
});
