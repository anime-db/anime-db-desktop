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

const { BrowserWindow } = require('electron');
const path           = require('path');
const { getLocale }  = require('../config');
const i18n           = require('../i18n');

/**
 * Creates the splash window. Uses show: false — show it inside 'ready-to-show' handler.
 *
 * The locale and the translated initial status text are passed in via
 * webPreferences.additionalArguments rather than an IPC message: this is the only channel
 * available before the window's first paint, so splash.html can render the correct-locale text
 * from the start instead of flashing a hardcoded string while waiting for the first
 * "splash-progress" event (issue #404).
 *
 * @returns {BrowserWindow}
 */
function createSplash() {
    const locale = getLocale();

    const win = new BrowserWindow({
        width: 400,
        height: 300,
        frame: false,
        center: true,
        resizable: false,
        movable: false,
        skipTaskbar: true,
        show: false,
        webPreferences: {
            preload: path.join(__dirname, '..', 'splash', 'preload.js'),
            contextIsolation: true,
            nodeIntegration: false,
            additionalArguments: [
                `--splash-locale=${encodeURIComponent(locale)}`,
                `--splash-initial-status=${encodeURIComponent(i18n.t('splash.step_meilisearch', locale))}`,
            ],
        },
    });

    win.loadFile(path.join(__dirname, '..', 'splash', 'splash.html'));

    return win;
}

module.exports = { createSplash };
