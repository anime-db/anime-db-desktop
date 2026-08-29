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

const fs                = require('fs');
const path              = require('path');
const { execFileSync }  = require('child_process');

const CADDYFILE = path.join(__dirname, '..', '..', 'app', 'Caddyfile');
const RUNTIME_DIR = path.join(__dirname, '..', '..', 'bin', 'frankenphp');

/**
 * Splits Caddyfile text into its top-level blocks (the global options block plus every site
 * block), tracking brace depth so nested blocks such as `php_server { ... }` don't get mistaken
 * for a second top-level block. `#`-comments are stripped first since they may themselves contain
 * braces in prose (see the WS block comment in app/Caddyfile).
 *
 * @param {string} text
 * @returns {Array<{ header: string, body: string }>} `header` is '' for the global block.
 */
function parseTopLevelBlocks(text) {
    const lines = text.split('\n').map((line) => line.replace(/#.*/, ''));
    const blocks = [];
    let depth   = 0;
    let current = null;

    for (const line of lines) {
        if (depth === 0) {
            const trimmed = line.trim();
            if (trimmed.endsWith('{')) {
                current = { header: trimmed.slice(0, -1).trim(), bodyLines: [] };
                depth  += (line.match(/{/g) || []).length - (line.match(/}/g) || []).length;
            }
            continue;
        }

        depth += (line.match(/{/g) || []).length - (line.match(/}/g) || []).length;
        if (depth === 0) {
            blocks.push({ header: current.header, body: current.bodyLines.join('\n') });
            current = null;
        } else {
            current.bodyLines.push(line);
        }
    }

    return blocks;
}

/**
 * @param {string} text raw Caddyfile content
 * @returns {{
 *   hasAdminOff: boolean,
 *   siteBlocks: Array<{
 *     header: string,
 *     usesEnvBraces: boolean,
 *     isHostLiteral: boolean,
 *     hasLocalBind: boolean,
 *     hasRootDirective: boolean,
 *   }>,
 * }}
 */
function analyzeCaddyfile(text) {
    const blocks      = parseTopLevelBlocks(text);
    const globalBlock  = blocks.find((block) => block.header === '');
    const siteBlocks    = blocks.filter((block) => block.header !== '');

    return {
        hasAdminOff: globalBlock !== undefined && /(^|\n)\s*admin off\s*($|\n)/.test(globalBlock.body),
        siteBlocks:  siteBlocks.map((block) => ({
            header:           block.header,
            usesEnvBraces:    block.header.includes('{env.'),
            // A bare-port address (`:8080`) has no listener host; anything else is a host literal
            // (e.g. `127.0.0.1:8080`), which makes Caddy set up a TLS listener even with
            // auto_https off — see the comment above the WS block in app/Caddyfile.
            isHostLiteral:    !block.header.startsWith(':'),
            hasLocalBind:     /(^|\n)\s*bind 127\.0\.0\.1\s*($|\n)/.test(block.body),
            hasRootDirective: /(^|\n)\s*root \*/.test(block.body),
        })),
    };
}

describe('app/Caddyfile — static structure (level 1, no server, no network, no binary)', () => {
    const text     = fs.readFileSync(CADDYFILE, 'utf8');
    const analysis = analyzeCaddyfile(text);

    /**
     * Ровно два, а не «хотя бы один». Остальные проверки этого уровня сформулированы как свойство
     * КАЖДОГО блока, поэтому удаление целого блока проходит их все: удалять — значит нечего
     * проверять. Между тем WS-блок несущий, в него ходит native/ws-client.js, и без него отваливается
     * шина push-событий бэкенд → Electron.
     *
     * Закрыть эту дыру должен именно уровень 1: уровень 2 поймал бы пропажу через
     * expectedListeners, но он условный и в CI пропускается — бинаря FrankenPHP на ubuntu-раннере
     * нет. Гейт релиза (issue #535) поймал бы тоже, но только на теге. Утверждение зеркалит его
     * правило «ровно два слушающих сокета, APP и WS».
     */
    test('declares exactly two site blocks — the app port and the WS port', () => {
        expect(analysis.siteBlocks).toHaveLength(2);
    });

    test('the global block has admin off', () => {
        expect(analysis.hasAdminOff).toBe(true);
    });

    test('no site address uses the runtime {env.*} form', () => {
        for (const site of analysis.siteBlocks) {
            expect(site.usesEnvBraces).toBe(false);
        }
    });

    test('no site address is a host literal', () => {
        for (const site of analysis.siteBlocks) {
            expect(site.isHostLiteral).toBe(false);
        }
    });

    test('every site block binds to 127.0.0.1', () => {
        for (const site of analysis.siteBlocks) {
            expect(site.hasLocalBind).toBe(true);
        }
    });

    test('every site block declares root *', () => {
        for (const site of analysis.siteBlocks) {
            expect(site.hasRootDirective).toBe(true);
        }
    });
});

describe('analyzeCaddyfile — regression fixtures (each catches one previously-shipped defect)', () => {
    const GOOD_SITE = [
        ':{$APP_PORT} {',
        '    bind 127.0.0.1',
        '    root * {env.APP_ROOT}/public',
        '    php_server {',
        '        worker ./public/index.php',
        '    }',
        '}',
    ].join('\n');

    const GOOD_GLOBAL = ['{', '    frankenphp', '    auto_https off', '    admin off', '}'].join('\n');

    test('flags an address using {env.X} instead of {$X}', () => {
        const broken = GOOD_SITE.replace(':{$APP_PORT}', '{env.APP_PORT}:80');
        const [site] = analyzeCaddyfile(`${GOOD_GLOBAL}\n\n${broken}`).siteBlocks;
        expect(site.usesEnvBraces).toBe(true);
    });

    test('flags a host literal in the site address', () => {
        const broken = GOOD_SITE.replace(':{$APP_PORT}', '127.0.0.1:{$APP_PORT}');
        const [site] = analyzeCaddyfile(`${GOOD_GLOBAL}\n\n${broken}`).siteBlocks;
        expect(site.isHostLiteral).toBe(true);
    });

    test('flags a site block missing bind 127.0.0.1', () => {
        const broken = GOOD_SITE.replace('    bind 127.0.0.1\n', '');
        const [site] = analyzeCaddyfile(`${GOOD_GLOBAL}\n\n${broken}`).siteBlocks;
        expect(site.hasLocalBind).toBe(false);
    });

    test('flags a site block missing root *', () => {
        const broken = GOOD_SITE.replace('    root * {env.APP_ROOT}/public\n', '');
        const [site] = analyzeCaddyfile(`${GOOD_GLOBAL}\n\n${broken}`).siteBlocks;
        expect(site.hasRootDirective).toBe(false);
    });

    test('flags a global block missing admin off', () => {
        const broken = GOOD_GLOBAL.replace('    admin off\n', '');
        const analysis = analyzeCaddyfile(`${broken}\n\n${GOOD_SITE}`);
        expect(analysis.hasAdminOff).toBe(false);
    });
});

/**
 * @returns {string|null} absolute path to the FrankenPHP binary in bin/frankenphp/, or null when
 *                         the runtime hasn't been downloaded (this project builds Windows-only, so
 *                         a locally-run `npm test` will not normally have it).
 */
function findFrankenphpBinary() {
    if (!fs.existsSync(RUNTIME_DIR)) return null;

    const match = fs.readdirSync(RUNTIME_DIR).find((name) => /^frankenphp(\.exe)?$/i.test(name));

    return match ? path.join(RUNTIME_DIR, match) : null;
}

const BINARY = findFrankenphpBinary();
const skipReason = `FrankenPHP binary not found in ${RUNTIME_DIR} (run "npm run download-bins" to enable this check)`;
const maybeTest  = BINARY === null ? test.skip : test;

describe('app/Caddyfile — validate/adapt via FrankenPHP (level 2, conditional on a local binary)', () => {
    const adaptEnv = { ...process.env, APP_PORT: '18501', WS_PORT: '18502' };

    maybeTest(BINARY === null ? `validate --config reports no errors (skipped: ${skipReason})` : 'validate --config reports no errors', () => {
        execFileSync(BINARY, ['validate', '--config', CADDYFILE], { env: adaptEnv, stdio: 'pipe' });
    });

    maybeTest(BINARY === null ? `adapted config matches the expected security properties (skipped: ${skipReason})` : 'adapted config matches the expected security properties', () => {
        const output   = execFileSync(BINARY, ['adapt', '--config', CADDYFILE], { env: adaptEnv, stdio: 'pipe' });
        const adapted  = JSON.parse(output.toString('utf8'));
        const servers  = Object.values(adapted.apps.http.servers);

        expect(adapted.admin.disabled).toBe(true);
        expect(servers.length).toBeGreaterThan(0);

        const expectedListeners = ['127.0.0.1:18501', '127.0.0.1:18502'];
        const actualListeners   = servers.flatMap((server) => server.listen);
        expect(actualListeners.sort()).toEqual([...expectedListeners].sort());

        for (const server of servers) {
            expect(server.listen).toHaveLength(1);
            expect(server.tls_connection_policies).toBeUndefined();
            expect(findVarsRootHandler(server)).toBe(true);
        }
    });
});

/**
 * Recursively searches an adapted server's JSON tree for a `vars` handler exposing `root`,
 * without assuming the exact route/subroute nesting Caddy produces for a given Caddyfile version.
 *
 * @param {unknown} node
 * @returns {boolean}
 */
function findVarsRootHandler(node) {
    if (node === null || typeof node !== 'object') return false;

    if (node.handler === 'vars' && Object.prototype.hasOwnProperty.call(node, 'root')) {
        return true;
    }

    return Object.values(node).some((value) => findVarsRootHandler(value));
}
