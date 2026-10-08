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

'use strict';

/*
 * Detects a run that leaked out of its isolated environment: snapshots the mtime of developer
 * paths before the run and reports those that appeared or changed after it.
 */

const fs   = require('fs');
const path = require('path');

const rootDir = path.resolve(__dirname, '..');
const appDir  = path.join(rootDir, 'app');

/**
 * Developer paths a run must never create or touch: they exist only if the isolation leaked.
 */
const LEAK_GUARDED_PATHS = [
    path.join(rootDir, 'data'),
    path.join(appDir, 'var', 'config.json'),
    path.join(appDir, 'var', 'share'),
];

/**
 * @param {string[]} [guarded]
 * @returns {Map<string, number|null>} mtime of every guarded path, null when it does not exist
 */
function snapshotGuardedPaths(guarded = LEAK_GUARDED_PATHS) {
    return new Map(guarded.map((p) => [p, fs.existsSync(p) ? fs.statSync(p).mtimeMs : null]));
}

/**
 * @param {Map<string, number|null>} before
 * @returns {string[]} guarded paths that appeared (or changed) since the snapshot
 */
function findLeakedPaths(before) {
    return [...before.keys()].filter((p) => {
        const now = fs.existsSync(p) ? fs.statSync(p).mtimeMs : null;
        return now !== before.get(p);
    });
}

module.exports = { LEAK_GUARDED_PATHS, snapshotGuardedPaths, findLeakedPaths };
