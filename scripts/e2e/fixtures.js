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
 * Playwright Test fixtures: `app` and `page` of a freshly launched, isolated app per scenario.
 * Trace and a final screenshot are kept only for a failed scenario (no video: Electron's
 * recordVideo needs ffmpeg, i.e. a second download, and hangs without it); the verdict reporter
 * names the scenario.
 */

const base = require('@playwright/test');

const { launchApp } = require('./launch');
const { disposeFixture } = require('../fixture');

const test = base.test.extend({
    // The fixture template is built lazily inside the worker process (launchApp), so only the
    // worker can remove it; a restarted worker (after a failed scenario) cleans up its own copy.
    // eslint-disable-next-line no-empty-pattern
    _fixtureCleanup: [async ({}, use) => {
        await use();
        disposeFixture();
    }, { scope: 'worker', auto: true }],

    // `test.use({ sourcePlugin: true })` installs the offline "fill from source" plugin (plugins.js).
    sourcePlugin: [false, { option: true }],

    session: async ({ sourcePlugin }, use, testInfo) => {
        const session = await launchApp({ sourcePlugin });
        await session.app.context().tracing.start({ screenshots: true, snapshots: true, sources: false });

        try {
            await use(session);
        } finally {
            const failed = testInfo.status !== testInfo.expectedStatus;
            if (failed) {
                const tracePath = testInfo.outputPath('trace.zip');
                await session.app.context().tracing.stop({ path: tracePath }).catch(() => {});
                await testInfo.attach('trace', { path: tracePath, contentType: 'application/zip' }).catch(() => {});
                const shot = await session.page.screenshot({ timeout: 3000 }).catch(() => null);
                if (shot !== null) {
                    await testInfo.attach('screenshot', { body: shot, contentType: 'image/png' });
                }
                if (session.serverTail() !== '') {
                    await testInfo.attach('frankenphp-log', { body: session.serverTail(), contentType: 'text/plain' });
                }
            } else {
                await session.app.context().tracing.stop().catch(() => {});
            }
            await session.close();
        }
    },

    app:  async ({ session }, use) => use(session.app),
    page: async ({ session }, use) => use(session.page),
});

module.exports = { test, expect: base.expect };
