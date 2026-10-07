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

// Fullmetal Alchemist: Brotherhood — a 64-episode series, "Plan to Watch" in the fixture.
const CARD = '/anime/1';
const editable = (page) => page.locator('#anime-editable-1');

test.describe('card inline editor', () => {
    test.beforeEach(async ({ page, session }) => {
        await page.goto(urlOf(session, CARD));
    });

    test('watch status: open, change, save — the badge shows the server answer', covers({ routes: ['/anime/{id}', '/anime/{id}/editable/watch_status'], features: ['inline-editor'] }), async ({ page, session }) => {
        await editable(page).getByRole('button', { name: 'Plan to Watch' }).click();
        await editable(page).locator('select[name="watch_status"]').selectOption('watching');
        await editable(page).getByRole('button', { name: 'Save' }).click();

        await expect(editable(page).getByRole('button', { name: 'Watching' })).toBeVisible();
        await expect(editable(page).locator('select[name="watch_status"]')).toHaveCount(0);

        // Persisted, not just repainted.
        await page.goto(urlOf(session, CARD));
        await expect(editable(page).getByRole('button', { name: 'Watching' })).toBeVisible();
    });

    test('episode progress: the saved value is rendered from the server response', covers({ routes: ['/anime/{id}', '/anime/{id}/editable/watched_episodes'], features: ['inline-editor'] }), async ({ page }) => {
        await editable(page).getByRole('button', { name: '0 / 64' }).click();
        // The server normalises "007" to 7; the page can only show "7 / 64" if it painted the answer.
        await editable(page).locator('input[name="watched_episodes"]').fill('007');
        await editable(page).getByRole('button', { name: 'Save' }).click();

        await expect(editable(page).getByRole('button', { name: '7 / 64' })).toBeVisible();
        await expect(editable(page).locator('input[name="watched_episodes"]')).toHaveCount(0);
    });

    test('cancel closes the editor and saves nothing', covers({ routes: ['/anime/{id}', '/anime/{id}/editable/watch_status'], features: ['inline-editor'] }), async ({ page, session }) => {
        await editable(page).getByRole('button', { name: 'Plan to Watch' }).click();
        await editable(page).locator('select[name="watch_status"]').selectOption('dropped');
        await editable(page).getByRole('button', { name: 'Cancel' }).click();

        await expect(editable(page).locator('select[name="watch_status"]')).toHaveCount(0);
        await expect(editable(page).getByRole('button', { name: 'Plan to Watch' })).toBeVisible();

        await page.goto(urlOf(session, CARD));
        await expect(editable(page).getByRole('button', { name: 'Plan to Watch' })).toBeVisible();
    });

    test('a save error keeps the editor open with the message', covers({ routes: ['/anime/{id}', '/anime/{id}/editable/watched_episodes'], features: ['inline-editor', 'inline-editor-error'] }), async ({ page }) => {
        await editable(page).getByRole('button', { name: '0 / 64' }).click();
        const input = editable(page).locator('input[name="watched_episodes"]');
        // An empty number field passes the browser's own validation and is refused by the server.
        await input.fill('');
        await editable(page).getByRole('button', { name: 'Save' }).click();

        await expect(editable(page).locator('.anime-detail__editable-error')).toHaveText('Invalid watched episode count.');
        await expect(input).toBeVisible();
        await expect(editable(page).getByRole('button', { name: 'Save' })).toBeVisible();
    });
});
