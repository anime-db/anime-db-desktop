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

const fs = require('fs');
const path = require('path');
const {run} = require('../../scripts/security-audit');

const scriptPath = path.resolve(__dirname, '../../scripts/security-audit.js');

const BASE_SHIPPED = ['electron', 'htmx.org', 'bootstrap'];
const TODAY = '2026-11-01';

function baseline(accepted = [], shipped = BASE_SHIPPED) {
    return {accepted, shipped_dev: shipped};
}

function accept(id, pkg, expires = '2026-12-28') {
    return {id, package: pkg, reason: 'test', expires};
}

function npmReport(pkg, id, nodes, severity = 'high') {
    return JSON.stringify({
        vulnerabilities: {
            [pkg]: {
                name: pkg,
                severity,
                via: [{source: 1, name: pkg, title: 'Bad thing', url: `https://github.com/advisories/${id}`, severity}],
                nodes,
            },
        },
    });
}

const NPM_CLEAN = JSON.stringify({vulnerabilities: {}});
const COMPOSER_CLEAN = JSON.stringify({advisories: []});

function lock(entries) {
    return {packages: entries};
}

function exec({npm = NPM_CLEAN, composer = COMPOSER_CLEAN, bl = baseline(), lockPackages = {}} = {}) {
    const lines = [];
    const files = {
        'security-audit-baseline.json': JSON.stringify(bl),
        'package-lock.json':            JSON.stringify(lock(lockPackages)),
    };
    const code = run({
        runners:  {npm: () => npm, composer: () => composer},
        readFile: (p) => files[path.basename(p)],
        log:      (l) => lines.push(l),
        today:    TODAY,
    });
    return {code, out: lines.join('\n')};
}

