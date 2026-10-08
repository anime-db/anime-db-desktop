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
 * Coverage matrix: which routes of the app are exercised by E2E scenarios.
 *
 *   npm run coverage-matrix            Markdown to stdout
 *   npm run coverage-matrix -- --json  the same sections as JSON
 *
 * Routes come from `bin/console debug:router`, labels from the Playwright scenario listing. Neither
 * needs the running app, Xvfb or the FrankenPHP binary. The exit code is 0 unless a source fails.
 */

const { execFileSync } = require('child_process');
const fs   = require('fs');
const os   = require('os');
const path = require('path');

const { envForDir } = require('../fixture');
const { buildMatrix, collectScenarios, groupRoutes, renderMarkdown, validateExclusions } = require('./matrix');

const rootDir = path.resolve(__dirname, '..', '..');
const appDir  = path.join(rootDir, 'app');

/**
 * Routes of the app, grouped by path. Runs the prod console in a fresh runtime directory (a stale
 * compiled container answers silently and wrongly) with an empty plugins directory (plugin routes
 * depend on the developer's machine).
 *
 * @returns {{ path: string, methods: string[] }[]}
 */
function loadRoutes() {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'animedb-routes-'));
    try {
        fs.mkdirSync(path.join(dir, 'plugins'));
        const env = { ...process.env, ...envForDir(dir), APP_ENV: 'prod', APP_RUNTIME_DIR: path.join(dir, 'var') };
        let out;
        try {
            out = execFileSync('php', [path.join(appDir, 'bin', 'console'), 'debug:router', '--format=json', '--no-interaction'], {
                cwd: appDir, env, stdio: 'pipe', maxBuffer: 64 * 1024 * 1024,
            });
        } catch (err) {
            throw new Error(`debug:router failed:\n${(err.stderr || err.stdout || err.message).toString()}`);
        }

        return groupRoutes(JSON.parse(out.toString()));
    } finally {
        fs.rmSync(dir, { recursive: true, force: true });
    }
}

/**
 * @returns {{ file: string, title: string, routes: string[], features: string[] }[]}
 */
function loadScenarios() {
    let out;
    try {
        out = execFileSync('npx', ['playwright', 'test', '-c', 'scripts/e2e/playwright.config.js', '--list', '--reporter=json'], {
            cwd: rootDir, stdio: 'pipe', maxBuffer: 64 * 1024 * 1024, shell: process.platform === 'win32',
        });
    } catch (err) {
        throw new Error(`playwright --list failed:\n${(err.stderr || err.stdout || err.message).toString()}`);
    }

    return collectScenarios(JSON.parse(out.toString()));
}

/**
 * @returns {{ path: string, reason: string }[]}
 */
function loadExclusions() {
    return validateExclusions(JSON.parse(fs.readFileSync(path.join(__dirname, 'exclusions.json'), 'utf8')));
}

function main(argv) {
    try {
        const matrix = buildMatrix(loadRoutes(), loadScenarios(), loadExclusions());
        process.stdout.write(argv.includes('--json') ? `${JSON.stringify(matrix, null, 2)}\n` : `${renderMarkdown(matrix)}\n`);
    } catch (err) {
        process.stderr.write(`coverage-matrix: ${err.message}\n`);
        process.exitCode = 1;
    }
}

if (require.main === module) {
    main(process.argv.slice(2));
}

module.exports = { loadExclusions, loadRoutes, loadScenarios };
