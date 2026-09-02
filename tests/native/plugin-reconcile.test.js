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

const { run } = require('../../native/supervisor/plugin-reconcile');

const CONTEXT = { appPort: 8000, qbittorrentPort: 9999, meiliPort: 7700, meiliKey: 'test-key' };

afterEach(() => {
    jest.clearAllMocks();
});

describe('run', () => {
    // Same rationale as search-reindex.js/market-refresh.js (issue #400): the one-off console
    // call goes through the shared wrapper for env/log/timeout/PID-tracking rather than spawning
    // its own child process.
    test('delegates to php-command.js with the reconcile command and a timeout', () => {
        run(CONTEXT);

        expect(mockRun).toHaveBeenCalledWith('app:plugin:reconcile', [], CONTEXT, expect.any(Number));
    });

    test('resolves and rejects exactly as php-command.js does', async () => {
        mockRun.mockResolvedValueOnce(undefined);
        await expect(run(CONTEXT)).resolves.toBeUndefined();

        mockRun.mockRejectedValueOnce(new Error('app:plugin:reconcile завершился с кодом 1'));
        await expect(run(CONTEXT)).rejects.toThrow('app:plugin:reconcile завершился с кодом 1');
    });
});
