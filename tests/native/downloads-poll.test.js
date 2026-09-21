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

const mockRun = jest.fn();
jest.mock('../../native/supervisor/php-command', () => ({
    run: (...args) => mockRun(...args),
}));

const { run } = require('../../native/supervisor/downloads-poll');

const CONTEXT = { appPort: 8000, qbittorrentPort: 9999, meiliPort: 7700, meiliKey: 'test-key' };

afterEach(() => {
    jest.clearAllMocks();
});

describe('run', () => {
    // Разовая команда app:downloads:poll обязана идти через общую обёртку php-command.js
    // (issue #400), как и app:market:refresh — downloads-poll.js своего спавна не заводит.
    test('delegates to php-command.js with the downloads poll command and a bounded timeout', () => {
        run(CONTEXT);

        expect(mockRun).toHaveBeenCalledWith('app:downloads:poll', [], CONTEXT, expect.any(Number));
        const timeoutMs = mockRun.mock.calls[0][3];
        expect(timeoutMs).toBeGreaterThan(0);
    });

    test('resolves and rejects exactly as php-command.js does', async () => {
        mockRun.mockResolvedValueOnce(undefined);
        await expect(run(CONTEXT)).resolves.toBeUndefined();

        mockRun.mockRejectedValueOnce(new Error('app:downloads:poll завершился с кодом 1'));
        await expect(run(CONTEXT)).rejects.toThrow('app:downloads:poll завершился с кодом 1');
    });
});
