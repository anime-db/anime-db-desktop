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

const path = require('path');
const { Linter } = require('eslint');

const { stubOpenDialog, stubOpenDialogCancelled, stubMessageBox } = require('../../scripts/e2e/dialogs');

const rootDir = path.resolve(__dirname, '..', '..');

/**
 * @param {string} code
 * @returns {string[]} messages of the lint run as if the code lived in scripts/e2e/
 */
function lintE2e(code) {
    const config   = require(path.join(rootDir, 'eslint.config.js'));
    const messages = new Linter({ cwd: rootDir, configType: 'flat' })
        .verify(code, config, { filename: path.join(rootDir, 'scripts/e2e/scenarios/x.e2e.js') });

    return messages.map((m) => m.message);
}

describe('E2E lint guard: scripted interaction is forbidden', () => {
    test.each([
        "page.evaluate(() => document.querySelector('button').click());",
        "page.evaluate(() => { const el = document.body; el.dispatchEvent(new Event('x')); });",
        "app.evaluate(() => { window.x.click(); });",
        "page.evaluate(\"document.querySelector('button').click()\");",
        "win.webContents.executeJavaScript(`document.getElementById('a').click()`);",
        "page.$eval('#btn', (el) => el.click());",
        "page.$$eval('#btn', (els) => els[0].click());",
        "page.evalOnSelector('#btn', (el) => el.click());",
        "page.evalOnSelectorAll('#btn', (els) => els[0].click());",
        "page.locator('#btn').dispatchEvent('click');",
        "page.dispatchEvent('#btn', 'click');",
    ])('%s is rejected', (code) => {
        const messages = lintE2e(`'use strict';\n${code}\n`);

        expect(messages.some((m) => m.includes('No scripted interaction in E2E'))).toBe(true);
    });

    test('locator.click() and a DOM-building evaluate() are allowed', () => {
        const messages = lintE2e(
            "'use strict';\nasync function f(page) {\n"
            + "    await page.evaluate(() => { document.body.append(document.createElement('div')); });\n"
            + "    await page.locator('#a').click();\n}\nf();\n",
        );

        expect(messages.filter((m) => m.includes('No scripted interaction in E2E'))).toEqual([]);
    });
});

/**
 * An Electron app double whose main-process `dialog` module is observable.
 *
 * @returns {{ app: object, dialog: object }}
 */
function fakeApp() {
    const dialog = {};

    return {
        dialog,
        app: { evaluate: async (fn, arg) => fn({ dialog }, arg) },
    };
}

describe('native dialog stubs', () => {
    test('stubOpenDialog answers with the given path, not cancelled', async () => {
        const { app, dialog } = fakeApp();
        await stubOpenDialog(app, '/tmp/folder');

        await expect(dialog.showOpenDialog()).resolves.toEqual({ canceled: false, filePaths: ['/tmp/folder'] });
    });

    test('stubOpenDialogCancelled answers "cancelled"', async () => {
        const { app, dialog } = fakeApp();
        await stubOpenDialogCancelled(app);

        await expect(dialog.showOpenDialog()).resolves.toEqual({ canceled: true, filePaths: [] });
    });

    test('stubMessageBox answers the button index for both variants', async () => {
        const { app, dialog } = fakeApp();
        await stubMessageBox(app, 1);

        await expect(dialog.showMessageBox()).resolves.toEqual({ response: 1, checkboxChecked: false });
        expect(dialog.showMessageBoxSync()).toBe(1);
    });
});
