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

const net = require('net');

/**
 * Находит свободный TCP-порт начиная с startPort.
 * Пробует до 100 портов подряд; если все заняты — отклоняет промис с ошибкой.
 *
 * @param {number} startPort
 * @returns {Promise<number>}
 */
function findFreePort(startPort) {
    return new Promise((resolve, reject) => {
        const tryPort = (port) => {
            const server = net.createServer();
            server.listen(port, '127.0.0.1', () => {
                server.close(() => resolve(port));
            });
            server.on('error', () => {
                if (port < startPort + 100) {
                    tryPort(port + 1);
                } else {
                    reject(new Error(`Нет свободного порта в диапазоне ${startPort}–${startPort + 100}`));
                }
            });
        };
        tryPort(startPort);
    });
}

module.exports = { findFreePort };
