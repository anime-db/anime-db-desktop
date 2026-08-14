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
const fs        = require('fs');
const path      = require('path');
const paths     = require('../paths');
const { buildCommonEnv } = require('./env');
const { pruneOldLogs, openLogStream, todayStr } = require('./logrotate');

// FrankenPHP's embedded PHP runtime doubles as the CLI interpreter — there is no separate
// php.exe binary bundled with the app (see .claude-docs/gotchas.md).
const BINARY  = path.join(__dirname, '..', '..', 'bin', 'frankenphp', 'frankenphp.exe');
const CONSOLE = path.join(__dirname, '..', '..', 'app', 'bin', 'console');

const LOG_PREFIX = 'migrations';
const LOG_MAX     = 7;

/** How many of the most recent pre-migration backups to keep in getBackupsDir(). */
const MAX_BACKUPS = 5;

/** doctrine:migrations:up-to-date --fail-on-unregistered exit codes (see vendor/doctrine/migrations). */
const STATUS_UP_TO_DATE  = 0;
const STATUS_OUT_OF_DATE = 1;
const STATUS_DOWNGRADE   = 2;

/**
 * Thrown by run() when the app must not start. `kind` selects which localized dialog
 * lifecycle/index.js shows:
 * - 'downgrade'      — the database belongs to a newer app version than this build (see
 *                       STATUS_DOWNGRADE); downgrading migrations is not supported.
 * - 'backup-failed'  — the pre-migration VACUUM INTO backup could not be created.
 * - 'migrate-failed' — doctrine:migrations:migrate failed even after restoring the backup and
 *                       retrying once.
 */
class MigrationBootstrapError extends Error {
    constructor(kind, detail, backupPath = null, logPath = null) {
        super(detail || kind);
        this.kind       = kind;
        this.detail     = detail || null;
        this.backupPath = backupPath;
        this.logPath    = logPath;
    }
}

/**
 * Runs `bin/console <args>` and resolves with its exit code plus captured stderr — never
 * rejects on a non-zero exit, since callers here need to branch on specific exit codes rather
 * than treat every failure the same way. Both streams are also mirrored into logStream.
 *
 * @param {string[]} args
 * @param {Record<string, string>} env
 * @param {NodeJS.WritableStream} logStream
 * @returns {Promise<{ code: number, stderr: string }>}
 */
function runConsole(args, env, logStream) {
    return new Promise((resolve, reject) => {
        const child = spawn(BINARY, ['php-cli', CONSOLE, ...args], {
            cwd: paths.getAppRootDir(),
            env,
            stdio: ['ignore', 'pipe', 'pipe'],
        });

        let stderr = '';
        child.stdout.on('data', (d) => logStream.write(d));
        child.stderr.on('data', (d) => {
            stderr += d;
            logStream.write(d);
        });

        child.on('error', reject);
        child.on('exit', (code) => resolve({ code, stderr: stderr.trim() }));
    });
}

/**
 * @param {Record<string, string>} env
 * @param {NodeJS.WritableStream} logStream
 * @returns {Promise<number>} STATUS_UP_TO_DATE | STATUS_OUT_OF_DATE | STATUS_DOWNGRADE
 */
async function checkStatus(env, logStream) {
    const { code } = await runConsole(['doctrine:migrations:up-to-date', '--fail-on-unregistered'], env, logStream);
    return code;
}

/**
 * @param {Record<string, string>} env
 * @param {NodeJS.WritableStream} logStream
 * @returns {Promise<{ code: number, stderr: string }>}
 */
function migrate(env, logStream) {
    return runConsole(['doctrine:migrations:migrate', '--no-interaction', '--allow-no-migration'], env, logStream);
}

/**
 * Deletes the oldest backup files so that at most maxBackups remain. File names are
 * `data-<version>-<timestamp>.db`; the version prefix is not sortable (e.g. "1.10.0" sorts
 * before "1.9.0" lexicographically), so files are ordered by the trailing timestamp instead.
 *
 * @param {string} backupDir
 * @param {number} maxBackups
 */
