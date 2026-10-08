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
 * `npm run e2e:session` — boots the app on the data fixture exactly as the scenarios do (prod
 * FrankenPHP + our Electron) and leaves it running, without running any scenario. Prints the
 * app URL and a CDP endpoint to attach to with
 * `chromium.connectOverCDP(endpoint)`; Ctrl+C (or SIGTERM) stops Electron and FrankenPHP and
 * removes the data copy. Native dialogs are not stubbed here: attach a stub from your own
 * session through scripts/e2e/dialogs.js, or avoid the buttons that open them.
 */

const { spawn } = require('child_process');
const path = require('path');

const { checkPrerequisites, relaunchUnderXvfb } = require('./prereq');
const { findFreePort } = require('../../native/supervisor/port');
const { disposeFixture } = require('../fixture');
const { prepareData } = require('./launch');
const { startServer } = require('./server');

checkPrerequisites();

async function main() {
    const data   = prepareData();
    const server = await startServer(data.dir, data.env);
    const cdpPort = await findFreePort(9222);

    const electron = spawn(require('electron'), [
        '--no-sandbox', '--disable-gpu', `--remote-debugging-port=${cdpPort}`,
        path.join(__dirname, 'main.js'),
    ], {
        env: { ...process.env, E2E_PORT: String(server.port), E2E_USER_DATA_DIR: data.dir },
        stdio: 'inherit',
    });

    let stopping = false;
    const shutdown = async (code) => {
        if (stopping) {
            return;
        }
        stopping = true;
        electron.kill('SIGTERM');
        await server.stop();
        data.cleanup();
        disposeFixture();
        process.exit(code);
    };

    electron.once('exit', () => shutdown(0));
    process.once('SIGINT', () => shutdown(130));
    process.once('SIGTERM', () => shutdown(143));

    console.log(`[e2e:session] app:  http://127.0.0.1:${server.port}`);
    console.log(`[e2e:session] CDP:  http://127.0.0.1:${cdpPort}`);
    console.log(`[e2e:session] data: ${data.dir}`);
    console.log('[e2e:session] running — Ctrl+C to stop');
}

if (!relaunchUnderXvfb()) {
    main().catch((err) => {
        console.error(`[e2e:session] ${err.message}`);
        process.exit(1);
    });
}
