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
const os = require('os');
const path = require('path');

const {
    run,
    parseArgs,
    parseListeners,
    checkListeners,
    checkResponses,
    checkLicenseFiles,
    pidFilePath,
    EXPECTATIONS,
    REQUIRED_LICENSE_FILES,
} = require('../../scripts/release-smoke');

describe('parseArgs', () => {
    test('reads every supported flag', () => {
        expect(parseArgs(['--app', 'C:\\a.exe', '--user-data-dir', 'C:\\p', '--timeout', '5000', '--keep-user-data']))
            .toEqual({ appPath: 'C:\\a.exe', userDataDir: 'C:\\p', timeoutMs: 5000, keepUserData: true });
    });

    test('ignores unknown flags instead of failing', () => {
        expect(parseArgs(['--nope', 'x'])).toEqual({});
    });
});

describe('parseListeners', () => {
    test('reads the array shape PowerShell emits for several sockets', () => {
        const stdout = JSON.stringify([
            { LocalAddress: '127.0.0.1', LocalPort: 8123 },
            { LocalAddress: '127.0.0.1', LocalPort: 8001 },
        ]);

        expect(parseListeners(stdout)).toEqual([
            { address: '127.0.0.1', port: 8001 },
            { address: '127.0.0.1', port: 8123 },
        ]);
    });

    /**
     * ConvertTo-Json collapses a one-element array into a bare object. Handling only the array shape
     * would work on every healthy run and break exactly when the build opens a single socket — that
     * is, on the failure this gate exists to report.
     */
    test('reads the bare-object shape PowerShell emits for a single socket', () => {
        const stdout = JSON.stringify({ LocalAddress: '127.0.0.1', LocalPort: 8000 });

        expect(parseListeners(stdout)).toEqual([{ address: '127.0.0.1', port: 8000 }]);
    });

    test('treats empty output as no sockets rather than throwing', () => {
        expect(parseListeners('')).toEqual([]);
        expect(parseListeners('   \n')).toEqual([]);
    });
});

describe('checkListeners', () => {
    test('accepts exactly two loopback sockets', () => {
        expect(checkListeners([
            { address: '127.0.0.1', port: 8000 },
            { address: '127.0.0.1', port: 8001 },
        ])).toEqual([]);
    });

    test('reports a socket count other than two', () => {
        const problems = checkListeners([{ address: '127.0.0.1', port: 8000 }]);

        expect(problems).toHaveLength(1);
        expect(problems[0]).toContain('ровно два');
    });

    test('reports a socket bound beyond loopback', () => {
        const problems = checkListeners([
            { address: '0.0.0.0', port: 8000 },
            { address: '127.0.0.1', port: 8001 },
        ]);

        expect(problems).toHaveLength(1);
        expect(problems[0]).toContain('0.0.0.0:8000');
    });

    /**
     * The IPv6 wildcard is the reason this is an allowlist and not a blocklist on "0.0.0.0": a
     * listener on `::` is just as open, and a check phrased as "does not contain 0.0.0.0" would
     * call it fine.
     */
    test('reports the IPv6 wildcard too, not just 0.0.0.0', () => {
        const problems = checkListeners([
            { address: '::', port: 8000 },
            { address: '127.0.0.1', port: 8001 },
        ]);

        expect(problems).toHaveLength(1);
        expect(problems[0]).toContain('::');
    });
});

