/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

'use strict';

jest.mock('http');

const http = require('http');
const { waitForHealth } = require('../../native/supervisor/healthcheck');

describe('waitForHealth', () => {
    test('resolves with the port when /health returns 200', async () => {
        http.get.mockImplementation((url, cb) => {
            cb({ statusCode: 200 });
            return { on: jest.fn().mockReturnThis() };
        });
        await expect(
            waitForHealth(4000, { intervalMs: 10, timeoutMs: 1000 })
        ).resolves.toBe(4000);
    });

    test('rejects when /health keeps returning non-200 until timeout', async () => {
        http.get.mockImplementation((url, cb) => {
            cb({ statusCode: 503 });
            return { on: jest.fn().mockReturnThis() };
        });
        await expect(
            waitForHealth(4001, { intervalMs: 1, timeoutMs: 10 })
        ).rejects.toThrow('/health не ответил');
    });

    test('rejects when connection is refused until timeout', async () => {
        http.get.mockImplementation(() => {
            const req = {
                on: jest.fn((event, handler) => {
                    if (event === 'error') handler(new Error('ECONNREFUSED'));
                    return req;
                }),
            };
            return req;
        });
        await expect(
            waitForHealth(4002, { intervalMs: 1, timeoutMs: 10 })
        ).rejects.toThrow('/health не ответил');
    });
});
