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

const { app } = require('electron');
const fs      = require('fs');
const path    = require('path');
const paths   = require('../paths');
const { todayStr } = require('./logrotate');
const phpCommand = require('./php-command');

const LOG_PREFIX = 'migrations';

/** How many of the most recent pre-migration backups to keep in getBackupsDir(). */
const MAX_BACKUPS = 5;

/**
 * Filename prefix (`data-preimport-<timestamp>.db`) that marks a snapshot as taken ahead of a
 * catalog import rather than a routine pre-migration backup — see createPreImportBackup(). Kept
 * distinct from the version string createBackup() embeds in the same position so pruneOldBackups()
 * can tell the two apart by filename alone.
 */
const PREIMPORT_PREFIX = 'preimport';

/** doctrine:migrations:up-to-date --fail-on-unregistered exit codes (see vendor/doctrine/migrations). */
const STATUS_UP_TO_DATE  = 0;
const STATUS_OUT_OF_DATE = 1;
const STATUS_DOWNGRADE   = 2;

/**
 * The message doctrine/migrations' up-to-date command prints (to stdout, via SymfonyStyle)
 * when it exits with STATUS_OUT_OF_DATE because there are pending migrations. Symfony also
 * exits 1 on any uncaught exception, so the exit code alone can't tell "pending migrations"
 * apart from "console crashed" (broken php.ini, missing vendor, unreadable DB directory, etc.).
 */
const OUT_OF_DATE_MARKER = 'Out-of-date!';

/**
 * Upper bound for a single bin/console invocation. Fail-closed startup means a hang here
 * (locked DB file, migration waiting on input) would otherwise block the splash screen
 * forever with no way for the user to recover.
 */
const CONSOLE_TIMEOUT_MS = 10 * 60 * 1000;

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
 * Kills a console invocation orphaned by a previous session that never reached the exit event
 * (crash, force-kill from Task Manager) — must run before any child process of the current
 * session starts, same ordering constraint as the other supervisors' killOrphan() (see
 * index.js).
 *
 * @returns {Promise<void>}
 */
function killOrphan() {
    return phpCommand.killOrphan(LOG_PREFIX);
}

/**
 * @param {import('./env').PhpContext} context
 * @returns {Promise<{ code: number | null, stdout: string, stderr: string }>}
 */
function checkStatus(context) {
    return phpCommand.run(
        'doctrine:migrations:up-to-date',
        ['--fail-on-unregistered'],
        context,
        CONSOLE_TIMEOUT_MS,
        { rejectOnNonZero: false, name: LOG_PREFIX },
    );
}

/**
 * @param {import('./env').PhpContext} context
 * @returns {Promise<{ code: number | null, stdout: string, stderr: string }>}
 */
function migrate(context) {
    return phpCommand.run(
        'doctrine:migrations:migrate',
        ['--no-interaction', '--allow-no-migration'],
        context,
        CONSOLE_TIMEOUT_MS,
        { rejectOnNonZero: false, name: LOG_PREFIX },
    );
}

/**
 * Deletes the oldest routine backup files so that at most maxBackups remain. File names are
 * `data-<version>-<timestamp>.db`; the version prefix is not sortable (e.g. "1.10.0" sorts
 * before "1.9.0" lexicographically), so files are ordered by the trailing timestamp instead.
 *
 * Deliberately excludes `data-preimport-<timestamp>.db` snapshots (see createPreImportBackup()) —
 * those are not part of the routine pre-migration rotation MAX_BACKUPS bounds, and must survive
 * it indefinitely (issue #681).
 *
 * @param {string} backupDir
 * @param {number} maxBackups
 */
