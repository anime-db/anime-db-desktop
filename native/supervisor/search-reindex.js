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
const path       = require('path');
const paths      = require('../paths');
const phpCommand = require('./php-command');

const COMMAND = 'app:search:reindex';

/**
 * Upper bound for a full catalog reindex (see AnimeReindexService). Rebuilding the Meilisearch
 * index from the SQLite catalog can take much longer than the other one-off console calls in
 * native/supervisor/, so it gets its own, more generous timeout rather than sharing one with
 * them.
 */
const TIMEOUT_MS = 30 * 60 * 1000;

/**
 * Runs `bin/console app:search:reindex` once and resolves when it exits successfully. Used to
 * rebuild the Meilisearch index after it was wiped by a version change (see meilisearch.js
 * checkVersionAndWipe / issue #389).
 *
 * @param {import('./env').PhpContext} context
 * @returns {Promise<void>}
 */
function run(context) {
    return phpCommand.run(COMMAND, [], context, TIMEOUT_MS);
}

/**
 * @returns {object}
 */
function readState() {
    try {
        const parsed = JSON.parse(fs.readFileSync(paths.getStatePath(), 'utf8'));
        return (parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed)) ? parsed : {};
    } catch {
        return {};
    }
}

/**
 * @param {object} state
 */
function writeState(state) {
    const statePath = paths.getStatePath();
    fs.mkdirSync(path.dirname(statePath), { recursive: true });
    fs.writeFileSync(statePath, JSON.stringify(state, null, 2));
}

/**
 * Marks that the next start() must run a full reindex regardless of the wiped/migrationsApplied
 * signals index.js#start() otherwise relies on. Restoring an arbitrary backup snapshot (see
 * native/backup-restore/index.js, issue #681) swaps in a catalog the current Meilisearch index
 * was never built against — including same-schema snapshots, where both those signals stay false
 * and index.js would otherwise skip reindexing entirely, leaving search stale (issue #681 review).
 * Shares state.json with cache-invalidation.js/safe-mode.js.
 */
function markRequired() {
    const state = readState();
    state.reindexRequired = true;
    writeState(state);
}

/**
 * Consumes the marker set by markRequired(), so a restore forces exactly one reindex rather than
 * one on every subsequent start. Must be called unconditionally (not short-circuited behind
 * wiped/migrationsApplied) so the marker is always cleared once a start reaches this check.
 *
 * @returns {boolean} true if a reindex was requested via markRequired()
 */
function consumeRequired() {
    const state = readState();
    const required = state.reindexRequired === true;
    if (required) {
        state.reindexRequired = false;
        writeState(state);
    }
    return required;
}

module.exports = { run, markRequired, consumeRequired };