describe('checkResponses', () => {
    const ok = (port) => EXPECTATIONS.map((e) => ({ port, path: e.path, status: e.status }));

    test('accepts responses that meet every expectation', () => {
        expect(checkResponses(EXPECTATIONS, [...ok(8000), ...ok(8001)])).toEqual([]);
    });

    /**
     * The scenario of issue #533 verbatim: /health kept answering 200 out of a raw DBAL query while
     * every ORM-backed page was a 500. A gate that probed only /health would have been green
     * through the entire period the catalogue did not work.
     */
    test('reports an ORM page failing while /health still answers', () => {
        const responses = ok(8000).map((r) => (r.path === '/' ? { ...r, status: 500 } : r));

        const problems = checkResponses(EXPECTATIONS, responses);

        expect(problems).toHaveLength(1);
        expect(problems[0]).toContain('127.0.0.1:8000/');
        expect(problems[0]).toContain('получено 500');
    });

    /**
     * The scenario of issue #532: a host literal in the site address made Caddy answer the WS port
     * with its own `400 Client sent an HTTP request to an HTTPS server`, never reaching PHP.
     */
    test('reports the WS endpoint answering 400 instead of asking for an upgrade', () => {
        const responses = ok(8001).map((r) => (r.path === '/ws' ? { ...r, status: 400 } : r));

        const problems = checkResponses(EXPECTATIONS, responses);

        expect(problems).toHaveLength(1);
        expect(problems[0]).toContain('/ws');
        expect(problems[0]).toContain('426');
    });

    test('reports a probe that never got an answer', () => {
        const responses = ok(8000).map((r) => (r.path.startsWith('/anime') ? { ...r, status: null, error: 'нет ответа' } : r));

        const problems = checkResponses(EXPECTATIONS, responses);

        expect(problems).toHaveLength(1);
        expect(problems[0]).toContain('нет ответа');
    });
});

describe('EXPECTATIONS', () => {
    /**
     * Guards the point of the whole gate rather than its wiring: /health is not enough, because it
     * answers without the ORM. If someone trims this list back to a liveness ping, this test says so.
     */
    test('cover an ORM-backed page and the WS endpoint, not just /health', () => {
        // Список каталога дёргается с обязательным watch_status: голый /anime отвечает 400 по
        // замыслу (AnimeListRequestParser), и гейт, слащий невалидный запрос, объявил бы рабочую
        // сборку сломанной — что он и сделал на прогоне issue #552.
        const paths = EXPECTATIONS.map((e) => e.path);

        expect(paths).toContain('/health');
        expect(paths).toContain('/');
        expect(paths).toContain('/anime?watch_status=plan');
        expect(paths).toContain('/ws');
        expect(EXPECTATIONS.find((e) => e.path === '/ws').status).toBe(426);
    });
});

describe('pidFilePath', () => {
    /**
     * Mirrors native/supervisor/pid-tracker.js and native/paths.js. The layout is duplicated here on
     * purpose — this script is deliberately black-box and must not require anything from native/,
     * which is Electron-bound — so a test pins the duplication instead of a comment asking nicely.
     */
    test('points at <userData>/var/pids/frankenphp.pid', () => {
        expect(pidFilePath(path.join('C:', 'profile')))
            .toBe(path.join('C:', 'profile', 'var', 'pids', 'frankenphp.pid'));
    });
});

describe('checkLicenseFiles', () => {
    let treeDir;

    /** Builds a packaged tree containing exactly the files listed, then reports what's missing. */
    const treeWith = (relPaths) => {
        for (const rel of relPaths) {
            const abs = path.join(treeDir, rel);
            fs.mkdirSync(path.dirname(abs), { recursive: true });
            fs.writeFileSync(abs, 'x');
        }

        return checkLicenseFiles(treeDir);
    };

    beforeEach(() => {
        treeDir = fs.mkdtempSync(path.join(os.tmpdir(), 'anime-db-license-tree-'));
    });

    afterEach(() => {
        fs.rmSync(treeDir, { recursive: true, force: true });
    });

    test('accepts a tree that carries every required file', () => {
        expect(treeWith(REQUIRED_LICENSE_FILES)).toEqual([]);
    });

    test('reports every missing file by its exact path', () => {
        const problems = treeWith([]);

        expect(problems).toHaveLength(REQUIRED_LICENSE_FILES.length);
        for (const rel of REQUIRED_LICENSE_FILES) {
            expect(problems).toContainEqual(expect.stringContaining(rel));
        }
    });

    // The three groups reach the tree through three different mechanisms, each with its own way of
    // failing silently, so losing any one of them has to be caught individually rather than by a
    // single "the directory exists" check.
    test.each([
        ['our own GPLv3 text (extraFiles)',              'LICENSE.txt'],
        ['the third-party index (extraFiles)',           'THIRD-PARTY-LICENSES/README.md'],
        ['the PHP license from the upstream archive',    'resources/app/bin/licenses/frankenphp/license.txt'],
        ['the qbittorrent-nox bundle attribution',       'resources/app/bin/qbittorrent-nox/THIRD-PARTY-LICENSES/README.md'],
        ['the ffprobe bundle attribution',               'resources/app/bin/ffprobe/THIRD-PARTY-LICENSES/README.md'],
    ])('fails when the tree loses %s', (_label, dropped) => {
        const problems = treeWith(REQUIRED_LICENSE_FILES.filter((rel) => rel !== dropped));

        expect(problems).toEqual([expect.stringContaining(dropped)]);
    });
});

