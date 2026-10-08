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
const { clickAwaitingPost } = require('../actions');

const cards = (page) => page.locator('.anime-card');
const statusFilter = (page, status) => page.locator(`[data-filter-section="watch_status"] [data-value="${status}"] .anime-list__filter-value-name`);

test.describe('catalog list', () => {
    test('the watch status filter changes the result and reset brings the list back', covers({ routes: ['/', '/anime/{id}', '/anime/{id}/editable/watch_status', '/anime', '/anime/facets'], features: ['catalog-filter'] }), async ({ page, session }) => {
        // Every fixture entry is "Plan to Watch": move one to "Watching" the way a user does.
        await page.goto(urlOf(session, '/anime/2'));
        await page.locator('#anime-editable-2').getByRole('button', { name: 'Plan to Watch' }).click();
        await page.locator('#anime-editable-2 select[name="watch_status"]').selectOption('watching');
        await page.locator('#anime-editable-2').getByRole('button', { name: 'Save' }).click();
        await expect(page.locator('#anime-editable-2').getByRole('button', { name: 'Watching' })).toBeVisible();

        await page.goto(urlOf(session, '/'));
        await expect(cards(page)).toHaveCount(7);

        await statusFilter(page, 'watching').click();
        await expect(cards(page)).toHaveCount(1);
        await expect(cards(page).first().locator('.anime-card__title')).toHaveText('Spirited Away');

        await page.locator('#anime-list-chips-reset').click();
        await expect(cards(page)).toHaveCount(7);

        await statusFilter(page, 'plan').click();
        await expect(cards(page)).toHaveCount(6);
        await expect(page.locator('.anime-card__title', { hasText: 'Spirited Away' })).toHaveCount(0);
    });

    test('classic pagination: a narrow window pages the list and a page button switches the page', covers({ routes: ['/', '/settings', '/settings/pagination-mode', '/anime'], features: ['catalog-pagination'] }), async ({ app, page, session }) => {
        await page.goto(urlOf(session, '/settings'));
        // Форма уходит обычной навигацией: без ожидания POST-а следующий переход его отменяет, и
        // режим остаётся прежним (см. scripts/e2e/actions.js).
        await clickAwaitingPost(page, page.locator('label[for="pagination-mode-classic"]'), '/settings/pagination-mode');
        await expect(page.locator('#pagination-mode-classic')).toBeChecked();

        // One grid column makes the page six cards, so seven fixture entries need two pages.
        await app.evaluate(({ BrowserWindow }) => BrowserWindow.getAllWindows()[0].setSize(380, 900));
        await page.goto(urlOf(session, '/'));

        const pagination = page.locator('#anime-list-pagination');
        await expect(pagination).toBeVisible();
        await expect(pagination.getByRole('button')).toHaveText(['1', '2']);
        await expect(cards(page)).toHaveCount(6);

        await pagination.getByRole('button', { name: '2' }).click();
        await expect(cards(page)).toHaveCount(1);
        await expect(pagination.getByRole('button', { name: '2' })).toHaveAttribute('aria-current', 'true');

        await pagination.getByRole('button', { name: '1' }).click();
        await expect(cards(page)).toHaveCount(6);
    });

    test('a search with no hits shows the empty state, not the error', covers({ routes: ['/', '/anime'], features: ['catalog-empty-state'] }), async ({ page, session }) => {
        await page.goto(urlOf(session, '/'));
        await expect(cards(page)).toHaveCount(7);

        await page.locator('#anime-list-search').fill('no such title anywhere');

        await expect(page.locator('#anime-list-empty')).toBeVisible();
        await expect(page.locator('#anime-list-empty')).toHaveText('The list is empty.');
        await expect(page.locator('#anime-list-error')).toBeHidden();
        await expect(cards(page)).toHaveCount(0);

        // Emptying the field is the way back: the entries return and the empty state goes away.
        await page.locator('#anime-list-search').fill('');
        await expect(cards(page)).toHaveCount(7);
        await expect(page.locator('#anime-list-empty')).toBeHidden();
    });
});
