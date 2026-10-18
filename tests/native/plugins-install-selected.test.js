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

jest.mock('fs', () => ({ existsSync: jest.fn() }));
jest.mock('../../native/paths', () => ({
    getInstallerPluginsPath: jest.fn(() => '/fake/userData/installer-plugins.json'),
}));
jest.mock('../../native/supervisor/php-command', () => ({ run: jest.fn() }));

const fs         = require('fs');
const phpCommand = require('../../native/supervisor/php-command');
const installSelected = require('../../native/supervisor/plugins-install-selected');

const CONTEXT = { appPort: 8000 };

afterEach(() => jest.clearAllMocks());

test('hasSelection reflects whether the selection file exists', () => {
    fs.existsSync.mockReturnValue(true);
    expect(installSelected.hasSelection()).toBe(true);
    expect(fs.existsSync).toHaveBeenCalledWith('/fake/userData/installer-plugins.json');

    fs.existsSync.mockReturnValue(false);
    expect(installSelected.hasSelection()).toBe(false);
});

test('run launches app:plugins:install-selected and returns the installed ids from stdout', async () => {
    phpCommand.run.mockResolvedValue({ code: 0, stdout: 'noise\n{"installed":["animedb-a","animedb-b"]}', stderr: '' });

    await expect(installSelected.run(CONTEXT)).resolves.toEqual(['animedb-a', 'animedb-b']);
    expect(phpCommand.run).toHaveBeenCalledWith(
        'app:plugins:install-selected', [], CONTEXT, expect.any(Number), { rejectOnNonZero: false },
    );
});

test('run rejects on a non-zero exit code and on a timeout', async () => {
    phpCommand.run.mockResolvedValueOnce({ code: 1, stdout: '', stderr: 'boom' });
    await expect(installSelected.run(CONTEXT)).rejects.toThrow('code 1');

    phpCommand.run.mockResolvedValueOnce({ code: null, stdout: '', stderr: '' });
    await expect(installSelected.run(CONTEXT)).rejects.toThrow('timeout');
});

test('run rejects when stdout holds no installed list', async () => {
    phpCommand.run.mockResolvedValue({ code: 0, stdout: '', stderr: '' });
    await expect(installSelected.run(CONTEXT)).rejects.toThrow();

    phpCommand.run.mockResolvedValue({ code: 0, stdout: '{"other":[]}', stderr: '' });
    await expect(installSelected.run(CONTEXT)).rejects.toThrow('installed');
});
