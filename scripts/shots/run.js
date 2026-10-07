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
 * `npm run shots` entry point. Boots a plain `php -S` server for app/public (see router.php for
 * why it needs its own router), waits for it to answer, then runs scripts/shots/capture.js
 * inside Electron under Xvfb to screenshot the app in both themes — and tears the server down
 * again regardless of outcome.
 *
 * Deliberately independent of native/: the real app entry (native/index.js) starts FrankenPHP,
 * Meilisearch, the plugin supervisor and the rest of the desktop app's lifecycle, none of which a
 * screenshot of the web UI needs. Reused from native/supervisor/ are only the two Electron-free
 * helpers (findFreePort, waitForHealth) — they have no dependency on the `electron` module.
 */

const { spawn, spawnSync, execFileSync } = require('child_process');
const fs   = require('fs');
const path = require('path');

const { findFreePort } = require('../../native/supervisor/port');
const { waitForHealth } = require('../../native/supervisor/healthcheck');
const {
    KILL_GRACE_MS, LastPageTracker, resolveTimeoutMs, formatTimeoutMessage,
} = require('./lifecycle');

const rootDir   = path.resolve(__dirname, '..', '..');
const appDir    = path.join(rootDir, 'app');
const outDir    = path.join(rootDir, 'shots');
const routerPhp = path.join(__dirname, 'router.php');
const captureJs = path.join(__dirname, 'capture.js');

const STARTUP_PORT = 8100;

/**
 * Ubuntu 24.04 package names (README documents the pre-24.04 names, without the `t64` suffix).
 * Listed here only for the error message below — the check itself is `ldd`-based, not a lookup
 * against this table, since the exact library-to-package mapping drifts across distro releases.
 */
const REQUIRED_PACKAGES = [
    'xvfb', 'libatk1.0-0t64', 'libatk-bridge2.0-0t64', 'libgtk-3-0t64', 'libgbm1', 'libnss3',
    'libasound2t64', 'libcups2t64', 'libdrm2', 'libxkbcommon0', 'libpango-1.0-0', 'libcairo2',
    'libatspi2.0-0t64', 'libxdamage1', 'libxrandr2', 'libxcomposite1', 'libxfixes3', 'libxshmfence1',
];

/**
 * @param {string} message
 * @returns {never}
 */
function fail(message) {
    console.error(`\n[shots] ${message}\n`);
    process.exit(1);
}

function checkPlatform() {
    if (process.platform !== 'linux') {
        fail('this command only runs on Linux — it renders the app under Xvfb, which is Linux-only.');
    }
}

/**
 * @param {string} binary
 */
function checkBinaryOnPath(binary) {
    if (spawnSync('which', [binary]).status !== 0) {
        fail(
            `\`${binary}\` was not found on PATH. Required system packages:\n  ` +
            `${REQUIRED_PACKAGES.join(', ')}\n(drop the "t64" suffix on distros other than Ubuntu 24.04) — see README.`,
        );
    }
}

function checkBuiltPrerequisites() {
    if (!fs.existsSync(path.join(appDir, 'vendor', 'autoload.php'))) {
        fail('app/vendor is missing — run `composer install` inside app/ first.');
    }
    if (!fs.existsSync(path.join(appDir, 'public', 'css', 'app.css'))) {
        fail('built frontend assets are missing — run `npm run assets` first.');
    }
    if (!fs.existsSync(path.join(appDir, 'public', 'js', 'main.js'))) {
        fail('built frontend assets are missing — run `npm run assets` first.');
    }
    if (!fs.existsSync(path.join(rootDir, 'node_modules', 'electron'))) {
        fail('Electron is missing — run `npm ci` first.');
    }
}

/**
 * `require('electron')` under plain Node (as opposed to inside the Electron runtime itself)
 * resolves to the path of the platform Electron binary — the same trick electron-builder and
 * similar tooling use to locate it.
 *
 * @returns {string}
 */
function electronBinaryPath() {
    return require('electron');
}

/**
 * A missing Chromium runtime library normally surfaces as Electron dying with a bare
 * `error while loading shared libraries: libX.so: cannot open shared object file`, which does
 * not name the apt package to install. `ldd` catches the same problem ahead of time and reports
 * every missing library at once.
 */
function checkElectronRuntimeLibs() {
    const binaryPath = electronBinaryPath();
    const ldd = spawnSync('ldd', [binaryPath], { encoding: 'utf8' });

    // A container without `ldd` itself is rare enough that failing the check silently (letting
    // Electron's own error surface later) is preferable to a hard requirement on `ldd`.
    if (ldd.error) return;

    const missing = (ldd.stdout || '')
        .split('\n')
        .filter((line) => line.includes('not found'))
        .map((line) => line.trim().split(' ')[0]);

    if (missing.length > 0) {
        fail(
            `Electron is missing shared libraries:\n  ${missing.join('\n  ')}\n\n` +
            `They come from the Chromium runtime packages listed in README:\n  ${REQUIRED_PACKAGES.join(', ')}\n` +
            '(drop the "t64" suffix on distros other than Ubuntu 24.04).',
        );
    }
}

/**
 * Brings the SQLite schema up to date the same way the packaged app does on every start (see
 * native/supervisor/migrations.js) — a freshly cloned repo has no `data/` directory at all.
 * Both commands are idempotent, so running them unconditionally is cheap once the schema is
 * already current.
 */
