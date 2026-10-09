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
    folder = fs.mkdtempSync(path.join(os.tmpdir(), 'animedb-e2e-backup-'));
});

test.afterEach(() => {
    fs.rmSync(folder, { recursive: true, force: true });
});

test.describe('settings: backup', () => {
    test('the page opens in the app with an empty destination and a disabled start button', covers({ routes: ['/settings/backup'], features: ['catalog-backup'] }), async ({ page, session }) => {
        await page.goto(urlOf(session, '/settings/backup'));

        await expect(page.getByRole('heading', { level: 1 })).toHaveText('Catalog export and import');
        await expect(page.locator('#settings-backup-unavailable')).toBeHidden();
        await expect(page.locator('#settings-backup-path')).toHaveValue('');
        await expect(page.locator('#settings-backup-start')).toBeDisabled();
        await expect(page.locator('#settings-backup-snapshots-empty')).toBeVisible();
    });

    test('choosing a folder fills the destination and enables the start button', covers({ routes: ['/settings/backup'], features: ['catalog-backup', 'native-dialog'] }), async ({ app, page, session }) => {
        await stubOpenDialog(app, folder);
        await page.goto(urlOf(session, '/settings/backup'));

        await page.locator('#settings-backup-pick-folder').click();

        await expect(page.locator('#settings-backup-path')).toHaveValue(folder);
        await expect(page.locator('#settings-backup-start')).toBeEnabled();
    });

    test('cancelling the folder dialog changes nothing', covers({ routes: ['/settings/backup'], features: ['catalog-backup', 'native-dialog'] }), async ({ app, page, session }) => {
        await stubOpenDialogCancelled(app);
        await page.goto(urlOf(session, '/settings/backup'));

        await page.locator('#settings-backup-pick-folder').click();

        await expect(page.locator('#settings-backup-path')).toHaveValue('');
        await expect(page.locator('#settings-backup-start')).toBeDisabled();
    });

    test('choosing an archive file fills the import path and enables the import button', covers({ routes: ['/settings/backup'], features: ['catalog-backup', 'native-dialog'] }), async ({ app, page, session }) => {
        const archive = path.join(folder, 'catalog.zip');
        fs.writeFileSync(archive, '');
        await stubOpenDialog(app, archive);
        await page.goto(urlOf(session, '/settings/backup'));

        await expect(page.locator('#settings-import-start')).toBeDisabled();
        await page.locator('#settings-import-pick-file').click();

        await expect(page.locator('#settings-import-path')).toHaveValue(archive);
        await expect(page.locator('#settings-import-start')).toBeEnabled();
    });
});
