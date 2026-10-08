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
 * Pure logic of the coverage matrix: routes x scenario labels -> report. No PHP, no Playwright.
 * The matrix is an artifact, not a gate: nothing here decides a verdict or a threshold.
 */

const ROUTE_PREFIX   = 'route:';
const FEATURE_PREFIX = 'feature:';

/**
 * Groups `debug:router --format=json` output by path (one path may carry several routes).
 *
 * @param {Record<string, { path: string, method?: string }>} routerJson
 * @returns {{ path: string, methods: string[] }[]} sorted by path
 */
function groupRoutes(routerJson) {
    /** @type {Map<string, Set<string>>} */
    const byPath = new Map();
    for (const route of Object.values(routerJson)) {
        if (!byPath.has(route.path)) {
            byPath.set(route.path, new Set());
        }
        byPath.get(route.path).add(route.method || 'ANY');
    }

    return [...byPath.entries()]
        .map(([path, methods]) => ({ path, methods: [...methods].sort() }))
        .sort((a, b) => (a.path < b.path ? -1 : a.path > b.path ? 1 : 0));
}

/**
 * Collects specs of a Playwright JSON report; suites are walked recursively, so nesting depth
 * does not matter. Tags come without the leading `@`.
 *
 * @param {{ suites?: object[] }} report
 * @returns {{ file: string, title: string, routes: string[], features: string[] }[]}
 */
function collectScenarios(report) {
    const scenarios = [];
    const walk = (suite) => {
        for (const spec of suite.specs || []) {
            const tags = (spec.tags || []).map((tag) => tag.replace(/^@/, ''));
            scenarios.push({
                file: spec.file || suite.file || '',
                title: spec.title,
                routes: tags.filter((t) => t.startsWith(ROUTE_PREFIX)).map((t) => t.slice(ROUTE_PREFIX.length)),
                features: tags.filter((t) => t.startsWith(FEATURE_PREFIX)).map((t) => t.slice(FEATURE_PREFIX.length)),
            });
        }
        (suite.suites || []).forEach(walk);
    };
    (report.suites || []).forEach(walk);

    return scenarios;
}

/**
 * @param {unknown} exclusions parsed exclusions.json
 * @returns {{ path: string, reason: string }[]}
 */
function validateExclusions(exclusions) {
    if (!Array.isArray(exclusions)) {
        throw new Error('exclusions.json must be an array of {"path", "reason"} objects.');
    }
    for (const item of exclusions) {
        if (!item || typeof item.path !== 'string' || item.path === '') {
            throw new Error(`exclusions.json: an entry has no path: ${JSON.stringify(item)}`);
        }
        if (typeof item.reason !== 'string' || item.reason.trim() === '') {
            throw new Error(`exclusions.json: the exclusion of "${item.path}" has no reason.`);
        }
    }

    return exclusions;
}

/**
 * @param {string} scenario
 * @returns {string}
 */
function scenarioName(scenario) {
    return `${scenario.file}: ${scenario.title}`;
}

/**
 * @param {{ path: string, methods: string[] }[]} routes
 * @param {{ file: string, title: string, routes: string[], features: string[] }[]} scenarios
 * @param {{ path: string, reason: string }[]} exclusions
 */
function buildMatrix(routes, scenarios, exclusions) {
    validateExclusions(exclusions);
    const excluded = new Map(exclusions.map((e) => [e.path, e.reason]));
    const known    = new Set(routes.map((r) => r.path));

    /** @param {string} key @param {'routes'|'features'} field */
    const scenariosOf = (key, field) => scenarios.filter((s) => s[field].includes(key)).map(scenarioName);

    const covered   = [];
    const uncovered = [];
    for (const route of routes) {
        if (excluded.has(route.path)) {
            continue;
        }
        const names = scenariosOf(route.path, 'routes');
        if (names.length > 0) {
            covered.push({ ...route, scenarios: names });
        } else {
            uncovered.push(route);
        }
    }

    const featureNames = [...new Set(scenarios.flatMap((s) => s.features))].sort();

    return {
        summary: { covered: covered.length, total: covered.length + uncovered.length },
        covered,
        uncovered,
        features: featureNames.map((feature) => ({ feature, scenarios: scenariosOf(feature, 'features') })),
        excluded: exclusions.map((e) => ({
            ...e,
            methods: (routes.find((r) => r.path === e.path) || { methods: [] }).methods,
            labelledBy: scenariosOf(e.path, 'routes'),
            known: known.has(e.path),
        })),
        unlabelled: scenarios.filter((s) => s.routes.length === 0 && s.features.length === 0).map(scenarioName),
        unknownRoutes: scenarios.flatMap((s) =>
            s.routes.filter((r) => !known.has(r)).map((route) => ({ route, scenario: scenarioName(s) }))),
    };
}

/**
 * @param {string[]} cells
 * @returns {string}
 */
const row = (cells) => `| ${cells.map((c) => c.replace(/\|/g, '\\|')).join(' | ')} |`;

/**
 * @param {string[]} head
 * @param {string[][]} rows
 * @returns {string[]}
 */
function table(head, rows) {
    if (rows.length === 0) {
        return ['_none_', ''];
    }

    return [row(head), row(head.map(() => '---')), ...rows.map(row), ''];
}

/**
 * @param {ReturnType<typeof buildMatrix>} m
 * @returns {string}
 */
function renderMarkdown(m) {
    const lines = [
        '# Coverage matrix',
        '',
        `Covered paths: ${m.summary.covered} of ${m.summary.total} (informational, no threshold).`,
        '',
        '## Covered paths', '',
        ...table(['Path', 'Methods', 'Scenarios'], m.covered.map((r) => [r.path, r.methods.join(', '), r.scenarios.join('<br>')])),
        '## Uncovered paths', '',
        ...table(['Path', 'Methods'], m.uncovered.map((r) => [r.path, r.methods.join(', ')])),
        '## Features', '',
        ...table(['Feature', 'Scenarios'], m.features.map((f) => [f.feature, f.scenarios.join('<br>')])),
        '## Excluded paths', '',
        ...table(['Path', 'Reason', 'Labelled by'], m.excluded.map((e) => [e.path, e.reason, e.labelledBy.join('<br>')])),
        '## Scenarios without labels', '',
        ...table(['Scenario'], m.unlabelled.map((s) => [s])),
        '## Route labels without a route', '',
        ...table(['Label', 'Scenario'], m.unknownRoutes.map((u) => [u.route, u.scenario])),
    ];

    return lines.join('\n');
}

module.exports = { buildMatrix, collectScenarios, groupRoutes, renderMarkdown, validateExclusions };
