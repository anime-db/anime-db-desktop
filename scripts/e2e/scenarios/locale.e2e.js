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

test('switching the interface language changes the visible text and survives a reload', covers({ routes: ['/settings'], features: ['locale-switch'] }), async ({ page, session }) => {
    await page.goto(urlOf(session, '/settings'));
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Settings');
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');

    await page.locator('#locale').selectOption('ru');

    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Настройки');
    await expect(page.locator('label[for="locale"]')).toHaveText('Язык интерфейса');
    await expect(page.locator('html')).toHaveAttribute('lang', 'ru');

    await page.goto(urlOf(session, '/'));
    await expect(page.locator('#anime-list-search')).toHaveAttribute('placeholder', 'Поиск по названию…');

    await page.goto(urlOf(session, '/settings'));
    await page.locator('#locale').selectOption('en');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Settings');
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
});
