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
const { covers, urlOf } = require('../coverage');
const { stubOpenDialog, stubOpenDialogCancelled } = require('../dialogs');

let folder;

test.beforeEach(() => {
    folder = fs.mkdtempSync(path.join(os.tmpdir(), 'animedb-e2e-storage-'));
});

test.afterEach(() => {
    fs.rmSync(folder, { recursive: true, force: true });
});

async function chooseWritableType(page) {
    const writable = await page.locator('#storage-new-type').getAttribute('data-writable-types');
    await page.locator('#storage-new-type').selectOption(writable.split(',')[0]);
}

test.describe('new storage', () => {
    test('a folder chosen in the native dialog becomes the storage and is listed', covers({ routes: ['/storage/new', '/storage'], features: ['storage-folder-picker', 'native-dialog'] }), async ({ app, page, session }) => {
        await stubOpenDialog(app, folder);
        await page.goto(urlOf(session, '/storage/new'));

        await page.locator('#storage-new-name').fill('Chosen folder');
        await chooseWritableType(page);
        await page.locator('#storage-new-pick-folder').click();
        await expect(page.locator('#storage-new-path')).toHaveValue(folder);
        await page.getByRole('button', { name: 'Create' }).click();

        await expect(page).not.toHaveURL(/\/storage\/new/);
        await page.goto(urlOf(session, '/storage'));
        const row = page.getByRole('row').filter({ hasText: 'Chosen folder' });
        await expect(row).toContainText(folder);
    });

    test('cancelling the dialog keeps the path field as it was', covers({ routes: ['/storage/new'], features: ['storage-folder-picker', 'native-dialog'] }), async ({ app, page, session }) => {
        await stubOpenDialogCancelled(app);
        await page.goto(urlOf(session, '/storage/new'));

        await chooseWritableType(page);
        await page.locator('#storage-new-path').fill('/typed/by/hand');
        await page.locator('#storage-new-pick-folder').click();

        await expect(page.locator('#storage-new-path')).toHaveValue('/typed/by/hand');
    });
});
