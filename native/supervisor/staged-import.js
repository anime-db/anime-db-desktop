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

const COMMAND = 'app:import:staged-status';

/** Just reads and parses a small JSON file — no reason for this to ever take long. */
const STATUS_TIMEOUT_MS = 60 * 1000;

/** A staged import older than this is not applied without asking first (issue #706). */
const MAX_AGE_MS = 24 * 60 * 60 * 1000;

/**
 * The three possible outcomes of decide().
 *
 * @readonly
 * @enum {string}
 */
const Verdict = {
    /** Marker valid, schema compatible, and either fresh enough or user-confirmed. Applying it
     *  is a separate, not-yet-built step — this verdict alone changes nothing on disk. */
    APPLY:  'apply',
    /** No staging directory at all — a normal start, nothing to decide. */
    SKIP:   'skip',
    /** Marker invalid, schema incompatible, or the user declined a stale staging — see `reason`
     *  on the returned object. import-staging/ has already been removed by the time this
     *  resolves. */
    REJECT: 'reject',
};

/**
 * Written to paths.getImportRejectionPath() so App\Service\Import\StagedImportService
 * ::readRejectionReason() can surface it on /settings/backup after import-staging/ itself is gone
 * (issue #706 acceptance criteria 2, 8). The first three are one per way decide() itself can
 * reject a staged import; IMPORT_ROLLED_BACK is the exception — written directly by
 * import-apply.js#apply() (issue #710) when a staged import was applied but had to be rolled back,
 * a case decide() never sees as anything other than a marker-less import-staging/ left behind by
 * swapCatalog() having already removed the marker before the failure. Without this, decide() would
 * otherwise call reject(INVALID_MARKER) on the next start and overwrite the true reason.
 *
 * @readonly
 * @enum {string}
 */
const RejectReason = {
    INVALID_MARKER:       'invalid_marker',
    INCOMPATIBLE_SCHEMA:  'incompatible_schema',
    USER_DECLINED:        'user_declined',
    IMPORT_ROLLED_BACK:   'import_rolled_back',
};

/**
 * Kills a `app:import:staged-status` invocation orphaned by a previous session — same ordering
 * constraint as the other one-off console calls (see index.js).
 *
 * @returns {Promise<void>}
 */
function killOrphan() {
    return phpCommand.killOrphan(COMMAND);
}

/**
 * Exported for import-apply.js#apply() (issue #710), the one caller of this outside decide()
 * itself — see RejectReason.IMPORT_ROLLED_BACK.
 *
 * @param {string} reason  one of RejectReason
 */
function writeRejection(reason) {
    const rejectionPath = paths.getImportRejectionPath();
    fs.mkdirSync(path.dirname(rejectionPath), { recursive: true });
    fs.writeFileSync(rejectionPath, JSON.stringify({ reason }));
}

/**
 * @param {string} reason  one of RejectReason
 * @returns {{ verdict: string, reason: string }}
 */
function reject(reason) {
    writeRejection(reason);
    fs.rmSync(paths.getImportStagingDir(), { recursive: true, force: true });
    return { verdict: Verdict.REJECT, reason };
}

/**
 * Removes a rejection reason a previous decide() call wrote, once it stops being current —
 * before returning SKIP (nothing staged, so any past rejection no longer describes anything the
 * user still needs to act on) or APPLY (the staged import that was rejected before, if any, has
 * since been superseded by one that passed every check). Otherwise
 * StagedImportService::readRejectionReason() would keep surfacing a stale reason on
 * /settings/backup indefinitely, including after an unrelated, successful import.
 */
function clearRejection() {
    fs.rmSync(paths.getImportRejectionPath(), { force: true });
}

/**
 * Decides whether a staged catalog import (see App\Service\Import\CatalogStageService, issue
 * #669) may be applied — never touches `data.db`, `media/`, or anything else the eventual apply
 * step owns. Must run before frankenphp.start(), the same place migrations.run() and the schema
 * downgrade guard already run (see index.js) — a rejected staged database must never be swapped
 * in, and deciding that requires the staged file, not the working one, to be checked first.
 *
 * The three checks run in order, each one a precondition for the next:
 *   1. the marker (`import.json`) is valid — checked via `bin/console app:import:staged-status`
 *      ({@see \App\Command\ImportStagedStatusCommand}), which defers to
 *      App\Service\Import\StagedImportService::readMarker() so this step and the /settings/backup
 *      banner can never disagree about what counts as a valid marker;
 *   2. the staged database's schema is compatible with this build, via
 *      migrations.js#checkDumpSchema() against the staged file, not the working one;
 *   3. the staging is not older than MAX_AGE_MS — if it is, `confirmStaleImport` (when supplied)
 *      is asked before applying; declining, or omitting the callback entirely, rejects rather
 *      than applying silently.
 *
 * @param {import('./env').PhpContext} context
 * @param {(info: { stagedAt: string, sourceArchive: string }) => (Promise<boolean> | boolean)} [confirmStaleImport]
 *   asked only when the staging is older than MAX_AGE_MS and every earlier check passed; a
 *   missing callback is treated the same as a decline, never as a silent confirmation
 * @returns {Promise<{ verdict: string, reason?: string }>}
 */
async function decide(context, confirmStaleImport) {
    const stagingDir = paths.getImportStagingDir();
    if (!fs.existsSync(stagingDir)) {
        clearRejection();
        return { verdict: Verdict.SKIP };
    }

    const status = await phpCommand.run(COMMAND, [], context, STATUS_TIMEOUT_MS, { rejectOnNonZero: false });
    if (status.code !== 0) {
        return reject(RejectReason.INVALID_MARKER);
    }

    let marker;
    try {
        marker = JSON.parse(status.stdout);
    } catch {
        return reject(RejectReason.INVALID_MARKER);
    }

    const schemaVerdict = await migrations.checkDumpSchema(path.join(stagingDir, 'data.db'), context);
    if (schemaVerdict !== migrations.DumpSchemaVerdict.COMPATIBLE) {
        return reject(RejectReason.INCOMPATIBLE_SCHEMA);
    }

    const ageMs = Date.now() - new Date(marker.stagedAt).getTime();
    if (ageMs > MAX_AGE_MS) {
        const confirmed = confirmStaleImport
            ? await confirmStaleImport({ stagedAt: marker.stagedAt, sourceArchive: marker.sourceArchive })
            : false;
        if (!confirmed) {
            return reject(RejectReason.USER_DECLINED);
        }
    }

    clearRejection();
    return { verdict: Verdict.APPLY };
}

module.exports = { decide, killOrphan, writeRejection, Verdict, RejectReason };
