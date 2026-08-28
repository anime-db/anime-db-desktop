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

jest.mock('http');

const { EventEmitter } = require('events');
const http = require('http');
const { waitForHealth, waitForProcessAlive } = require('../../native/supervisor/healthcheck');

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

    /**
     * Сообщение обязано называть путь, который реально опрашивался: `waitForHealth` принимает
     * `path`, и его ждут три разных потребителя (FrankenPHP — `/health`, qbittorrent.js —
     * `/api/v2/app/version`, meilisearch.js — свой). До issue #552 текст был литералом `/health`
     * при любом пути, и отказ ожидания WebUI qBittorrent прочитался как обращение к
     * несуществующему `/health` на его порту — разбор ушёл не туда.
     */
    test('names the path that was actually polled, not a hardcoded /health', async () => {
        http.get.mockImplementation((url, cb) => {
            cb({ statusCode: 403 });
            return { on: jest.fn().mockReturnThis() };
        });
        await expect(
            waitForHealth(18080, { intervalMs: 1, timeoutMs: 10, path: '/api/v2/app/version' })
        ).rejects.toThrow('/api/v2/app/version не ответил за 10ms на порту 18080');
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

describe('waitForProcessAlive', () => {
    test('resolves when the process is still alive after the grace period', async () => {
        const child = new EventEmitter();
        await expect(waitForProcessAlive(child, 10)).resolves.toBeUndefined();
    });

    test('rejects when the process exits before the grace period elapses', async () => {
        const child = new EventEmitter();
        const promise = waitForProcessAlive(child, 1000);
        child.emit('exit', 1);
        await expect(promise).rejects.toThrow('процесс завершился до истечения проверки готовности');
    });
});
