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

const { contextBridge, ipcRenderer } = require('electron');
const path = require('path');

const logoPath = 'file://' + path.join(__dirname, '..', '..', 'resources', 'logo.png').replace(/\\/g, '/');

/**
 * Reads a `--name=value` entry passed via BrowserWindow's webPreferences.additionalArguments —
 * the only way to hand the resolved locale and its initial status text to this window before its
 * first paint, so splash.html never flashes hardcoded Russian text while waiting for the first
 * "splash-progress" IPC message (issue #404).
 *
 * @param {string} name
 * @returns {string}
 */
function readArg(name) {
    const prefix = `--${name}=`;
    const arg = process.argv.find(a => a.startsWith(prefix));
    return arg ? decodeURIComponent(arg.slice(prefix.length)) : '';
}

contextBridge.exposeInMainWorld('splash', {
    logoPath,
    locale:        readArg('splash-locale'),
    initialStatus: readArg('splash-initial-status'),
    onProgress: (cb) => ipcRenderer.on('splash-progress', (_event, data) => cb(data)),
});
