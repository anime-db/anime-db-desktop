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

/**
 * Number of consecutive unclosed start markers (see beginStartAttempt()) that trigger the safe
 * mode prompt on the next launch — one crash could be a fluke (killed from Task Manager, a
 * transient disk error); two in a row is treated as a real, recurring startup failure.
 */
const UNCLOSED_START_THRESHOLD = 2;

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
 * Must be called once, before the kernel starts, ahead of any prompt to the user — see
 * commitStartSuccess() for the counterpart that clears what this writes. Shares state.json
 * with cache-invalidation.js's buildFingerprint (issue #403).
 *
 * If the previous launch's marker (`startPending`) is still set, it never reached
 * commitStartSuccess() — a crash, a hang killed from Task Manager, or a plugin that took the
 * kernel bootstrap down with it. That counts as one more unclosed start in a row; anything else
 * (first run, or a previous launch that completed normally) resets the streak. The marker for
 * *this* launch is written immediately, synchronously with the streak update — if this very
 * launch also fails before commitStartSuccess() runs, the next one must still see it as unclosed.
 *
 * @returns {boolean} true once the streak has reached UNCLOSED_START_THRESHOLD — the caller
 *                     should offer the user a safe-mode restart before starting the kernel
 */
function beginStartAttempt() {
    const state = readState();
    state.unclosedStartStreak = state.startPending === true ? (state.unclosedStartStreak || 0) + 1 : 0;
    state.startPending = true;
    writeState(state);
    return state.unclosedStartStreak >= UNCLOSED_START_THRESHOLD;
}

/**
 * The compiled Symfony container cache bakes in the set of plugin bundles active at the time it
 * was built (see Kernel::initializeBundles()'s docblock) — switching SAFE_MODE on or off without
 * wiping APP_RUNTIME_DIR/cache would leave that stale list in place, so the mode change would
 * either have no effect or "stick" after leaving safe mode.
 *
 * @param {boolean} safeMode  mode the kernel is about to start in
 * @returns {boolean} true if this differs from the mode of the last successfully completed
 *                     start — the caller must invalidate the cache before starting the kernel
 */
function hasModeChanged(safeMode) {
    return Boolean(readState().safeMode) !== safeMode;
}

/**
 * Must be called only after every process has started successfully — same rule as
 * cache-invalidation.js's commitFingerprint(), and for the same reason: committing early would
 * make a start that fails partway through look closed to the next launch.
 *
 * @param {boolean} safeMode  mode the kernel just finished starting in
 */
function commitStartSuccess(safeMode) {
    const state = readState();
    state.startPending = false;
    state.unclosedStartStreak = 0;
    state.safeMode = safeMode;
    writeState(state);
}

module.exports = { beginStartAttempt, hasModeChanged, commitStartSuccess };
