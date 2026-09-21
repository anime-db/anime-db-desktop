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
const fs = require('fs');
const path = require('path');
const paths = require('../paths');
const supervisor = require('../supervisor');
const { restoreBackup } = require('../supervisor/migrations');

/**
 * Restores data.db from a snapshot picked on /settings/backup (issue #681), reusing
 * migrations.js's own restoreBackup() — the same file swap it runs after a failed migration
 * attempt, including clearing the `-journal`/`-wal`/`-shm` sidecars ahead of the copy.
 *
 * The swap only happens once supervisor.stop() has torn down FrankenPHP and messenger-consumer:
 * both hold an open Doctrine connection to data.db, so restoring while either is still running
 * would race that connection instead of replacing a closed file, the same constraint the
 * migrations.js retry path satisfies by construction (it only restores between two console
 * invocations, never while the web worker is up). The app is then relaunched — same trigger
 * native/catalog-import/index.js uses after staging an import — so the whole startup sequence,
 * including doctrine:migrations:up-to-date, runs fresh against the restored file rather than
 * resuming in place.
 *
 * @param {import('electron').IpcMainInvokeEvent} _event
 * @param {string} name  backup file name only, as returned by the /settings/backup snapshot list
 *   (see native/supervisor/migrations.js) — never a full path. Resolving it against
 *   getBackupsDir() here, via path.basename(), keeps a compromised or buggy renderer from
 *   pointing this at an arbitrary file elsewhere on disk.
 * @returns {Promise<{ ok: boolean }>}
 */
async function startRestore(_event, name) {
    const backupPath = path.join(paths.getBackupsDir(), path.basename(name));
    if (!fs.existsSync(backupPath)) {
        return { ok: false };
    }

    await supervisor.stop();
    restoreBackup(backupPath);

    // Lazy require: lifecycle/index.js requires this module before assigning its own
    // module.exports, so a top-level require here would capture that early, still-empty object
    // (same reasoning as native/catalog-import/index.js's own lazy require of '../lifecycle').
    require('../lifecycle').relaunch();

    return { ok: true };
}

ipcMain.handle('backup:restore-start', startRestore);

module.exports = { startRestore };
