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

const COMMAND = 'app:catalog:export';

/**
 * Upper bound for a full catalog export (issue #657) — a real catalog's covers/gallery can run
 * into the tens of thousands of files, same order of magnitude as search-reindex.js's TIMEOUT_MS.
 */
const TIMEOUT_MS = 30 * 60 * 1000;

/**
 * Starts `bin/console app:catalog:export <destinationDir>` (App\Command\CatalogExportCommand) via
 * the shared one-off-command launcher, the same route window.animeDb.pickFolder() already gives
 * the renderer to the destination directory itself. The heavy lifting — the VACUUM INTO snapshot,
 * zipping media/, the free-space check — all happens in that PHP process; this handler only
 * spawns it and reports how it exited. Progress and the final outcome reach the page separately,
 * over the existing /ws bus (WsPublisher's `export.progress`/`export.done`/`export.failed`,
 * App\Service\Export\CatalogExportService), the same precedent as `scan.progress` — this
 * handler's own return value is a fallback for a page that missed those events entirely (e.g. it
 * was not open yet when the process failed to even spawn), not the primary signal.
 *
 * @param {import('electron').IpcMainInvokeEvent} _event
 * @param {string} destinationDir
 * @returns {Promise<{ ok: boolean, code: number | null }>}
 */
async function startExport(_event, destinationDir) {
    const context = supervisor.getWorkerContext();
    if (!context) {
        return { ok: false, code: null };
    }

    const { code } = await phpCommand.run(COMMAND, [destinationDir], context, TIMEOUT_MS, { rejectOnNonZero: false });

    return { ok: code === 0, code };
}

/**
 * Cancels the export currently in flight, if any, by killing the process php-command.js's
 * pid-tracker recorded for it under this same command name — the exact mechanism killOrphan()
 * already uses for a crashed previous session's leftovers (issue #657 acceptance criterion 5: the
 * archive is only rename()d into place on success, so killing the process never leaves a
 * half-written file under its final name).
 *
 * @returns {Promise<void>}
 */
function cancelExport() {
    return phpCommand.killOrphan(COMMAND);
}

ipcMain.handle('catalog:export-start', startExport);
ipcMain.handle('catalog:export-cancel', cancelExport);

module.exports = { startExport, cancelExport };
