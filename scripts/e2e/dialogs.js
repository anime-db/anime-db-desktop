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

/*
 * Replaces native dialogs in the real Electron main process of a run (`electronApp.evaluate`),
 * so nothing in the app needs to change and no modal dialog is left open for nobody to close.
 * A stub applies until the app exits; call it again to change the answer.
 */

/**
 * `dialog.showOpenDialog` answers with the given paths (window.animeDb.pickFolder / pickFile).
 *
 * @param {import('@playwright/test').ElectronApplication} app
 * @param {string|string[]} filePaths
 */
async function stubOpenDialog(app, filePaths) {
    const paths = Array.isArray(filePaths) ? filePaths : [filePaths];
    await app.evaluate(({ dialog }, answer) => {
        dialog.showOpenDialog = async () => ({ canceled: false, filePaths: answer });
    }, paths);
}

/**
 * `dialog.showOpenDialog` answers "cancelled".
 *
 * @param {import('@playwright/test').ElectronApplication} app
 */
async function stubOpenDialogCancelled(app) {
    await app.evaluate(({ dialog }) => {
        dialog.showOpenDialog = async () => ({ canceled: true, filePaths: [] });
    });
}

/**
 * `dialog.showMessageBox` and `dialog.showMessageBoxSync` answer with the button index.
 *
 * @param {import('@playwright/test').ElectronApplication} app
 * @param {number} response
 */
async function stubMessageBox(app, response = 0) {
    await app.evaluate(({ dialog }, answer) => {
        dialog.showMessageBox = async () => ({ response: answer, checkboxChecked: false });
        dialog.showMessageBoxSync = () => answer;
    }, response);
}

module.exports = { stubOpenDialog, stubOpenDialogCancelled, stubMessageBox };
