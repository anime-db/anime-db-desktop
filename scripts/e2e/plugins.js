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
 * Offline source plugin for scenarios that need a "fill from source" button. It lives in
 * scripts/e2e/plugins/e2e-source and is copied into the environment's plugins directory before the
 * server starts (the Symfony container is compiled with it). Without it the card has no such
 * buttons at all: a fresh fixture has no plugins, and a scenario must not need the network.
 */

const { execFileSync } = require('child_process');
const crypto = require('crypto');
const fs     = require('fs');
const path   = require('path');

const { envForDir } = require('../fixture');
const { buildPhpEnv } = require('./server');

const appDir = path.resolve(__dirname, '..', '..', 'app');

const PLUGIN_ID = 'e2e-source';
const FRAME_URL = 'https://frames.invalid/e2e-frame.webp';
// A valid 1x1 WebP; never decoded by the host (the file only has to exist), kept valid for browsers.
const FRAME_BYTES = Buffer.from('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==', 'base64');

/**
 * Copies the plugin into the environment and lets the app index it.
 *
 * @param {string} dataDir
 * @param {Record<string, string>} env  user-data variables (scripts/fixture envForDir)
 */
function installSourcePlugin(dataDir, env) {
    const target = path.join(env.PLUGINS_DIR, PLUGIN_ID);
    fs.cpSync(path.join(__dirname, 'plugins', PLUGIN_ID), target, { recursive: true });

    try {
        execFileSync('php', [path.join(appDir, 'bin', 'console'), 'app:plugin:reconcile', '--no-interaction'], {
            cwd: appDir,
            env: { ...process.env, ...env },
            stdio: 'pipe',
        });
    } catch (err) {
        throw new Error(`plugin reconcile failed:\n${(err.stderr || err.stdout || err.message).toString()}`);
    }
}

/**
 * Switches what the plugin answers: 'empty' (nothing found), 'images' (one frame) or 'linkable' (one record, no media). In 'images'
 * the frame file is put where the host's media downloader looks for an already downloaded URL, so
 * the scenario needs no network.
 *
 * @param {string} dataDir
 * @param {'empty'|'images'|'linkable'} mode
 * @param {number} animeId
 */
function setSourceMode(dataDir, mode, animeId) {
    fs.writeFileSync(path.join(dataDir, 'plugins', PLUGIN_ID, 'mode'), mode);

    if (mode === 'images') {
        const dir = path.join(dataDir, 'media', String(animeId));
        fs.mkdirSync(dir, { recursive: true });
        const name = `${crypto.createHash('sha1').update(FRAME_URL).digest('hex')}.webp`;
        fs.writeFileSync(path.join(dir, name), FRAME_BYTES);
    }
}

/**
 * External ids the host asked the plugin to remove from its list, in call order (the plugin's
 * `removed` file; absent until the first call).
 *
 * @param {string} dataDir
 * @returns {string[]}
 */
function removedFromSource(dataDir) {
    const file = path.join(dataDir, 'plugins', PLUGIN_ID, 'removed');

    return fs.existsSync(file) ? fs.readFileSync(file, 'utf8').split('\n').filter((line) => line !== '') : [];
}

/**
 * Runs the queued deletions from the sources. The app removes an entry from a source's list through
 * the message queue, whose consumer is a separate process the E2E run does not start; this runs
 * the consumer for a few seconds, enough for the handful of jobs a scenario queues.
 *
 * @param {string} dataDir
 */
function consumeQueuedRemovals(dataDir) {
    try {
        execFileSync('php', [path.join(appDir, 'bin', 'console'), 'messenger:consume', 'async', '--time-limit=4', '--sleep=0.2', '--no-interaction'], {
            cwd: appDir,
            env: buildPhpEnv(dataDir, envForDir(dataDir)),
            stdio: 'pipe',
        });
    } catch (err) {
        throw new Error(`queue consume failed:\n${(err.stderr || err.stdout || err.message).toString()}`);
    }
}

module.exports = { installSourcePlugin, setSourceMode, removedFromSource, consumeQueuedRemovals, PLUGIN_ID };
