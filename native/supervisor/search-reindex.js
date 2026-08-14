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

const { app }   = require('electron');
const { spawn } = require('child_process');
const path      = require('path');
const paths     = require('../paths');
const { getOrCreateAppSecret } = require('../config');

// FrankenPHP's embedded PHP runtime doubles as the CLI interpreter — there is no separate
// php.exe binary bundled with the app (see .claude-docs/gotchas.md).
const BINARY  = path.join(__dirname, '..', '..', 'bin', 'frankenphp', 'frankenphp.exe');
const CONSOLE = path.join(__dirname, '..', '..', 'app', 'bin', 'console');

function buildEnv(meiliPort, meiliKey) {
    return {
        ...process.env,
        APP_ROOT:                paths.getAppRootDir(),
        APP_ENV:                 'prod',
        APP_SECRET:              getOrCreateAppSecret(),
        CORE_VERSION:            app.getVersion(),
        DATABASE_URL:            `sqlite:///${paths.getDbPath()}`,
        QUEUE_DATABASE_URL:      `sqlite:///${paths.getQueueDbPath()}`,
        MESSENGER_TRANSPORT_DSN: 'doctrine://queue?auto_setup=0',
        PHPRC:                   paths.getPhpIniDir(),
        APP_RUNTIME_DIR:         paths.getRuntimeDir(),
        MEDIA_DIR:               paths.getMediaDir(),
        CONFIG_PATH:             paths.getConfigPath(),
        PLUGINS_CONFIG_PATH:     paths.getPluginsConfigPath(),
        MEILISEARCH_URL:         `http://127.0.0.1:${meiliPort}`,
        MEILISEARCH_KEY:         meiliKey,
    };
}

/**
 * Runs `bin/console app:search:reindex` once and resolves when it exits successfully. Used to
 * rebuild the Meilisearch index after it was wiped by a version change (see meilisearch.js
 * checkVersionAndWipe / issue #389).
 *
 * @param {number} meiliPort
 * @param {string} meiliKey
 * @returns {Promise<void>}
 */
function run(meiliPort, meiliKey) {
    return new Promise((resolve, reject) => {
        const child = spawn(BINARY, ['php-cli', CONSOLE, 'app:search:reindex'], {
            cwd: paths.getAppRootDir(),
            env: buildEnv(meiliPort, meiliKey),
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

module.exports = { run, buildEnv };
