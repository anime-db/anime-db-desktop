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
const { findFreePort } = require('../native/supervisor/port');

test('findFreePort(8000) returns a number >= 8000', async () => {
    const port = await findFreePort(8000);
    expect(typeof port).toBe('number');
    expect(port).toBeGreaterThanOrEqual(8000);
});

test('findFreePort skips an occupied port and returns the next free one', async () => {
    // Занимаем порт 9100 вручную
    const blocker = net.createServer();
    await new Promise((resolve) => blocker.listen(9100, '127.0.0.1', resolve));

    try {
        const port = await findFreePort(9100);
        expect(port).toBeGreaterThan(9100);
        expect(port).toBeLessThanOrEqual(9200);
    } finally {
        await new Promise((resolve) => blocker.close(resolve));
    }
});
