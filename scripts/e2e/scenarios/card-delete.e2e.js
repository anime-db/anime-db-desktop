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

// Gintama — no sync source is linked, so the confirmation is the app's modal "confirm" prompt.
const CARD = '/anime/3';

async function startDelete(page) {
    await page.getByRole('button', { name: 'Entry actions' }).click();
    await page.getByRole('button', { name: 'Delete' }).click();
}

test.describe('card deletion', () => {
    test('the prompt names the entry; cancelling closes it and deletes nothing', covers({ routes: ['/anime/{id}', '/anime/{id}/delete'], features: ['delete-confirm'] }), async ({ page, session }) => {
        await page.goto(urlOf(session, CARD));

        const prompt = new Promise((resolve) => page.once('dialog', async (dialog) => {
            const message = dialog.message();
            await dialog.dismiss();
            resolve(message);
        }));
        await startDelete(page);

        // The title is wrapped in bidi isolation marks (U+2068/U+2069) by the template.
        expect(await prompt).toMatch(/Delete "\u2068?Gintama\u2069?"\?/);
        await expect(page).toHaveURL(urlOf(session, CARD));
        await expect(page.locator('.anime-detail__title')).toHaveText('Gintama');

        await page.goto(urlOf(session, '/'));
        await expect(page.locator('.anime-card')).toHaveCount(7);
    });

    test('confirming deletes the entry and returns to the catalog without it', covers({ routes: ['/anime/{id}', '/anime/{id}/delete', '/'], features: ['delete-confirm'] }), async ({ page, session }) => {
        await page.goto(urlOf(session, CARD));

        page.once('dialog', (dialog) => dialog.accept());
        await startDelete(page);

        await expect(page).toHaveURL(urlOf(session, '/'));
        await expect(page.locator('.anime-card')).toHaveCount(6);
        await expect(page.locator('.anime-card__title', { hasText: 'Gintama' })).toHaveCount(0);

        const gone = await page.goto(urlOf(session, CARD));
        expect(gone.status()).toBe(404);
    });
});
