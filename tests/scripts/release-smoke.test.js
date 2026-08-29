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

const path = require('path');

const {
    run,
    parseArgs,
    parseListeners,
    checkListeners,
    checkResponses,
    pidFilePath,
    EXPECTATIONS,
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

describe('run', () => {
    test('refuses to run anywhere but Windows, with a reason', async () => {
        if (process.platform === 'win32') return;

        const result = await run({ appPath: 'C:\\nope.exe' });

        expect(result.ok).toBe(false);
        expect(result.exitCode).toBe(1);
        expect(result.message).toContain('только на Windows');
    });
});
