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

const path = require('path');
const { spawnSync } = require('child_process');

const mockRun = jest.fn();
jest.mock('../../native/supervisor/php-command', () => ({
    run: (...args) => mockRun(...args),
}));

const { COMMAND, run } = require('../../native/supervisor/source-urls-check');

const CONTEXT = { appPort: 8000, qbittorrentPort: 9999, meiliPort: 7700, meiliKey: 'test-key' };
const APP_DIR = path.join(__dirname, '..', '..', 'app');

afterEach(() => {
    jest.clearAllMocks();
});

describe('run', () => {
    test('delegates to php-command.js with the exact command name and a bounded timeout', () => {
        run(CONTEXT);

        expect(mockRun).toHaveBeenCalledWith('app:source-urls:check', [], CONTEXT, expect.any(Number));
        expect(mockRun.mock.calls[0][3]).toBeGreaterThan(0);
    });
});

// Мок php-command не отличает «команда есть» от «команды нет», поэтому имя из модуля сверяется
// с реальным приложением. Нужны `php` и `app/vendor` (composer install); без них тест падает,
// а не пропускается.
describe('registration in the application', () => {
    test('the command is registered under the name the supervisor calls', () => {
        const result = spawnSync('php', ['bin/console', COMMAND, '--help'], {
            cwd: APP_DIR,
            encoding: 'utf8',
            env: { ...process.env, APP_ENV: 'test' },
        });

        expect(result.error).toBeUndefined();
        expect(result.stderr).not.toMatch(/not defined|There are no commands defined/);
        expect(result.status).toBe(0);
        expect(result.stdout).toContain(COMMAND);
    });
});
