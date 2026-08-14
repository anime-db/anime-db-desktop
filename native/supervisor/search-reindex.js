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

const { spawn } = require('child_process');
const path      = require('path');
const paths     = require('../paths');
const { buildCommonEnv } = require('./env');

// FrankenPHP's embedded PHP runtime doubles as the CLI interpreter — there is no separate
// php.exe binary bundled with the app (see .claude-docs/gotchas.md).
const BINARY  = path.join(__dirname, '..', '..', 'bin', 'frankenphp', 'frankenphp.exe');
const CONSOLE = path.join(__dirname, '..', '..', 'app', 'bin', 'console');

/**
 * Runs `bin/console app:search:reindex` once and resolves when it exits successfully. Used to
 * rebuild the Meilisearch index after it was wiped by a version change (see meilisearch.js
 * checkVersionAndWipe / issue #389).
 *
 * @param {import('./env').PhpContext} context
 * @returns {Promise<void>}
 */
function run(context) {
    return new Promise((resolve, reject) => {
        const child = spawn(BINARY, ['php-cli', CONSOLE, 'app:search:reindex'], {
            cwd: paths.getAppRootDir(),
            env: buildCommonEnv(context),
            stdio: 'ignore',
        });

        child.on('error', reject);
        child.on('exit', (code) => {
            if (code === 0) {
                resolve();
            } else {
                reject(new Error(`app:search:reindex завершился с кодом ${code}`));
            }
        });
    });
}

module.exports = { run };
