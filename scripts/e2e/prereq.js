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
 * Shared start-up of `npm run e2e` and `npm run e2e:session`: prerequisite checks, and re-running
 * the command under Xvfb when there is no display (Electron needs one).
 */

const { spawn, spawnSync } = require('child_process');
const fs   = require('fs');
const path = require('path');

const { frankenphpBinary } = require('./server');

const rootDir = path.resolve(__dirname, '..', '..');
const appDir  = path.join(rootDir, 'app');

/**
 * @param {string} message
 * @returns {never}
 */
function fail(message) {
    console.error(`\n[e2e] ${message}\n`);
    process.exit(1);
}

/**
 * @returns {string}
 */
function playwrightBinary() {
    return path.join(rootDir, 'node_modules', '.bin', 'playwright');
}

/**
 * Exit code for a finished `spawnSync` result; a failure to start the process is reported, not swallowed.
 *
 * @param {import('child_process').SpawnSyncReturns<Buffer>} result
 * @returns {number}
 */
function exitCodeOf(result) {
    if (result.error) {
        console.error(`\n[e2e] failed to run the command: ${result.error.message}\n`);
    }

    return result.status === null ? 1 : result.status;
}

function checkPrerequisites() {
    if (process.platform !== 'linux') {
        fail('this command only runs on Linux (Linux FrankenPHP build, Xvfb).');
    }
    if (!fs.existsSync(path.join(appDir, 'vendor', 'autoload.php'))) {
        fail('app/vendor is missing — run `composer install` inside app/ first.');
    }
    if (!fs.existsSync(path.join(appDir, 'public', 'js', 'main.js'))
        || !fs.existsSync(path.join(appDir, 'public', 'css', 'app.css'))) {
        fail('built frontend assets are missing — run `npm run assets` first.');
    }
    if (!fs.existsSync(path.join(rootDir, 'node_modules', 'electron'))) {
        fail('Electron is missing — run `npm ci` first.');
    }
    if (!fs.existsSync(path.join(rootDir, 'node_modules', '@playwright', 'test'))
        || !fs.existsSync(playwrightBinary())) {
        fail('Playwright отсутствует — выполни `npm ci`.');
    }
    if (!fs.existsSync(frankenphpBinary())) {
        fail(`Linux FrankenPHP binary not found at ${frankenphpBinary()} — run \`npm run download-e2e-runtime\` or set E2E_FRANKENPHP_BIN.`);
    }
    if (spawnSync('which', ['php']).status !== 0) {
        fail('`php` was not found on PATH (needed for the fixture and the cache warm-up).');
    }
}

/**
 * Without a display, re-runs this very command under `xvfb-run` and returns true — the caller must
 * then do nothing: the process exits with the child's code. With a display, returns false.
 *
 * @returns {boolean}
 */
function relaunchUnderXvfb() {
    if (process.env.DISPLAY) {
        return false;
    }
    if (spawnSync('which', ['xvfb-run']).status !== 0) {
        fail('no DISPLAY and `xvfb-run` was not found on PATH (package `xvfb`).');
    }
    const child = spawn('xvfb-run', ['-a', process.execPath, ...process.argv.slice(1)], { stdio: 'inherit', detached: true });
    // Own process group, signalled as a whole: xvfb-run (a shell script) does not pass a signal on
    // to its command, and stopping this one must stop the live session too.
    for (const signal of ['SIGINT', 'SIGTERM']) {
        process.on(signal, () => process.kill(-child.pid, signal));
    }
    child.on('exit', (code, signal) => process.exit(code === null ? 128 + (signal === 'SIGINT' ? 2 : 15) : code));

    return true;
}

module.exports = { checkPrerequisites, playwrightBinary, exitCodeOf, relaunchUnderXvfb, fail, rootDir };
