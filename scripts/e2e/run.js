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
 * `npm run e2e [-- <playwright test args>]` — runs the scenarios in scripts/e2e/scenarios/.
 * Exit code is Playwright's: non-zero when any scenario fails or times out; the verdict reporter
 * names them and the trace/video of a failed one stays in e2e-results/.
 */

const { spawnSync } = require('child_process');
const fs   = require('fs');
const path = require('path');

const { snapshotGuardedPaths, findLeakedPaths } = require('../leak-guard');
const { checkPrerequisites, relaunchUnderXvfb, playwrightBinary, exitCodeOf, rootDir } = require('./prereq');

checkPrerequisites();

if (!relaunchUnderXvfb()) {
    fs.rmSync(path.join(rootDir, 'e2e-results'), { recursive: true, force: true });

    const guardedBefore = snapshotGuardedPaths();

    const result = spawnSync(
        playwrightBinary(),
        ['test', '-c', path.join(__dirname, 'playwright.config.js'), ...process.argv.slice(2)],
        { cwd: rootDir, stdio: 'inherit' },
    );

    let exitCode = exitCodeOf(result);

    const leaked = findLeakedPaths(guardedBefore);
    if (leaked.length > 0) {
        console.error(
            `[e2e] isolation leaked: the run created or changed ${leaked.join(', ')} — `
            + 'the environment did not reach the server or the scenarios.',
        );
        exitCode = 1;
    }

    process.exit(exitCode);
}
