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

const fs = require('fs');
const path = require('path');
const { Linter } = require('eslint');

const { stubOpenDialog, stubOpenDialogCancelled, stubMessageBox } = require('../../scripts/e2e/dialogs');
const { checkPrerequisites, exitCodeOf } = require('../../scripts/e2e/prereq');
const { covers } = require('../../scripts/e2e/coverage');

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
        "page.locator('#btn').click({ force: true });",
        "page.locator('#field').check({ force: true, timeout: 1000 });",
    ])('%s is rejected', (code) => {
        const messages = lintE2e(`'use strict';\n${code}\n`);

        expect(messages.some((m) => m.includes('No scripted interaction in E2E'))).toBe(true);
    });

    test('`force: true` of a plain filesystem call is not an interaction', () => {
        const messages = lintE2e("'use strict';\nrequire('fs').rmSync('/tmp/x', { recursive: true, force: true });\n");

        expect(messages.filter((m) => m.includes('No scripted interaction in E2E'))).toEqual([]);
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

describe('coverage label of a scenario', () => {
    test('routes and features become Playwright tags the matrix can read', () => {
        expect(covers({ routes: ['/anime/{id}', '/'], features: ['inline-editor'] })).toEqual({
            tag: ['@route:/anime/{id}', '@route:/', '@feature:inline-editor'],
        });
    });

    test('a scenario without any label is refused', () => {
        expect(() => covers({})).toThrow('at least one route or feature');
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

describe('e2e start-up diagnostics', () => {
    afterEach(() => jest.restoreAllMocks());

    describe.each([
        ['the binary', (file) => file.endsWith(path.join('.bin', 'playwright'))],
        ['the package', (file) => file.includes(path.join('@playwright', 'test'))],
    ])('when only %s is missing', (_name, isMissing) => {
        const platform = Object.getOwnPropertyDescriptor(process, 'platform');

        beforeEach(() => Object.defineProperty(process, 'platform', { value: 'linux' }));
        afterEach(() => Object.defineProperty(process, 'platform', platform));

        test('Playwright is reported with a hint to run npm ci', () => {
            jest.spyOn(fs, 'existsSync').mockImplementation((file) => !isMissing(String(file)));
            const errors = jest.spyOn(console, 'error').mockImplementation(() => {});
            jest.spyOn(process, 'exit').mockImplementation((code) => {
                throw new Error(`exit:${code}`);
            });

            expect(() => checkPrerequisites()).toThrow('exit:1');
            expect(errors.mock.calls.join('\n')).toMatch(/Playwright.*npm ci/);
        });
    });

    test('a spawn error is printed and gives a non-zero exit code', () => {
        const errors = jest.spyOn(console, 'error').mockImplementation(() => {});

        expect(exitCodeOf({ status: null, error: new Error('spawnSync playwright ENOENT') })).toBe(1);
        expect(errors.mock.calls.join('\n')).toContain('spawnSync playwright ENOENT');
    });

    test('the exit code of the child is kept', () => {
        expect(exitCodeOf({ status: 3 })).toBe(3);
        expect(exitCodeOf({ status: 0 })).toBe(0);
    });
});

/**
 * The helper exists because of a real red release run: `POST /settings/pagination-mode` showed up in
 * the trace with status -1 (aborted) because the scenario navigated away before the form submit
 * finished, the setting never applied, and the failure surfaced two steps later on a hidden
 * pagination. Both halves matter — the waiter has to be armed *before* the click, and the predicate
 * has to match the method and the exact path — so both are pinned here.
 */
describe('clickAwaitingPost', () => {
    const { clickAwaitingPost } = require('../../scripts/e2e/actions');

    /**
     * @param {{ method?: string, url?: string }} seen  the request the page will report
     */
    function pageDouble({ method = 'POST', url = 'http://127.0.0.1:8200/settings/pagination-mode', status = 303 } = {}) {
        const order = [];

        return {
            order,
            page: {
                waitForResponse: (predicate) => {
                    order.push('armed');

                    return Promise.resolve({
                        matched: predicate({ request: () => ({ method: () => method }), url: () => url }),
                        status: () => status,
                    });
                },
            },
            locator: { click: () => { order.push('clicked'); return Promise.resolve(); } },
        };
    }

    test('arms the response waiter before clicking', async () => {
        const { page, locator, order } = pageDouble();

        await clickAwaitingPost(page, locator, '/settings/pagination-mode');

        expect(order).toEqual(['armed', 'clicked']);
    });

    test('matches the POST of that exact path', async () => {
        const { page, locator } = pageDouble();

        await expect(clickAwaitingPost(page, locator, '/settings/pagination-mode'))
            .resolves.toMatchObject({ matched: true });
    });

    /**
     * The reason the helper exists is that an unapplied setting must fail here and not two steps
     * later. A 403 on a CSRF token that drifted, a 400 on a value the controller refuses and a 500
     * all leave the setting unapplied exactly like an aborted request does.
     */
    test.each([[400], [403], [500]])('fails on status %i', async (status) => {
        const { page, locator } = pageDouble({ status });

        await expect(clickAwaitingPost(page, locator, '/settings/pagination-mode'))
            .rejects.toThrow(`POST /settings/pagination-mode answered ${status}`);
    });

    /** 303 from /settings/pagination-mode and a plain 302 from the sync toggle are both success. */
    test.each([[302], [303]])('accepts status %i', async (status) => {
        const { page, locator } = pageDouble({ status });

        await expect(clickAwaitingPost(page, locator, '/settings/pagination-mode')).resolves.toMatchObject({ matched: true });
    });

    test.each([
        ['another method', { method: 'GET' }],
        ['another path', { url: 'http://127.0.0.1:8200/settings/theme' }],
        ['the path as a prefix of a longer one', { url: 'http://127.0.0.1:8200/settings/pagination-mode/extra' }],
    ])('does not match %s', async (_name, seen) => {
        const { page, locator } = pageDouble(seen);

        await expect(clickAwaitingPost(page, locator, '/settings/pagination-mode')).resolves.toMatchObject({ matched: false });
    });

    /** The query string is not part of the path and must not keep the response from matching. */
    test('ignores the query string', async () => {
        const { page, locator } = pageDouble({ url: 'http://127.0.0.1:8200/settings/pagination-mode?from=settings' });

        await expect(clickAwaitingPost(page, locator, '/settings/pagination-mode')).resolves.toMatchObject({ matched: true });
    });
});