function pruneOldBackups(backupDir, maxBackups) {
    const pattern = new RegExp(`^data-(?!${PREIMPORT_PREFIX}-).+-(\\d{8}-\\d{6})\\.db$`);
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
 * @returns {string} the current instant as `YYYYMMDD-HHMMSS`, the timestamp format both
 *   createBackup() and createPreImportBackup() embed in their filenames.
 */
function formatTimestamp(date) {
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}${pad(date.getMonth() + 1)}${pad(date.getDate())}-`
        + `${pad(date.getHours())}${pad(date.getMinutes())}${pad(date.getSeconds())}`;
}

/**
 * Runs `app:database:backup` (VACUUM INTO, see DatabaseBackupCommand) to snapshot data.db to
 * backupPath — the step shared by createBackup() and createPreImportBackup().
 *
 * @param {string} backupPath
 * @param {import('./env').PhpContext} context
 * @returns {Promise<void>}
 */
async function runDatabaseBackupCommand(backupPath, context) {
    const { code, stderr } = await phpCommand.run(
        'app:database:backup',
        [backupPath],
        context,
        CONSOLE_TIMEOUT_MS,
        { rejectOnNonZero: false, name: LOG_PREFIX },
    );
    if (code !== 0) {
        throw new MigrationBootstrapError('backup-failed', stderr || `app:database:backup завершился с кодом ${code}`);
    }
}

/**
 * Runs `app:database:backup` (VACUUM INTO, see DatabaseBackupCommand) to snapshot data.db into
 * getBackupsDir() before a migration is attempted, then prunes old backups down to MAX_BACKUPS.
 *
 * @param {import('./env').PhpContext} context
 * @returns {Promise<string>} path to the backup that was created
 */
async function createBackup(context) {
    const backupDir = paths.getBackupsDir();
    fs.mkdirSync(backupDir, { recursive: true });

    const backupPath = path.join(backupDir, `data-${app.getVersion()}-${formatTimestamp(new Date())}.db`);
    await runDatabaseBackupCommand(backupPath, context);

    pruneOldBackups(backupDir, MAX_BACKUPS);
    return backupPath;
}

/**
 * Snapshots data.db the same way createBackup() does, but under a `data-preimport-<timestamp>.db`
 * name instead of `data-<version>-<timestamp>.db` — pruneOldBackups() deliberately excludes this
 * prefix, so the snapshot survives routine rotation indefinitely (issue #681) instead of being
 * evicted by MAX_BACKUPS churn while an import is still in progress.
 *
 * Not called from anywhere in this codebase yet — reserved for the catalog-import apply step
 * (issue #659, not yet built), which needs a backup of the pre-import catalog to restore from if
 * the user reverts.
 *
 * @param {import('./env').PhpContext} context
 * @returns {Promise<string>} path to the backup that was created
 */
async function createPreImportBackup(context) {
    const backupDir = paths.getBackupsDir();
    fs.mkdirSync(backupDir, { recursive: true });

    const backupPath = path.join(backupDir, `data-${PREIMPORT_PREFIX}-${formatTimestamp(new Date())}.db`);
    await runDatabaseBackupCommand(backupPath, context);

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
 * @returns {Promise<boolean>} true, если doctrine:migrations:migrate реально применил хотя бы
 *   одну миграцию; false, если схема уже была актуальна и migrate не запускался.
 * @throws {MigrationBootstrapError}
 */
async function run(context) {
    const logDir = path.join(paths.getRuntimeDir(), 'log');
    const logPath = path.join(logDir, `${LOG_PREFIX}-${todayStr()}.log`);

    try {
        const status = await checkStatus(context);
        if (status.code === STATUS_UP_TO_DATE) return false;
        if (status.code === STATUS_DOWNGRADE) throw new MigrationBootstrapError('downgrade');

        const output = [status.stdout, status.stderr].filter(Boolean).join('\n');
        if (status.code !== STATUS_OUT_OF_DATE || !output.includes(OUT_OF_DATE_MARKER)) {
            // A bare exit code of 1 is ambiguous: doctrine also uses it for "pending
            // migrations", but Symfony returns the same code for any uncaught exception
            // (broken php.ini, missing vendor, unreadable DB directory, ...). Require the
            // doctrine "Out-of-date!" marker in the output before treating this as the former —
            // otherwise a real crash here would be misreported as a migration and go on to fail
            // the backup step too, masking the actual cause.
            throw new MigrationBootstrapError(
                'migrate-failed',
                output || `doctrine:migrations:up-to-date завершился с неожиданным кодом ${status.code}`,
            );
        }

        const backupPath = await createBackup(context);

        let result = await migrate(context);
        if (result.code !== 0) {
            restoreBackup(backupPath);
            result = await migrate(context);
        }
        if (result.code !== 0) {
            restoreBackup(backupPath);
            throw new MigrationBootstrapError('migrate-failed', result.stderr, backupPath);
        }

        return true;
    } catch (err) {
        if (err instanceof MigrationBootstrapError) {
            err.logPath = err.logPath || logPath;
            throw err;
        }
        throw new MigrationBootstrapError('migrate-failed', err.message, null, logPath);
    }
}

module.exports = { run, killOrphan, MigrationBootstrapError, MAX_BACKUPS, restoreBackup, createPreImportBackup };