function pruneOldBackups(backupDir, maxBackups) {
    const pattern = /^data-.+-(\d{8}-\d{6})\.db$/;
    const files = fs.readdirSync(backupDir)
        .map(f => ({ name: f, match: f.match(pattern) }))
        .filter(f => f.match !== null)
        .sort((a, b) => a.match[1].localeCompare(b.match[1]));

    const excess = files.length - maxBackups;
    for (let i = 0; i < excess; i++) {
        fs.rmSync(path.join(backupDir, files[i].name), { force: true });
    }
}

/**
 * Runs `app:database:backup` (VACUUM INTO, see DatabaseBackupCommand) to snapshot data.db into
 * getBackupsDir() before a migration is attempted, then prunes old backups down to MAX_BACKUPS.
 *
 * @param {Record<string, string>} env
 * @param {NodeJS.WritableStream} logStream
 * @returns {Promise<string>} path to the backup that was created
 */
async function createBackup(env, logStream) {
    const backupDir = paths.getBackupsDir();
    fs.mkdirSync(backupDir, { recursive: true });

    const now = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    const timestamp = `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}-`
        + `${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`;
    const backupPath = path.join(backupDir, `data-${app.getVersion()}-${timestamp}.db`);

    const { code, stderr } = await runConsole(['app:database:backup', backupPath], env, logStream);
    if (code !== 0) {
        throw new MigrationBootstrapError('backup-failed', stderr || `app:database:backup завершился с кодом ${code}`);
    }

    pruneOldBackups(backupDir, MAX_BACKUPS);
    return backupPath;
}

/**
 * Restores data.db from a backup created by createBackup(). Also removes any `-journal`/`-wal`/
 * `-shm` sidecar left behind by the failed migration attempt — those reference the pre-restore
 * file and would otherwise be (incorrectly) replayed against the restored one on next open.
 *
 * @param {string} backupPath
 */
function restoreBackup(backupPath) {
    const dbPath = paths.getDbPath();
    for (const suffix of ['-journal', '-wal', '-shm']) {
        fs.rmSync(dbPath + suffix, { force: true });
    }
    fs.copyFileSync(backupPath, dbPath);
}

/**
 * Checks the schema status and, if there are pending migrations, backs up data.db and applies
 * them. Must run before frankenphp.start() — doctrine:migrations:migrate itself has no
 * dependency on the HTTP/Meilisearch/queue side of the app, only on DATABASE_URL.
 *
 * On a clean profile this is the same code path: doctrine:migrations:up-to-date reports
 * STATUS_OUT_OF_DATE (no doctrine_migration_versions table yet) and migrate creates the schema
 * from scratch.
 *
 * @param {import('./env').PhpContext} context  без appPort — веб-воркер ещё не поднят (см. env.js)
 * @returns {Promise<void>}
 * @throws {MigrationBootstrapError}
 */
async function run(context) {
    const env = buildCommonEnv(context);

    const logDir = path.join(paths.getRuntimeDir(), 'log');
    pruneOldLogs(logDir, LOG_PREFIX, LOG_MAX);
    const logStream = openLogStream(logDir, LOG_PREFIX);
    const logPath = path.join(logDir, `${LOG_PREFIX}-${todayStr()}.log`);

    try {
        const status = await checkStatus(env, logStream);
        if (status === STATUS_UP_TO_DATE) return;
        if (status === STATUS_DOWNGRADE) throw new MigrationBootstrapError('downgrade');
        if (status !== STATUS_OUT_OF_DATE) {
            throw new MigrationBootstrapError('migrate-failed', `doctrine:migrations:up-to-date завершился с неожиданным кодом ${status}`);
        }

        const backupPath = await createBackup(env, logStream);

        let result = await migrate(env, logStream);
        if (result.code !== 0) {
            restoreBackup(backupPath);
            result = await migrate(env, logStream);
        }
        if (result.code !== 0) {
            throw new MigrationBootstrapError('migrate-failed', result.stderr, backupPath);
        }
    } catch (err) {
        if (err instanceof MigrationBootstrapError) {
            err.logPath = err.logPath || logPath;
            throw err;
        }
        throw new MigrationBootstrapError('migrate-failed', err.message, null, logPath);
    } finally {
        logStream.end();
    }
}

module.exports = { run, MigrationBootstrapError, MAX_BACKUPS };
