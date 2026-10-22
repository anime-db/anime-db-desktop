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

/**
 * Hand-off of a folder path from the Explorer context menu entry to the main window. The
 * installer registers `"<exe>" --explorer-open="%V"`; both the first and the second instance read
 * the path with the same parser and the window opens `/explorer/open?path=...` (issue #1122).
 */

const ARG_PREFIX = '--explorer-open=';

/** Guard header the backend's /explorer/open resolver requires (ExplorerOpenController::SHELL_HEADER). */
const SHELL_EXTRA_HEADERS = 'X-AnimeDB-Shell: 1\n';

/**
 * Extracts the path from the `--explorer-open=<path>` argument. For a drive root `"D:\"` the
 * command line parser (CommandLineToArgvW) treats `\"` as an escaped quote and yields `D:"`, so a
 * trailing quote is dropped and a bare drive letter is turned back into `D:\`.
 *
 * @param {string[]} argv
 * @returns {string | null}
 */
function parseExplorerOpenArg(argv) {
    const arg = argv.find(a => typeof a === 'string' && a.startsWith(ARG_PREFIX));
    if (arg === undefined) return null;

    let value = arg.slice(ARG_PREFIX.length).replace(/"+$/, '');
    if (/^[A-Za-z]:$/.test(value)) value += '\\';

    return value === '' ? null : value;
}

/**
 * @param {number} port
 * @param {string} targetPath
 * @returns {string}
 */
function buildExplorerOpenUrl(port, targetPath) {
    return `http://127.0.0.1:${port}/explorer/open?path=${encodeURIComponent(targetPath)}`;
}

/**
 * Keeps the latest requested path until the window has loaded, then navigates it by the port the
 * backend listens on *at that moment* (it changes across a plugin reload, issue #871).
 *
 * @param {{ getPort: () => (number | null), getWindow: () => (import('electron').BrowserWindow | null) }} deps
 */
function createExplorerOpen({ getPort, getWindow }) {
    let pending = null;
    let ready   = false;

    function flush() {
        const win  = getWindow();
        const port = getPort();
        if (pending === null || !ready || !win || win.isDestroyed() || port === null) return;

        const url = buildExplorerOpenUrl(port, pending);
        pending = null;
        win.loadURL(url, { extraHeaders: SHELL_EXTRA_HEADERS });
    }

    return {
        /** @param {string | null} targetPath */
        request(targetPath) {
            if (!targetPath) return;
            pending = targetPath;
            flush();
        },
        /** Call once the main window has finished its first load. */
        markReady() {
            ready = true;
            flush();
        },
    };
}

module.exports = { ARG_PREFIX, SHELL_EXTRA_HEADERS, parseExplorerOpenArg, buildExplorerOpenUrl, createExplorerOpen };
