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

/*
 * Machine-readable coverage label of a scenario, consumed by the coverage matrix. It is a Playwright
 * tag, so `npx playwright test --list --reporter=json` carries it in the `tags` of every test:
 *
 *   @route:/anime/{id}     the route (Symfony path with {placeholders}) the scenario exercises
 *   @feature:inline-editor the feature name, kebab-case
 *
 * A scenario names at least one route or feature; a scenario spanning several routes lists each.
 * Usage: `test('title', covers({ routes: ['/anime/{id}'], features: ['inline-editor'] }), async ...)`.
 */

/**
 * @param {{ routes?: string[], features?: string[] }} labels
 * @returns {{ tag: string[] }}
 */
function covers({ routes = [], features = [] }) {
    const tag = [
        ...routes.map((route) => `@route:${route}`),
        ...features.map((feature) => `@feature:${feature}`),
    ];
    if (tag.length === 0) {
        throw new Error('A scenario needs at least one route or feature label.');
    }

    return { tag };
}

/**
 * @param {{ port: number }} session
 * @param {string} route
 * @returns {string}
 */
function urlOf(session, route) {
    return `http://127.0.0.1:${session.port}${route}`;
}

module.exports = { covers, urlOf };