describe('security-audit', () => {
    test('clean reports pass', () => {
        expect(exec().code).toBe(0);
    });

    test('new finding blocks with exit code 1', () => {
        const r = exec({npm: npmReport('foo', 'GHSA-new', ['node_modules/foo']), lockPackages: {'node_modules/foo': {}}});
        expect(r.code).toBe(1);
        expect(r.out).toContain('GHSA-new');
    });

    test.each(['low', 'moderate'])('%s findings are ignored', (severity) => {
        const r = exec({npm: npmReport('foo', 'GHSA-mod', ['node_modules/foo'], severity), lockPackages: {'node_modules/foo': {}}});
        expect(r.code).toBe(0);
    });

    test('critical finding blocks with exit code 1', () => {
        const r = exec({npm: npmReport('foo', 'GHSA-crit', ['node_modules/foo'], 'critical'), lockPackages: {'node_modules/foo': {}}});
        expect(r.code).toBe(1);
        expect(r.out).toContain('GHSA-crit');
    });

    test('finding from baseline passes', () => {
        const r = exec({
            npm:          npmReport('foo', 'GHSA-old', ['node_modules/foo']),
            lockPackages: {'node_modules/foo': {}},
            bl:           baseline([accept('GHSA-old', 'foo')]),
        });
        expect(r.code).toBe(0);
        expect(r.out).toContain('Accepted risks');
    });

    test('finding from baseline with expired expires blocks', () => {
        const r = exec({
            npm:          npmReport('foo', 'GHSA-old', ['node_modules/foo']),
            lockPackages: {'node_modules/foo': {}},
            bl:           baseline([accept('GHSA-old', 'foo', '2026-10-31')]),
        });
        expect(r.code).toBe(1);
        expect(r.out).toContain('expired');
    });

    test('baseline entry is valid through its expires day', () => {
        const r = exec({
            npm:          npmReport('foo', 'GHSA-old', ['node_modules/foo']),
            lockPackages: {'node_modules/foo': {}},
            bl:           baseline([accept('GHSA-old', 'foo', TODAY)]),
        });
        expect(r.code).toBe(0);
    });

    test('finding with only dev nodes goes to the build chain section', () => {
        const r = exec({npm: npmReport('foo', 'GHSA-dev', ['node_modules/foo']), lockPackages: {'node_modules/foo': {dev: true}}});
        expect(r.code).toBe(0);
        expect(r.out).toMatch(/Build chain[\s\S]*GHSA-dev/);
        expect(r.out).not.toContain('Blocking');
    });

    test('finding with one dev node and one non-dev node blocks', () => {
        const r = exec({
            npm:          npmReport('foo', 'GHSA-mix', ['node_modules/foo', 'node_modules/a/node_modules/foo']),
            lockPackages: {'node_modules/foo': {dev: true}, 'node_modules/a/node_modules/foo': {}},
        });
        expect(r.code).toBe(1);
    });

    test('nodes are matched by path, not by package name', () => {
        const r = exec({
            npm:          npmReport('foo', 'GHSA-path', ['node_modules/foo']),
            lockPackages: {'node_modules/foo': {dev: true}, 'node_modules/a/node_modules/foo': {}},
        });
        expect(r.code).toBe(0);
    });

    test('finding in a shipped_dev package blocks even if the node is dev', () => {
        const r = exec({npm: npmReport('electron', 'GHSA-el', ['node_modules/electron']), lockPackages: {'node_modules/electron': {dev: true}}});
        expect(r.code).toBe(1);
    });

    test.each(BASE_SHIPPED)('shipped_dev without %s is a config error (2)', (missing) => {
        const r = exec({bl: baseline([], BASE_SHIPPED.filter((p) => p !== missing))});
        expect(r.code).toBe(2);
        expect(r.out).toContain(missing);
    });

    test.each([
        ['bad date', [{id: 'GHSA-x', package: 'foo', reason: 'r', expires: 'soon'}]],
        ['missing reason', [{id: 'GHSA-x', package: 'foo', expires: '2026-12-28'}]],
    ])('invalid baseline entry (%s) is a config error (2)', (name, accepted) => {
        expect(exec({bl: baseline(accepted)}).code).toBe(2);
    });

    test('npm error response without vulnerabilities key exits 3', () => {
        const r = exec({npm: JSON.stringify({error: {code: 'ENOAUDIT', summary: 'registry down'}})});
        expect(r.code).toBe(3);
        expect(r.out).toContain('npm');
        expect(r.out).toContain('ENOAUDIT');
    });

    test('empty npm output exits 3', () => {
        expect(exec({npm: ''}).code).toBe(3);
    });

    test('invalid composer JSON exits 3 and shows the start of the raw output', () => {
        const r = exec({composer: 'Composer could not detect the root package'});
        expect(r.code).toBe(3);
        expect(r.out).toContain('composer');
        expect(r.out).toContain('Composer could not detect');
    });

    test('raw output excerpt is truncated', () => {
        const r = exec({composer: 'x'.repeat(2000)});
        expect(r.out).toContain('x'.repeat(500));
        expect(r.out).not.toContain('x'.repeat(501));
    });

    test('composer JSON without advisories exits 3', () => {
        expect(exec({composer: JSON.stringify({abandoned: []})}).code).toBe(3);
    });

    test('runner that cannot start exits 3', () => {
        const {AuditFailure} = require('../../scripts/security-audit');
        const code = run({
            runners:  {npm: () => { throw new AuditFailure('npm', 'failed to run npm: ENOENT', ''); }, composer: () => COMPOSER_CLEAN},
            readFile: (p) => (path.basename(p) === 'package-lock.json' ? '{}' : JSON.stringify(baseline())),
            log:      () => {},
            today:    TODAY,
        });
        expect(code).toBe(3);
    });

    test('composer advisory not in baseline blocks', () => {
        const composer = JSON.stringify({advisories: {'vendor/pkg': [{advisoryId: 'PKSA-1', packageName: 'vendor/pkg', cve: 'CVE-2026-1', title: 'T', severity: 'high'}]}});
        const r = exec({composer});
        expect(r.code).toBe(1);
        expect(r.out).toContain('CVE-2026-1');
    });

    function composerAdv(overrides) {
        return JSON.stringify({advisories: {'v/p': [{advisoryId: 'PKSA-2', packageName: 'v/p', cve: null, title: 'T', ...overrides}]}});
    }

    test.each([[null], [undefined]])('composer advisory with severity %s is kept as unknown and blocks', (severity) => {
        const r = exec({composer: composerAdv({severity})});
        expect(r.code).toBe(1);
        expect(r.out).toContain('PKSA-2');
        expect(r.out).toContain('unknown');
    });

    test.each(['low', 'medium'])('composer advisory with %s severity is ignored', (severity) => {
        expect(exec({composer: composerAdv({severity})}).code).toBe(0);
    });

    test('composer severity is case-insensitive', () => {
        expect(exec({composer: composerAdv({severity: 'HIGH'})}).code).toBe(1);
    });

    test('composer finding is accepted via baseline by advisoryId when cve is null', () => {
        const r = exec({composer: composerAdv({severity: 'high'}), bl: baseline([accept('PKSA-2', 'v/p')])});
        expect(r.code).toBe(0);
        expect(r.out).toContain('Accepted risks');
    });

    test('composer advisory entry that is not an object exits 3', () => {
        const r = exec({composer: JSON.stringify({advisories: {'v/p': [null]}})});
        expect(r.code).toBe(3);
        expect(r.out).toContain('composer');
    });

    test('new finding plus failed second audit gives the highest code (3)', () => {
        const r = exec({npm: npmReport('foo', 'GHSA-new', ['node_modules/foo']), lockPackages: {'node_modules/foo': {}}, composer: 'not json'});
        expect(r.code).toBe(3);
    });

    test('config error plus new finding gives 2', () => {
        const r = exec({
            npm:          npmReport('foo', 'GHSA-new', ['node_modules/foo']),
            lockPackages: {'node_modules/foo': {}},
            bl:           baseline([], ['electron']),
        });
        expect(r.code).toBe(2);
    });

    test('script requires only Node built-in modules', () => {
        const {builtinModules} = require('module');
        const source = fs.readFileSync(scriptPath, 'utf8');
        const specs = [...source.matchAll(/require\(\s*['"]([^'"]+)['"]\s*\)/g)].map((m) => m[1])
            .concat([...source.matchAll(/^\s*import\s[^;]*?from\s+['"]([^'"]+)['"]/gm)].map((m) => m[1]));
        expect(specs.length).toBeGreaterThan(0);
        for (const spec of specs) {
            expect(spec.startsWith('node:')).toBe(true);
            expect(builtinModules).toContain(spec.slice(5));
        }
    });

    test('starting baseline satisfies the validation and accepts only electron', () => {
        const bl = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../security-audit-baseline.json'), 'utf8'));
        expect(exec({bl}).code).toBe(0);
        expect(new Set(bl.accepted.map((a) => a.package))).toEqual(new Set(['electron']));
    });
});
