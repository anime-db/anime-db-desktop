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
const { pruneOldLogs, openLogStream } = require('./logrotate');
const pidTracker = require('./pid-tracker');

// FrankenPHP's embedded PHP runtime doubles as the CLI interpreter — there is no separate
// php.exe binary bundled with the app (see .claude-docs/gotchas.md).
const BINARY  = path.join(__dirname, '..', '..', 'bin', 'frankenphp', 'frankenphp.exe');
const CONSOLE = path.join(__dirname, '..', '..', 'app', 'bin', 'console');

const LOG_MAX = 7;

/** Trailing output kept in a failure message — enough to diagnose, not a full dump. */
const OUTPUT_TAIL_CHARS = 4000;

/**
 * Shared launcher for one-off `bin/console` commands — the ones that spawn, run briefly and
 * exit, as opposed to the long-lived processes (frankenphp, meilisearch, qbittorrent,
 * messenger-consumer) that supervise themselves. Every call gets the same env, output logging,
 * a caller-supplied timeout and PID tracking, so no individual one-off caller has to reimplement
 * them (issue #400).
 *
 * @param {string} command      bin/console subcommand, e.g. 'app:search:reindex'; also used
 *                               (with ':' replaced by '-', since ':' is not a valid Windows
 *                               filename character) as the log file and PID-tracker name
 * @param {string[]} args       extra CLI arguments after the subcommand name
 * @param {import('./env').PhpContext} context
 * @param {number} timeoutMs    how long to wait before killing the process and rejecting; the
 *                               caller picks it, since a stuck one-off command must not block
 *                               supervisor.start() forever (see index.js) but different commands
 *                               have very different expected durations
 * @returns {Promise<void>}
 */
function run(command, args, context, timeoutMs) {
    const name = command.replace(/:/g, '-');

    const logDir = path.join(paths.getRuntimeDir(), 'log');
    pruneOldLogs(logDir, name, LOG_MAX);
    const logStream = openLogStream(logDir, name);

    return new Promise((resolve, reject) => {
        const child = spawn(BINARY, ['php-cli', CONSOLE, command, ...args], {
            cwd: paths.getAppRootDir(),
            env: buildCommonEnv(context),
            stdio: ['ignore', 'pipe', 'pipe'],
        });

        pidTracker.writePid(name, child.pid);

        let output = '';
        child.stdout.on('data', (d) => { output += d; logStream.write(d); });
        child.stderr.on('data', (d) => { output += d; logStream.write(d); });

        let timedOut = false;
        const timer = setTimeout(() => {
            timedOut = true;
            logStream.write(`\n[php-command] ${command} timed out after ${timeoutMs}ms, killing\n`);
            child.kill('SIGKILL');
        }, timeoutMs);

        const tail = () => (output.length > OUTPUT_TAIL_CHARS ? output.slice(-OUTPUT_TAIL_CHARS) : output);

        const finish = (err) => {
            clearTimeout(timer);
            pidTracker.clearPid(name);
            logStream.end();
            if (err) reject(err); else resolve();
        };

        child.on('error', (err) => {
            finish(new Error(`не удалось запустить ${command}: ${err.message}`));
        });
        child.on('exit', (code) => {
            if (timedOut) {
                finish(new Error(`${command} не завершился за ${timeoutMs}ms и был принудительно остановлен:\n${tail()}`));
                return;
            }
            if (code === 0) {
                finish();
                return;
            }
            finish(new Error(`${command} завершился с кодом ${code}:\n${tail()}`));
        });
    });
}

/**
 * Kills a one-off command orphaned by a previous session that never reached its 'exit' event
 * (crash, force-kill from Task Manager) — must run before any child process of the current
 * session starts, same ordering constraint as the other supervisors' killOrphan() (see index.js).
 *
 * @param {string} command  same value passed as `command` to run()
 * @returns {Promise<void>}
 */
function killOrphan(command) {
    return pidTracker.killOrphan(command.replace(/:/g, '-'), BINARY);
}

module.exports = { run, killOrphan };
