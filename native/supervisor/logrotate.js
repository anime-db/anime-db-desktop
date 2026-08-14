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

/**
 * Returns today's date as YYYY-MM-DD in local time.
 *
 * @returns {string}
 */
function todayStr() {
    const d = new Date();
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

/**
 * Deletes the oldest log files so that at most maxFiles remain.
 * Only considers files matching `prefix-YYYY-MM-DD.log`.
 * Called at startup — not in real-time.
 *
 * @param {string} logDir
 * @param {string} prefix
 * @param {number} maxFiles
 */
function pruneOldLogs(logDir, prefix, maxFiles) {
    if (!fs.existsSync(logDir)) return;

    const pattern = new RegExp(`^${prefix}-\\d{4}-\\d{2}-\\d{2}\\.log$`);
    const files = fs.readdirSync(logDir)
        .filter(f => pattern.test(f))
        .sort(); // ISO date names sort lexicographically = chronologically

    const excess = files.length - maxFiles;
    if (excess <= 0) return;

    for (let i = 0; i < excess; i++) {
        fs.rmSync(path.join(logDir, files[i]), { force: true });
    }
}

/**
 * Creates logDir if needed and opens an append write-stream to
 * `logDir/prefix-YYYY-MM-DD.log`.
 *
 * @param {string} logDir
 * @param {string} prefix
 * @returns {fs.WriteStream}
 */
function openLogStream(logDir, prefix) {
    fs.mkdirSync(logDir, { recursive: true });
    const filename = path.join(logDir, `${prefix}-${todayStr()}.log`);
    return fs.createWriteStream(filename, { flags: 'a' });
}

module.exports = { pruneOldLogs, openLogStream, todayStr };
