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
const { defineConfig } = require('@playwright/test');

const SCENARIO_TIMEOUT_MS = Number(process.env.E2E_TIMEOUT_MS) || 90000;

module.exports = defineConfig({
    testDir:    path.join(__dirname, 'scenarios'),
    testMatch:  '**/*.e2e.js',
    outputDir:  path.join(__dirname, '..', '..', 'e2e-results'),
    // Per-scenario budget, startup (server + Electron) included.
    timeout:    SCENARIO_TIMEOUT_MS,
    expect:     { timeout: 5000 },
    // Each scenario boots its own server and window; they are isolated, but Xvfb + FrankenPHP
    // per scenario is heavy enough that serial is the safe default.
    workers:    1,
    retries:    0,
    reporter:   [['list'], [path.join(__dirname, 'verdict-reporter.js')]],
});
