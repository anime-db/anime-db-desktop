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

const fs         = require('fs');
const paths      = require('../paths');
const phpCommand = require('./php-command');

// Must match the name in PluginsInstallSelectedCommandRegistrationTest (app/tests/Unit/Command/),
// which fails when the command is renamed or unregistered in the application.
const COMMAND = 'app:plugins:install-selected';

/**
 * Upper bound for one run: it downloads and installs every plugin left in the selection file, each
 * with its own cache warm-up, so it is far longer than the other one-off calls in this directory.
 */
const TIMEOUT_MS = 15 * 60 * 1000;

/**
 * Whether the installer's plugin selection file (see paths.getInstallerPluginsPath()) exists, i.e.
 * some selected plugins are still not installed.
 *
 * @returns {boolean}
 */
function hasSelection() {
    return fs.existsSync(paths.getInstallerPluginsPath());
}

/**
 * Runs `bin/console app:plugins:install-selected` once. The command installs the selected plugins
 * without publishing the live-reload event and prints `{"installed": [ids]}`; activating them
 * (one restart for the whole batch) is up to the caller.
 *
 * @param {import('./env').PhpContext} context
 * @returns {Promise<string[]>} ids of the plugins that were actually installed
 */
async function run(context) {
    const result = await phpCommand.run(COMMAND, [], context, TIMEOUT_MS, { rejectOnNonZero: false });

    if (result.code !== 0) {
        throw new Error(`${COMMAND} exited with ${result.code === null ? 'a timeout' : `code ${result.code}`}`);
    }

    const jsonLine = result.stdout.split('\n').map((line) => line.trim()).filter((line) => line.startsWith('{')).pop();
    const parsed = JSON.parse(jsonLine === undefined ? '' : jsonLine);
    if (!parsed || !Array.isArray(parsed.installed)) {
        throw new Error(`${COMMAND} printed no "installed" list`);
    }

    return parsed.installed.filter((id) => typeof id === 'string');
}

module.exports = { COMMAND, hasSelection, run };