describe('third-party license index', () => {
    const licensesDir = path.join(__dirname, '..', '..', 'resources', 'third-party-licenses');

    /**
     * The index is the document a recipient actually reads, so a reference in it that resolves to
     * nothing is worse than a missing entry: it claims attribution that isn't shipped. Renaming a
     * text file without updating the table is the realistic way this breaks.
     */
    test('every file the index references exists', () => {
        const index = fs.readFileSync(path.join(licensesDir, 'README.md'), 'utf8');
        // Only backtick-quoted names WITHOUT a leading path: those are this directory's own files.
        // References carrying a path (`resources/app/bin/qbittorrent-nox/THIRD-PARTY-LICENSES/…`)
        // point into the bundles other components ship for themselves and are not ours to hold.
        const referenced = new Set(
            [...index.matchAll(/`(texts\/[\w.+-]+\.txt|[A-Za-z][\w-]*-NOTICE\.txt)`/g)].map(([, rel]) => rel),
        );

        expect(referenced.size).toBeGreaterThan(0);
        for (const rel of referenced) {
            expect({ rel, exists: fs.existsSync(path.join(licensesDir, rel)) })
                .toEqual({ rel, exists: true });
        }
    });

    /**
     * REQUIRED_LICENSE_FILES is a hand-written list, so the realistic failure is adding a notice
     * here and forgetting it there: the release gate would then happily pass a build shipping
     * without it. Tying the list to the directory makes that impossible to do silently.
     */
    test('the release gate requires every notice this directory ships', () => {
        const shipped = fs.readdirSync(licensesDir)
            .filter((name) => name.endsWith('-NOTICE.txt'))
            .map((name) => `THIRD-PARTY-LICENSES/${name}`);

        expect(shipped.length).toBeGreaterThan(0);
        expect(REQUIRED_LICENSE_FILES).toEqual(expect.arrayContaining(shipped));
    });

    test('every shipped license text is referenced by the index', () => {
        const index = fs.readFileSync(path.join(licensesDir, 'README.md'), 'utf8');
        const notices = fs.readdirSync(licensesDir).filter((name) => name.endsWith('-NOTICE.txt'));
        const texts = fs.readdirSync(path.join(licensesDir, 'texts'));

        expect(notices.length).toBeGreaterThan(0);
        expect(texts.length).toBeGreaterThan(0);

        // A text may be cited by the index itself or by one of the notices it points at — both
        // count as reachable for a reader starting from the index.
        const reachable = index + notices.map((name) => fs.readFileSync(path.join(licensesDir, name), 'utf8')).join('');

        for (const name of [...notices, ...texts]) {
            expect({ name, cited: reachable.includes(name) }).toEqual({ name, cited: true });
        }
    });
});

describe('run', () => {
    test('refuses to run anywhere but Windows, with a reason', async () => {
        if (process.platform === 'win32') return;

        const result = await run({ appPath: 'C:\\nope.exe' });

        expect(result.ok).toBe(false);
        expect(result.exitCode).toBe(1);
        expect(result.message).toContain('только на Windows');
    });
});
