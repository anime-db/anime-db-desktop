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
const { setSourceMode } = require('../plugins');

// The card of "Fullmetal Alchemist: Brotherhood"; the offline source plugin (plugins.js) gives it
// the "Fill from source" buttons next to the cover and above the gallery.
const gallery = (page) => page.locator('#anime-gallery-1');
const fillButton = (section) => section.getByRole('button', { name: 'Fill from source' });

test.use({ sourcePlugin: true });

/** Puts a marker on a DOM node: it is gone if the node is replaced, whatever its content. */
async function mark(locator) {
    await locator.evaluate((el) => { el.dataset.e2eMark = 'kept'; });
}

test.describe('card gallery', () => {
    test('applying a fill redraws the gallery, not the whole card', covers({ routes: ['/anime/{id}', '/anime/{id}/fill/{field}'], features: ['gallery', 'htmx-partial-swap'] }), async ({ page, session }) => {
        setSourceMode(session.dataDir, 'images', 1);
        await page.goto(urlOf(session, '/anime/1'));
        await expect(gallery(page).locator('li')).toHaveCount(0);

        const untouched = [
            page.locator('.anime-detail__title'),
            page.locator('#anime-editable-1'),
            page.locator('.anime-detail__labels'),
            page.locator('#anime-media-1'),
            page.locator('.anime-detail__summary'),
        ];
        for (const node of [...untouched, gallery(page)]) {
            await mark(node);
        }

        await fillButton(gallery(page)).click();

        await expect(gallery(page).locator('li')).toHaveCount(1);
        await expect(gallery(page).locator('[data-e2e-mark]')).toHaveCount(0);
        await expect(gallery(page)).not.toHaveAttribute('data-e2e-mark', /.*/);
        for (const node of untouched) {
            await expect(node).toHaveAttribute('data-e2e-mark', 'kept');
        }
    });
});
