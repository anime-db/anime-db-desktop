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

const labels = (page) => page.locator('.anime-detail__labels');
const viewLabels = (page) => labels(page).locator('[data-labels-view] .anime-detail__label-link');
const chips = (page) => labels(page).locator('.anime-detail__labels-chip');
const input = (page) => labels(page).locator('[data-labels-input]');

async function openEditor(page) {
    await labels(page).getByRole('button', { name: 'Edit tags' }).click();
    await expect(input(page)).toBeVisible();
}

async function save(page) {
    await labels(page).getByRole('button', { name: 'Save' }).click();
    await expect(labels(page).locator('[data-labels-editor]')).toBeHidden();
}

test.describe('card labels', () => {
    test('add a chip and save — the label shows in the card and survives a reload', covers({ routes: ['/anime/{id}', '/anime/{id}/labels'], features: ['card-labels'] }), async ({ page, session }) => {
        await page.goto(urlOf(session, '/anime/1'));
        await openEditor(page);

        await input(page).fill('Favourite');
        await input(page).press('Enter');
        await expect(chips(page)).toHaveText(['Sample×', 'Favourite×']);
        await save(page);

        await expect(viewLabels(page)).toHaveText(['Sample', 'Favourite']);
        await page.reload();
        await expect(viewLabels(page)).toHaveText(['Sample', 'Favourite']);
    });

    test('remove a chip and save — the label is gone from the card', covers({ routes: ['/anime/{id}', '/anime/{id}/labels'], features: ['card-labels'] }), async ({ page, session }) => {
        await page.goto(urlOf(session, '/anime/1'));
        await openEditor(page);

        await chips(page).filter({ hasText: 'Sample' }).getByRole('button').click();
        await expect(chips(page)).toHaveCount(0);
        await save(page);

        await expect(viewLabels(page)).toHaveCount(0);
        await page.reload();
        await expect(viewLabels(page)).toHaveCount(0);
    });

    test('cancel leaves the labels as they were', covers({ routes: ['/anime/{id}'], features: ['card-labels'] }), async ({ page, session }) => {
        await page.goto(urlOf(session, '/anime/1'));
        await openEditor(page);
        await input(page).fill('Discarded');
        await input(page).press('Enter');
        await labels(page).getByRole('button', { name: 'Cancel' }).click();

        await expect(labels(page).locator('[data-labels-editor]')).toBeHidden();
        await expect(labels(page).locator('[data-labels-view]')).toBeVisible();
        await expect(viewLabels(page)).toHaveText(['Sample']);
        await page.reload();
        await expect(viewLabels(page)).toHaveText(['Sample']);
    });

    test('autocomplete suggests a label from the catalog, a click turns it into a chip', covers({ routes: ['/anime/{id}', '/anime/{id}/labels', '/labels'], features: ['card-labels', 'labels-autocomplete'] }), async ({ page, session }) => {
        // Put a label into the catalog through one card ...
        await page.goto(urlOf(session, '/anime/1'));
        await openEditor(page);
        await input(page).fill('Zeta');
        await input(page).press('Enter');
        await save(page);

        // ... and find it from another one.
        await page.goto(urlOf(session, '/anime/2'));
        await openEditor(page);
        await input(page).fill('zet');
        const suggestion = labels(page).locator('[data-labels-suggestions]').getByRole('button', { name: 'Zeta' });
        await expect(suggestion).toBeVisible();
        await suggestion.click();

        await expect(chips(page)).toHaveText(['Sample×', 'Zeta×']);
        await expect(labels(page).locator('[data-labels-suggestions]')).toBeHidden();
        await save(page);
        await expect(viewLabels(page)).toHaveText(['Sample', 'Zeta']);
    });

    test('a name with no match in the catalog is accepted as a new label', covers({ routes: ['/anime/{id}', '/anime/{id}/labels'], features: ['card-labels', 'labels-autocomplete'] }), async ({ page, session }) => {
        await page.goto(urlOf(session, '/anime/1'));
        await openEditor(page);

        await input(page).fill('Nothing like it');
        await expect(labels(page).locator('[data-labels-suggestions]')).toBeHidden();
        await input(page).press('Enter');
        await expect(chips(page)).toHaveText(['Sample×', 'Nothing like it×']);
        await save(page);

        await expect(viewLabels(page)).toHaveText(['Sample', 'Nothing like it']);
        // The created label is a real catalog entry: its link filters the catalog.
        await viewLabels(page).filter({ hasText: 'Nothing like it' }).click();
        await expect(page).toHaveURL(/\/\?labels=\d+/);
        await expect(page.locator('.anime-card')).toHaveCount(1);
    });
});
