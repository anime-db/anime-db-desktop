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

const COMMAND = 'app:catalog:stage';

/**
 * Same order of magnitude as catalog-export/index.js's TIMEOUT_MS — a staged archive can carry as
 * many media files as a full export produced it.
 */
const TIMEOUT_MS = 30 * 60 * 1000;

/**
 * Starts `bin/console app:catalog:stage <archivePath>` (App\Command\CatalogStageCommand) via the
 * shared one-off-command launcher, the same route window.animeDb.pickFile() already gives the
 * renderer to the archive path itself (issue #670). Validation and unpacking all happen in that
 * PHP process; this handler only spawns it and reports how it exited — CatalogStageCommand's
 * EXIT_* constants let the page distinguish *why* it failed (missing manifest, unsupported
 * format version, unreadable/corrupt archive, missing database, unsafe entry path) without
 * parsing its translated console output.
 *
 * Progress reaches the page separately, over the existing /ws bus (WsPublisher's
 * `import.progress`, App\Service\Import\CatalogStageService) — the same precedent as
 * catalog-export/index.js's `export.progress`. Unlike export, staging never publishes a
 * `*.failed` event of its own, so this handler's return value is the only signal a failure
 * reached the page at all, not just a fallback for one that missed a WS event.
 *
 * A successful stage triggers the app's own restart (issue #671) rather than waiting for the
 * user to close the window: the window-close handler only hides to tray
 * (native/lifecycle/index.js), so without this the user could keep working against the old
 * catalog for however long the window stays open. `require('../lifecycle')` is deliberately
 * lazy — lifecycle/index.js requires this module before it assigns its own module.exports, so a
 * top-level require here would capture that early, still-empty export object.
 *
 * @param {import('electron').IpcMainInvokeEvent} _event
 * @param {string} archivePath
 * @returns {Promise<{ ok: boolean, code: number | null }>}
 */
async function startImport(_event, archivePath) {
    const context = supervisor.getWorkerContext();
    if (!context) {
        return { ok: false, code: null };
    }

    const { code } = await phpCommand.run(COMMAND, [archivePath], context, TIMEOUT_MS, { rejectOnNonZero: false });

    if (code === 0) {
        require('../lifecycle').relaunch();
    }

    return { ok: code === 0, code };
}

ipcMain.handle('catalog:import-start', startImport);

module.exports = { startImport };