function bootstrapDatabase() {
    fs.mkdirSync(path.join(rootDir, 'data'), { recursive: true });

    const console_ = path.join(appDir, 'bin', 'console');
    try {
        execFileSync('php', [console_, 'doctrine:migrations:migrate', '--no-interaction'], { cwd: appDir, stdio: 'pipe' });
        execFileSync('php', [console_, 'messenger:setup-transports', '--no-interaction'], { cwd: appDir, stdio: 'pipe' });
    } catch (err) {
        fail(`database bootstrap failed:\n${(err.stderr || err.stdout || err.message).toString()}`);
    }
}

/**
 * The catalog page needing a real anime id (for the anime-card screenshot) is the one page whose
 * data is a manual step (see README) rather than something this script can set up itself. `null`
 * means the catalog is empty — capture.js skips that one screenshot in that case.
 *
 * @returns {string|null}
 */
function findFirstAnimeId() {
    const dbPath = path.join(rootDir, 'data', 'data.db');
    if (!fs.existsSync(dbPath)) return null;

    try {
        const output = execFileSync('php', [
            '-r',
            '$db = new PDO("sqlite:".$argv[1]); '
            + '$id = $db->query("SELECT id FROM anime ORDER BY id LIMIT 1")->fetchColumn(); '
            + 'echo $id === false ? "" : $id;',
            dbPath,
        ], { encoding: 'utf8' }).trim();

        return output === '' ? null : output;
    } catch {
        return null;
    }
}

/**
 * @param {number} port
 * @returns {import('child_process').ChildProcess & { tail: () => string }}
 */
function startPhpServer(port) {
    const child = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', path.join(appDir, 'public'), routerPhp], {
        cwd: rootDir,
        // A single-threaded built-in server cannot serve the browser's parallel CSS/JS requests
        // for one page load — see router.php's docblock and the issue this implements.
        env: { ...process.env, PHP_CLI_SERVER_WORKERS: '4' },
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    let output = '';
    child.stdout.on('data', (chunk) => { output += chunk; });
    child.stderr.on('data', (chunk) => { output += chunk; });
    child.tail = () => output.slice(-4000);

    return child;
}

/**
 * @param {number} port
 * @param {string|null} animeId
 * @returns {Promise<number>}
 */
function runCapture(port, animeId) {
    return new Promise((resolve) => {
        const timeoutMs = resolveTimeoutMs(process.env);
        const lastPage  = new LastPageTracker();

        // Own process group, so a timeout reaches Electron behind the xvfb-run shell wrapper.
        const child = spawn('xvfb-run', [
            '-a', 'node_modules/.bin/electron',
            '--no-sandbox', '--disable-gpu', '--disable-lcd-text',
            captureJs,
        ], {
            cwd: rootDir,
            detached: true,
            env: {
                ...process.env,
                SHOTS_PORT:     String(port),
                SHOTS_OUT_DIR:  outDir,
                SHOTS_ANIME_ID: animeId || '',
            },
            stdio: ['inherit', 'pipe', 'inherit'],
        });

        child.stdout.on('data', (chunk) => {
            lastPage.push(chunk);
            process.stdout.write(chunk);
        });

        const killGroup = (signal) => {
            try {
                process.kill(-child.pid, signal);
            } catch {
                // already gone
            }
        };

        let timedOut = false;
        let killTimer = null;
        const timer = setTimeout(() => {
            timedOut = true;
            console.error(`\n[shots] ${formatTimeoutMessage(timeoutMs, lastPage.describe())}`);
            // SIGTERM first: capture.js uses it to save the snapshot of the hung page.
            killGroup('SIGTERM');
            killTimer = setTimeout(() => killGroup('SIGKILL'), KILL_GRACE_MS);
        }, timeoutMs);

        child.on('exit', (code) => {
            clearTimeout(timer);
            if (killTimer !== null) clearTimeout(killTimer);
            // Whatever is left in the group (e.g. Xvfb) must not outlive the run.
            if (timedOut) killGroup('SIGKILL');
            if (code !== 0) {
                console.error(`[shots] последняя страница: ${lastPage.describe()}`);
            }
            resolve(timedOut ? 1 : (code === null ? 1 : code));
        });
    });
}

async function main() {
    checkPlatform();
    checkBuiltPrerequisites();
    checkBinaryOnPath('php');
    checkBinaryOnPath('xvfb-run');
    checkElectronRuntimeLibs();

    bootstrapDatabase();
    const animeId = findFirstAnimeId();

    const port = await findFreePort(STARTUP_PORT);
    const server = startPhpServer(port);

    let exitCode = 1;
    try {
        await waitForHealth(port);
        // Cleared only now that capturing really starts, so a run that dies earlier keeps the
        // previous screenshots; *.FAILED.* of this run are written after this point.
        fs.rmSync(outDir, { recursive: true, force: true });
        fs.mkdirSync(outDir, { recursive: true });
        exitCode = await runCapture(port, animeId);
    } catch (err) {
        console.error(`[shots] ${err.message}`);
        console.error(server.tail());
    } finally {
        server.kill('SIGTERM');
    }

    if (exitCode === 0) {
        console.log(`[shots] screenshots written to ${outDir}`);
    }
    process.exit(exitCode);
}

main();
