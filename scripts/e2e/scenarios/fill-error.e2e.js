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

const NOT_FOUND = 'The source found no match for this anime.';
const notices = (page) => page.locator('.anime-detail__fill-error');

test.use({ sourcePlugin: true });

test.describe('a fill error shows where the button was clicked', () => {
    test.beforeEach(async ({ page, session }) => {
        await page.goto(urlOf(session, '/anime/1'));
        await expect(notices(page)).toHaveCount(0);
    });

    for (const [name, selector] of [['cover', '#anime-media-1'], ['gallery', '#anime-gallery-1']]) {
        test(`${name}: the notice appears in its own block and nowhere else`, covers({ routes: ['/anime/{id}', '/anime/{id}/fill/{field}'], features: ['fill-error-placement'] }), async ({ page }) => {
            const block = page.locator(selector);
            await block.getByRole('button', { name: 'Fill from source' }).click();

            await expect(block.locator('.anime-detail__fill-error')).toHaveText(NOT_FOUND);
            // The one notice on the whole card is the one inside the clicked block.
            await expect(notices(page)).toHaveCount(1);
        });
    }
});
