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

jest.mock('net');

const net = require('net');
const { findFreePort } = require('../../native/supervisor/port');

describe('findFreePort', () => {
    test('resolves with startPort when it is available', async () => {
        const server = {
            listen: jest.fn((port, host, cb) => cb()),
            close:  jest.fn((cb) => cb()),
            on:     jest.fn().mockReturnThis(),
        };
        net.createServer.mockReturnValue(server);

        const result = await findFreePort(8000);
        expect(result).toBe(8000);
        expect(server.listen).toHaveBeenCalledWith(8000, '127.0.0.1', expect.any(Function));
    });

    test('skips an occupied port and returns the next free one', async () => {
        let errorCb;
        const server1 = {
            listen: jest.fn(),
            close:  jest.fn(),
            on:     jest.fn((event, cb) => { errorCb = cb; }),
        };
        const server2 = {
            listen: jest.fn((port, host, cb) => cb()),
            close:  jest.fn((cb) => cb()),
            on:     jest.fn().mockReturnThis(),
        };
        net.createServer
            .mockReturnValueOnce(server1)
            .mockReturnValueOnce(server2);

        const p = findFreePort(8000);
        errorCb();
        const result = await p;
        expect(result).toBe(8001);
    });

    test('rejects when the entire 100-port range is occupied', async () => {
        let errorCb;
        net.createServer.mockImplementation(() => ({
            listen: jest.fn(),
            close:  jest.fn(),
            on:     jest.fn((event, cb) => { errorCb = cb; }),
        }));

        const p = findFreePort(9000);
        for (let i = 0; i <= 100; i++) {
            errorCb();
        }
        await expect(p).rejects.toThrow('Нет свободного порта');
    });
});
