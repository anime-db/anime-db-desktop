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

const { buildMatrix, collectScenarios, groupRoutes, renderMarkdown, validateExclusions } = require('../../scripts/coverage-matrix/matrix');

const routes = groupRoutes({
    a: { path: '/a', method: 'GET' },
    b: { path: '/a', method: 'POST' },
    c: { path: '/b', method: 'GET' },
    d: { path: '/health', method: 'GET' },
});
const exclusions = [{ path: '/health', reason: 'probe' }];
const scenario = (title, routesOf = [], features = []) => ({ file: 'x.e2e.js', title, routes: routesOf, features });

describe('coverage matrix', () => {
    test('routes are grouped by path with methods collected', () => {
        expect(routes).toEqual([
            { path: '/a', methods: ['GET', 'POST'] },
            { path: '/b', methods: ['GET'] },
            { path: '/health', methods: ['GET'] },
        ]);
    });

    test('collectScenarios walks nested suites and strips the leading @ from tags', () => {
        const report = { suites: [{ suites: [{ suites: [{ specs: [
            { file: 'f.js', title: 't', tags: ['@route:/a', '@feature:f1'] },
            { file: 'f.js', title: 'plain', tags: ['route:/b', 'feature:f2'] },
            { file: 'f.js', title: 'bare', tags: [] },
        ] }] }] }] };
        expect(collectScenarios(report)).toEqual([
            { file: 'f.js', title: 't', routes: ['/a'], features: ['f1'] },
            { file: 'f.js', title: 'plain', routes: ['/b'], features: ['f2'] },
            { file: 'f.js', title: 'bare', routes: [], features: [] },
        ]);
    });

    test('covered, uncovered and excluded paths are split; excluded are in neither list', () => {
        const m = buildMatrix(routes, [scenario('s1', ['/a'])], exclusions);
        expect(m.covered.map((r) => r.path)).toEqual(['/a']);
        expect(m.uncovered.map((r) => r.path)).toEqual(['/b']);
        expect(m.excluded).toHaveLength(1);
        expect(m.summary).toEqual({ covered: 1, total: 2 });
    });

    test('a route without a scenario is uncovered; removing the label removes the scenario from the matrix', () => {
        const withLabel = buildMatrix(routes, [scenario('s1', ['/a'])], exclusions);
        const without   = buildMatrix(routes, [scenario('s1')], exclusions);
        expect(withLabel.uncovered.map((r) => r.path)).toEqual(['/b']);
        expect(without.uncovered.map((r) => r.path)).toEqual(['/a', '/b']);
        expect(JSON.stringify(without.covered)).not.toContain('s1');
        expect(without.unlabelled).toEqual(['x.e2e.js: s1']);
    });

    test('a route label without a route is reported', () => {
        const m = buildMatrix(routes, [scenario('s1', ['/gone'])], exclusions);
        expect(m.unknownRoutes).toEqual([{ route: '/gone', scenario: 'x.e2e.js: s1' }]);
    });

    test('features are listed apart from routes', () => {
        const m = buildMatrix(routes, [scenario('s1', [], ['f1']), scenario('s2', [], ['f1'])], exclusions);
        expect(m.features).toEqual([{ feature: 'f1', scenarios: ['x.e2e.js: s1', 'x.e2e.js: s2'] }]);
        expect(m.unlabelled).toEqual([]);
    });

    test('a label on an excluded path is shown in the excluded section', () => {
        const m = buildMatrix(routes, [scenario('s1', ['/health'])], exclusions);
        expect(m.excluded[0].labelledBy).toEqual(['x.e2e.js: s1']);
    });

    test('an exclusion without a reason is an error', () => {
        expect(() => validateExclusions([{ path: '/x' }])).toThrow(/no reason/);
        expect(() => validateExclusions([{ path: '/x', reason: '  ' }])).toThrow(/no reason/);
        expect(() => buildMatrix(routes, [], [{ path: '/x', reason: '' }])).toThrow(/no reason/);
    });

    test('the shipped exclusions.json is valid', () => {
        expect(validateExclusions(require('../../scripts/coverage-matrix/exclusions.json')).map((e) => e.path)).toEqual(['/health', '/ws']);
    });

    test('markdown carries the summary and every section', () => {
        const md = renderMarkdown(buildMatrix(routes, [scenario('s1', ['/a'])], exclusions));
        expect(md).toContain('Covered paths: 1 of 2');
        for (const h of ['Covered paths', 'Uncovered paths', 'Features', 'Excluded paths', 'Scenarios without labels', 'Route labels without a route']) {
            expect(md).toContain(`## ${h}`);
        }
    });
});
