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
 * Boots one isolated app instance: a copy of the data fixture, the prod FrankenPHP server and
 * our own Electron (node_modules/electron — no second browser) driven by Playwright.
 *
 * Interaction goes through Playwright locators only. A scripted `el.click()` inside
 * evaluate()/executeJavaScript() reaches a button even under an overlay, with isTrusted=false,
 * and keeps the scenario green; locator.click() refuses to click what is covered. The ESLint rule
 * in eslint.config.js forbids the scripted form in scripts/e2e/.
 */

const path = require('path');
const { _electron: electron } = require('@playwright/test');

const { createIsolatedEnv, envForDir } = require('../fixture');
const { installSourcePlugin } = require('./plugins');
const { startServer } = require('./server');

const STARTUP_TIMEOUT_MS = 30000;
const mainJs = path.join(__dirname, 'main.js');

/**
 * Data-directory hook: E2E_DATA_DIR points the run at an existing environment directory (used
 * as is, never copied or removed); otherwise a fresh copy of the fixture is made.
 *
 * @returns {{ dir: string, env: Record<string, string>, cleanup: () => void }}
 */
function prepareData() {
    if (process.env.E2E_DATA_DIR) {
        const dir = path.resolve(process.env.E2E_DATA_DIR);
        return { dir, env: envForDir(dir), cleanup: () => {} };
    }

    return createIsolatedEnv();
}

/**
 * @param {{ actionTimeoutMs?: number }} [options]
 * @returns {Promise<{
 *   app: import('@playwright/test').ElectronApplication,
 *   page: import('@playwright/test').Page,
 *   port: number,
 *   dataDir: string,
 *   serverTail: () => string,
 *   close: () => Promise<void>,
 * }>}
 */
async function launchApp({ actionTimeoutMs = 5000, sourcePlugin = false } = {}) {
    const data = prepareData();
    let server = null;
    let app = null;

    const close = async () => {
        if (app !== null) {
            await app.close().catch(() => {});
        }
        if (server !== null) {
            await server.stop();
        }
        data.cleanup();
    };

    try {
        if (sourcePlugin) {
            installSourcePlugin(data.dir, data.env);
        }
        server = await startServer(data.dir, data.env);
        app = await electron.launch({
            executablePath: require('electron'),
            args: ['--no-sandbox', '--disable-gpu', mainJs],
            env: { ...process.env, E2E_PORT: String(server.port), E2E_USER_DATA_DIR: data.dir },
        });
        const page = await app.firstWindow();
        page.setDefaultTimeout(actionTimeoutMs);
        await page.waitForLoadState('domcontentloaded', { timeout: STARTUP_TIMEOUT_MS });

        return { app, page, port: server.port, dataDir: data.dir, serverTail: server.tail, close };
    } catch (err) {
        await close();
        throw err;
    }
}

module.exports = { launchApp, prepareData };
