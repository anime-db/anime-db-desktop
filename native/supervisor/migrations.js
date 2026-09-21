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

/** doctrine:migrations:up-to-date --fail-on-unregistered exit codes (see vendor/doctrine/migrations). */
const STATUS_UP_TO_DATE  = 0;
const STATUS_OUT_OF_DATE = 1;
const STATUS_DOWNGRADE   = 2;

/** Log file / PID-tracker name for checkDumpSchema(), kept separate from LOG_PREFIX so a schema
 *  check never shares a log file or PID slot with an in-flight run() against the working DB. */
const DUMP_CHECK_NAME = 'migrations-dump-check';

/**
 * The three possible outcomes of checkDumpSchema().
 *
 * @readonly
 * @enum {string}
 */
const DumpSchemaVerdict = {
    /** No migrations unknown to this build are present — the file can be applied. Also covers a
     *  file older than the build: its pending-but-known migrations would just run normally. */
    COMPATIBLE:   'compatible',
    /** The file has executed migrations this build does not recognize (STATUS_DOWNGRADE). Not
     *  necessarily "the file is newer" — a renamed, removed or squashed migration produces the
     *  same signal. */
    REJECT:       'reject',
    /** The file does not exist, isn't a readable SQLite database, or the check process failed or
     *  timed out — the file's compatibility could not be determined either way. */
    CHECK_FAILED: 'check-failed',
};

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
 * Checks whether an arbitrary SQLite file's schema is compatible with this build — used to vet a
 * prepared import file *before* anything swaps it in as the working database. This is a separate,
 * standalone check from the STATUS_DOWNGRADE guard in run() below: that one runs against the
 * working data.db as part of the normal startup migration flow, while this one runs against a
 * caller-supplied file and never touches data.db (or its own target file) at all — no backup is
 * taken, nothing is restored, no migration is applied.
 *
 * Runs `doctrine:migrations:up-to-date --fail-on-unregistered` with DATABASE_URL pointed at
 * `dbPath` instead of the working database (see php-command.js's `envOverride`).
 *
 * @param {string} dbPath  path to the file to check
 * @param {import('./env').PhpContext} context
 * @returns {Promise<string>} one of DumpSchemaVerdict:
 *   - COMPATIBLE   — no migrations unknown to this build are present (including a file older
 *                    than the build, which is a normal, applicable case);
 *   - REJECT       — the file has executed migrations this build does not recognize; this is not
 *                    necessarily "the file is newer" — see DumpSchemaVerdict.REJECT;
 *   - CHECK_FAILED — the file does not exist, isn't a readable SQLite database, or the check
 *                    process failed or timed out.
 */
async function checkDumpSchema(dbPath, context) {
    try {
        const stat = await fs.promises.stat(dbPath);
        if (!stat.isFile()) return DumpSchemaVerdict.CHECK_FAILED;
    } catch {
        return DumpSchemaVerdict.CHECK_FAILED;
    }

    let status;
    try {
        status = await phpCommand.run(
            'doctrine:migrations:up-to-date',
            ['--fail-on-unregistered'],
            context,
            CONSOLE_TIMEOUT_MS,
            {
                rejectOnNonZero: false,
                name: DUMP_CHECK_NAME,
                envOverride: { DATABASE_URL: `sqlite:///${dbPath}` },
            },
        );
    } catch {
        return DumpSchemaVerdict.CHECK_FAILED;
    }

    if (status.code === STATUS_DOWNGRADE) return DumpSchemaVerdict.REJECT;

    const output = [status.stdout, status.stderr].filter(Boolean).join('\n');
    if (status.code === STATUS_UP_TO_DATE || (status.code === STATUS_OUT_OF_DATE && output.includes(OUT_OF_DATE_MARKER))) {
        return DumpSchemaVerdict.COMPATIBLE;
    }

    return DumpSchemaVerdict.CHECK_FAILED;
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
 * @param {import('./env').PhpContext} context
 * @returns {Promise<string>} path to the backup that was created
 */
async function createBackup(context) {
    const backupDir = paths.getBackupsDir();
    fs.mkdirSync(backupDir, { recursive: true });

    const now = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    const timestamp = `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}-`
        + `${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`;
    const backupPath = path.join(backupDir, `data-${app.getVersion()}-${timestamp}.db`);

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

module.exports = { run, killOrphan, checkDumpSchema, DumpSchemaVerdict, MigrationBootstrapError, MAX_BACKUPS };
