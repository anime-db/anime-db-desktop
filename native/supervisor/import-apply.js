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

const fs   = require('fs');
const path = require('path');
const paths = require('../paths');
const phpCommand = require('./php-command');
const migrations = require('./migrations');
const messengerConsumer = require('./messenger-consumer');
const searchReindex = require('./search-reindex');

/** Same order of magnitude as staged-import.js's STATUS_TIMEOUT_MS — a `DELETE FROM` on a table
 *  the app itself keeps small has no reason to ever take long. */
const PURGE_TIMEOUT_MS = 60 * 1000;

/**
 * Marker filename {@see \App\Service\Import\StagedImportService::MARKER_FILENAME} writes and
 * reads — mirrored here as a literal the same way staged-import.js's own RejectReason strings
 * mirror {@see \App\Service\Import\StagedImportService}'s KNOWN_REJECTION_REASONS, since this
 * module (unlike staged-import.js) is the one that actually has to remove the file.
 */
const MARKER_FILENAME = 'import.json';

/**
 * Step 2 of apply(): removes the marker first, then swaps `data.db` and `media/` for the staged
 * versions. Never called except from apply(), after createPreImportBackup() has already snapshot
 * the catalog being replaced.
 *
 * The marker is removed before anything else so that a crash partway through the remaining steps
 * (3-5, still inside apply()'s own try/catch) leaves `import-staging/` in a state the next start's
 * staged-import.js#decide() can only reject, never re-apply: `app:import:staged-status` treats a
 * missing marker as invalid, and a REJECT verdict removes `import-staging/` entirely.
 *
 * Reuses migrations.js#restoreBackup() for the `data.db` swap rather than reimplementing it: it
 * already clears the `-journal`/`-wal`/`-shm` sidecars ahead of the copy — a hot journal left by
 * the file being replaced would otherwise be replayed against the file that took its place, the
 * same known lesson restoreBackup()'s own doc comment explains — and copies via a temporary file
 * plus rename, the same atomicity a staged `data.db` swap needs.
 *
 * `media/` is replaced wholesale, never merged: a merge would leave the previous catalog's own
 * media files orphaned under ids the imported catalog reuses. An archive staged without its own
 * `media/` (`app:catalog:stage` allows this) still clears the previous `media/` rather than
 * keeping it — the records it belonged to are gone, and app/public/js/inline-handlers.js already
 * degrades a missing cover file to a neutral tile.
 *
 * @param {string} stagingDir
 */
function swapCatalog(stagingDir) {
    fs.rmSync(path.join(stagingDir, MARKER_FILENAME), { force: true });

    migrations.restoreBackup(path.join(stagingDir, 'data.db'));

    const mediaDir = paths.getMediaDir();
    fs.rmSync(mediaDir, { recursive: true, force: true });

    const stagedMediaDir = path.join(stagingDir, 'media');
    if (fs.existsSync(stagedMediaDir)) {
        fs.renameSync(stagedMediaDir, mediaDir);
    } else {
        fs.mkdirSync(mediaDir, { recursive: true });
    }
}

/**
 * Applies a staged catalog import that staged-import.js#decide() has already returned
 * `Verdict.APPLY` for — never call this for a SKIP or REJECT verdict. Runs entirely before
 * frankenphp.start() (see index.js), the only point in the startup sequence where swapping
 * `data.db` out from under the app is safe: FrankenPHP's worker holds an open Doctrine connection
 * to it for its whole process lifetime.
 *
 * Order is load-bearing (issue #707):
 *   1. snapshot the current catalog (createPreImportBackup()) — its own failure
 *      (MigrationBootstrapError('backup-failed')) is deliberately not caught here: nothing has
 *      touched disk yet, so the same dialog lifecycle/index.js already shows for the ordinary
 *      upgrade path's backup failure applies unchanged, and a retry on the next start is safe;
 *   2. swap `data.db` and `media/` (see swapCatalog());
 *   3. bring the swapped-in database up to this build's schema — the same bootstrap the working
 *      database already went through earlier in the same start() call, reused here because a
 *      staged dump can be from an older schema version;
 *   4. purge the queue, strictly after `messenger:setup-transports` has created its table and
 *      before the long-lived consumer (started later, once FrankenPHP is up) can pick anything up
 *      — a queued message like PushSyncMessage addresses its target by a bare numeric id that, once
 *      the catalog underneath it changed, may now belong to an unrelated record;
 *   5. rebuild the search index, unconditionally — never gated behind the working database's own
 *      wiped/migrationsApplied signals the way the ordinary upgrade path's reindex is, since the
 *      whole catalog just changed regardless of whether step 3's migrate() had anything to do;
 *   6. remove `import-staging/` — only reached once every earlier step has succeeded.
 *
 * Any failure across steps 3-5 rolls the entire import back rather than leaving it half-applied:
 * restoreBackup() to the step-1 snapshot, followed by an unconditional reindex of the now-restored
 * catalog — by the time a failure here is caught, the index has already been cleared or partially
 * rebuilt against the catalog that is being discarded, so it must be rebuilt again for the one the
 * app is actually about to start against. That second reindex call is deliberately not wrapped in
 * its own try/catch: unlike the ordinary upgrade path's reindex (best-effort, logged and ignored —
 * see index.js), silently losing this one would start the app on a restored catalog paired with a
 * search index that still describes the catalog that was just discarded, which is worse than
 * failing the startup outright.
 *
 * @param {import('./env').PhpContext} context
 * @returns {Promise<{ applied: boolean, error: Error | null }>}
 */
async function apply(context) {
    const stagingDir = paths.getImportStagingDir();

    const preImportBackupPath = await migrations.createPreImportBackup(context);

    swapCatalog(stagingDir);

    try {
        await migrations.run(context);
        await messengerConsumer.runSetupTransports(context);
        await phpCommand.run('app:queue:purge', [], context, PURGE_TIMEOUT_MS);
        await searchReindex.run(context);
    } catch (err) {
        migrations.restoreBackup(preImportBackupPath);
        await searchReindex.run(context);
        return { applied: false, error: err };
    }

    fs.rmSync(stagingDir, { recursive: true, force: true });

    return { applied: true, error: null };
}

module.exports = { apply };
