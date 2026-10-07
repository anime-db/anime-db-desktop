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
const os   = require('os');
const path = require('path');

const { test, expect } = require('../fixtures');
const { stubOpenDialog } = require('../dialogs');

test('app starts and the catalog page is rendered', async ({ page }) => {
    await expect(page.locator('nav.app-nav')).toBeVisible();
});

test('a trusted click navigates', async ({ page, session }) => {
    await page.locator('a.app-nav__link[href="/settings"]').click();
    await expect(page).toHaveURL(`http://127.0.0.1:${session.port}/settings`);
});

test('prod error page: an unknown route is a 404 without the debug exception header', async ({ page, session }) => {
    const response = await page.goto(`http://127.0.0.1:${session.port}/no-such-page`);
    expect(response.status()).toBe(404);
    expect(response.headers()['x-debug-exception']).toBeUndefined();
});

test('a click on an element covered by another element fails instead of passing', async ({ page }) => {
    await page.evaluate(() => {
        const button = document.createElement('button');
        button.id = 'e2e-covered';
        button.textContent = 'covered';
        const overlay = document.createElement('div');
        overlay.id = 'e2e-overlay';
        overlay.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.2)';
        document.body.append(button, overlay);
    });

    // The overlay intercepts pointer events: Playwright must refuse, not click through it.
    await expect(page.locator('#e2e-covered').click({ timeout: 1500 })).rejects.toThrow(/intercepts pointer events|Timeout/);
});

test('the pick-folder button gets the stubbed native dialog answer without a real dialog', async ({ app, page, session }) => {
    const folder = fs.mkdtempSync(path.join(os.tmpdir(), 'animedb-e2e-folder-'));
    try {
        await stubOpenDialog(app, folder);

        await page.goto(`http://127.0.0.1:${session.port}/storage/new`);
        const writable = await page.locator('#storage-new-type').getAttribute('data-writable-types');
        await page.locator('#storage-new-type').selectOption(writable.split(',')[0]);
        await page.locator('#storage-new-pick-folder').click();

        await expect(page.locator('#storage-new-path')).toHaveValue(folder);
    } finally {
        fs.rmSync(folder, { recursive: true, force: true });
    }
});
