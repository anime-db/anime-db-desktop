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

const { ipcMain } = require('electron');
const phpCommand = require('../supervisor/php-command');
const supervisor  = require('../supervisor');

const COMMAND = 'app:catalog:import-v1';

/** Upper bound for a v1 import (issue #953): the catalog is one transaction plus a cover per entry. */
const TIMEOUT_MS = 30 * 60 * 1000;

/**
 * Starts `bin/console app:catalog:import-v1 <installationDir>` (App\Command\ImportV1Command) via
 * the shared one-off-command launcher; the directory is the v1 installation root chosen with the
 * existing window.animeDb.pickFolder(). All validation and mapping happens in that PHP process.
 * Progress and the outcome reach the page over the /ws bus (`import_v1.progress` / `.done` /
 * `.failed`, App\Service\WsPublisher), so this handler's own return value is only a fallback for
 * outcomes that never reach the bus: the process failing to spawn, being killed or timing out.
 *
 * @param {import('electron').IpcMainInvokeEvent} _event
 * @param {string} installationDir
 * @returns {Promise<{ ok: boolean, code: number | null }>}
 */
async function startImport(_event, installationDir) {
    const context = supervisor.getWorkerContext();
    if (!context) {
        return { ok: false, code: null };
    }

    const { code } = await phpCommand.run(COMMAND, [installationDir], context, TIMEOUT_MS, { rejectOnNonZero: false });

    return { ok: code === 0, code };
}

/**
 * Cancels the import in flight, if any, by killing the process php-command.js's pid-tracker
 * recorded for this command name. The import runs in a single transaction and removes the covers
 * it already stored when it fails, so a killed run leaves the catalog empty for another attempt.
 *
 * @returns {Promise<void>}
 */
function cancelImport() {
    return phpCommand.killOrphan(COMMAND);
}

ipcMain.handle('import-v1:start', startImport);
ipcMain.handle('import-v1:cancel', cancelImport);

module.exports = { startImport, cancelImport };
