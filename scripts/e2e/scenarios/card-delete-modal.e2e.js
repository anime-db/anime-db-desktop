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

const { test, expect } = require('../fixtures');
const { covers, urlOf } = require('../coverage');
const { setSourceMode, removedFromSource, consumeQueuedRemovals } = require('../plugins');

// Gintama, linked to the offline source below, so the confirmation is the delete modal that offers
// to delete from the source's list as well.
const CARD = '/anime/3';
const EXTERNAL_ID = 'e2e-linkable';

test.use({ sourcePlugin: true });

// Everything is arranged through the UI: sync is switched on in the plugin settings and the entry
// is linked to the source through the "search in plugins" screen.
async function linkToSource(page, session) {
    setSourceMode(session.dataDir, 'linkable', 3);

    await page.goto(urlOf(session, '/settings/plugins'));
    await page.getByRole('switch', { name: 'Synchronization: off' }).click();
    // Switching sync on lands on the plugin's own settings page, which this fixture plugin does not have.
    await expect(page).toHaveURL(urlOf(session, '/settings/plugins/e2e-source'));
    await page.goto(urlOf(session, '/settings/plugins'));
    await expect(page.getByRole('switch', { name: 'Synchronization: on' })).toBeVisible();

    await page.goto(urlOf(session, '/anime/search-plugins'));
    await page.getByRole('searchbox', { name: 'Title' }).fill('Gintama');
    await page.getByRole('button', { name: 'Search' }).click();
    await page.locator('.search-plugins__candidate').click();
    await page.getByRole('button', { name: 'Fill in the existing record' }).click();
    await expect(page).toHaveURL(urlOf(session, CARD));
}

async function openModal(page) {
    await page.getByRole('button', { name: 'Entry actions' }).click();
    await page.getByRole('button', { name: 'Delete' }).click();

    const modal = page.locator('.modal.show');
    await expect(modal).toBeVisible();

    return modal;
}

test.describe('card deletion with sources', () => {
    test.beforeEach(async ({ page, session }) => {
        await linkToSource(page, session);
    });

    test('the modal names the entry and the source it will also be deleted from', covers({ routes: ['/anime/{id}'], features: ['delete-modal'] }), async ({ page }) => {
        const modal = await openModal(page);

        // The title is wrapped in bidi isolation marks (U+2068/U+2069) by the template.
        await expect(modal).toContainText(/Delete "⁨?Gintama⁩?"\?/);
        await expect(modal.getByRole('checkbox', { name: 'Also delete from the lists on the sources' })).toBeChecked();
        await expect(modal).toContainText(/It will be deleted from the list on: ⁨?E2E source⁩?\./);
    });

    test('cancelling closes the modal and deletes the entry neither locally nor on the source', covers({ routes: ['/anime/{id}', '/anime/{id}/delete'], features: ['delete-modal'] }), async ({ page, session }) => {
        const modal = await openModal(page);
        await modal.getByRole('button', { name: 'Cancel' }).click();

        await expect(page.locator('.modal.show')).toHaveCount(0);
        await expect(page).toHaveURL(urlOf(session, CARD));
        await expect(page.locator('.anime-detail__title')).toHaveText('Gintama');

        await page.goto(urlOf(session, '/'));
        await expect(page.locator('.anime-card')).toHaveCount(7);
        consumeQueuedRemovals(session.dataDir);
        expect(removedFromSource(session.dataDir)).toEqual([]);
    });

    test('confirming deletes the entry and removes it from the source', covers({ routes: ['/anime/{id}', '/anime/{id}/delete', '/'], features: ['delete-modal'] }), async ({ page, session }) => {
        const modal = await openModal(page);
        await modal.getByRole('button', { name: 'Delete', exact: true }).click();

        await expect(page).toHaveURL(urlOf(session, '/'));
        await expect(page.locator('.anime-card')).toHaveCount(6);
        await expect(page.locator('.anime-card__title', { hasText: 'Gintama' })).toHaveCount(0);
        expect((await page.goto(urlOf(session, CARD))).status()).toBe(404);

        // The removal on the source is a queued job: nothing reaches the plugin before it runs.
        expect(removedFromSource(session.dataDir)).toEqual([]);
        consumeQueuedRemovals(session.dataDir);
        expect(removedFromSource(session.dataDir)).toEqual([EXTERNAL_ID]);
    });
});
