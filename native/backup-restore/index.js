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
const searchReindex = require('../supervisor/search-reindex');

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
 * Unlike migrations.js's own restoreBackup() call (a rollback to the pre-migration state the
 * Meilisearch index already matched), this restores an arbitrary, potentially different snapshot
 * — so a successful restore also marks a forced reindex (searchReindex.markRequired(), issue #681
 * review) for the next start to pick up, since a same-schema restore would otherwise leave both of
 * index.js#start()'s own reindex triggers (wiped, migrationsApplied) false and the search index
 * stale against the restored catalog. Also removes `userData/import-applied.json` (issue #726)
 * on the success path — the plugin list App\Service\Import\ImportedPluginsService would otherwise
 * keep showing on /settings/backup describes the catalog this restore just replaced, not the one
 * that is about to start.
 *
 * restoreBackup() can throw (out of disk space, sidecar/target files locked, ...) — supervisor.
 * stop() has already torn FrankenPHP/messenger-consumer down by that point, and neither restarts
 * on its own (their own auto-restart is suppressed while stopping, see supervisor/frankenphp.js
 * and supervisor/messenger-consumer.js), so relaunch() must run on the failure path too. Otherwise
 * the app would sit with an open window and a dead backend until the user restarts it by hand.
 * restoreBackup() itself never leaves dbPath partially written on failure (it swaps the restored
 * file into place via a rename, not an in-place copy — see its own doc comment), so relaunching
 * after a failed restore brings the app back up against the data.db that was already there.
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

    let ok = true;
    try {
        restoreBackup(backupPath);
        searchReindex.markRequired();
    } catch {
        ok = false;
    } finally {
        if (ok) {
            // The restored snapshot is a different catalog than whatever import-applied.json
            // (issue #726) still describes — remove it rather than leave a stale plugin list
            // pointing at a catalog this restore just replaced. Only on the success path:
            // restoreBackup() throwing means data.db was never actually swapped (see its own doc
            // comment), so the file still describes the catalog that is still there. Its own
            // try/catch, outside the block that computes `ok`: a failure to remove this
            // informational file must not turn a successful restore into a reported failure.
            try {
                fs.rmSync(paths.getImportAppliedPath(), { force: true });
            } catch (cleanupErr) {
                console.error('[backup-restore] не удалось удалить import-applied.json:', cleanupErr.message);
            }
            // Same reasoning for the v1 import report (issue #954): it describes the catalog this
            // restore just replaced. Separate try/catch so one failed removal doesn't skip the other.
            try {
                fs.rmSync(paths.getImportV1ReportPath(), { force: true });
            } catch (cleanupErr) {
                console.error('[backup-restore] не удалось удалить import-v1-report.json:', cleanupErr.message);
            }
        }

        // Lazy require: lifecycle/index.js requires this module before assigning its own
        // module.exports, so a top-level require here would capture that early, still-empty
        // object (same reasoning as native/catalog-import/index.js's own lazy require of
        // '../lifecycle'). Runs on both the success and failure path — see the doc comment above.
        require('../lifecycle').relaunch();
    }

    return { ok };
}

ipcMain.handle('backup:restore-start', startRestore);

module.exports = { startRestore };
