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
 * Starts the app the way production does: the real FrankenPHP binary with app/Caddyfile (the
 * front controller runs as a worker, routing is Caddy's php_server), APP_ENV=prod, and a warmed
 * Symfony cache. Deliberately not `php -S` + scripts/shots/router.php, which `npm run shots` uses.
 */

const { spawn, execFileSync } = require('child_process');
const fs   = require('fs');
const path = require('path');

const { findFreePort }  = require('../../native/supervisor/port');
const { waitForHealth } = require('../../native/supervisor/healthcheck');

const rootDir   = path.resolve(__dirname, '..', '..');
const appDir    = path.join(rootDir, 'app');
const caddyfile = path.join(appDir, 'Caddyfile');

const DEFAULT_START_PORT = 8200;

/**
 * Linux FrankenPHP binary: E2E_FRANKENPHP_BIN, else bin/frankenphp/frankenphp. The version is the
 * one pinned in scripts/versions.json (the Windows bundle's frankenphp.exe is not runnable here).
 *
 * @returns {string}
 */
function frankenphpBinary() {
    return process.env.E2E_FRANKENPHP_BIN || path.join(rootDir, 'bin', 'frankenphp', 'frankenphp');
}

/**
 * Environment of every PHP process of the run: prod, with all user data inside the environment
 * directory (including the Symfony runtime dir, so app/var is never touched).
 *
 * @param {string} dataDir  environment directory
 * @param {Record<string, string>} dataEnv  user-data variables (see scripts/fixture envForDir)
 * @returns {NodeJS.ProcessEnv}
 */
function buildPhpEnv(dataDir, dataEnv) {
    return {
        ...process.env,
        ...dataEnv,
        APP_ROOT:                appDir,
        APP_ENV:                 'prod',
        APP_DEBUG:               '0',
        APP_SECRET:              'e2e-app-secret',
        APP_RUNTIME_DIR:         path.join(dataDir, 'var'),
        MESSENGER_TRANSPORT_DSN: 'doctrine://queue?auto_setup=0',
        // The run starts neither Meilisearch nor qBittorrent; the URLs only keep the container
        // compilable and make calls to them fail fast.
        MEILISEARCH_URL:         'http://127.0.0.1:1',
        MEILISEARCH_KEY:         'e2e',
        QBITTORRENT_URL:         'http://127.0.0.1:1',
    };
}

/**
 * Compiles the prod container and caches before the server starts, so the first request of a
 * scenario does not pay for it (and a broken container fails here, with a readable message).
 *
 * @param {NodeJS.ProcessEnv} env
 */
function warmCache(env) {
    try {
        execFileSync('php', [path.join(appDir, 'bin', 'console'), 'cache:warmup', '--env=prod', '--no-interaction'], {
            cwd: appDir, env, stdio: 'pipe',
        });
    } catch (err) {
        throw new Error(`cache:warmup failed:\n${(err.stderr || err.stdout || err.message).toString()}`);
    }
}

/**
 * @param {string} dataDir
 * @param {Record<string, string>} dataEnv
 * @returns {Promise<{ port: number, stop: () => Promise<void>, tail: () => string }>}
 */
async function startServer(dataDir, dataEnv) {
    const binary = frankenphpBinary();
    if (!fs.existsSync(binary)) {
        throw new Error(`FrankenPHP binary not found at ${binary} — see "E2E" in README.`);
    }

    const env = buildPhpEnv(dataDir, dataEnv);
    fs.mkdirSync(env.APP_RUNTIME_DIR, { recursive: true });
    warmCache(env);

    const port   = await findFreePort(DEFAULT_START_PORT);
    const wsPort = await findFreePort(port + 1);

    const child = spawn(binary, ['run', '--config', caddyfile], {
        cwd: appDir,
        env: { ...env, APP_PORT: String(port), WS_PORT: String(wsPort) },
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    let output = '';
    child.stdout.on('data', (chunk) => { output += chunk; });
    child.stderr.on('data', (chunk) => { output += chunk; });
    const tail = () => output.slice(-4000);

    let exited = false;
    child.once('exit', () => { exited = true; });

    const stop = () => new Promise((resolve) => {
        if (exited) {
            resolve();
            return;
        }
        const timer = setTimeout(() => child.kill('SIGKILL'), 2000);
        child.once('exit', () => { clearTimeout(timer); resolve(); });
        child.kill('SIGTERM');
    });

    try {
        await Promise.race([
            waitForHealth(port),
            new Promise((_, reject) => child.once('exit', (code) => reject(new Error(`FrankenPHP exited with code ${code}`)))),
        ]);
    } catch (err) {
        await stop();
        throw new Error(`${err.message}\n${tail()}`);
    }

    return { port, stop, tail };
}

module.exports = { startServer, buildPhpEnv, frankenphpBinary };
