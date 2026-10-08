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
 * Deterministic data fixture and isolated user-data environments for runs that need a populated
 * app (the screenshot run, future scenario tests).
 *
 * The fixture is a script, not a binary file: buildFixture() migrates an empty SQLite database
 * and loads the data set with `app:fixture:load` (see FixtureLoadCommand), so it never drifts
 * from the schema. The result is built once per process and *copied* into a fresh temp directory
 * for every createIsolatedEnv() call — a copy of a SQLite file takes milliseconds, so a scenario
 * can have its own environment and nothing is reset between them.
 *
 * Every path of user data (databases, config.json, media, caches, ...) points into the copy, so a
 * run never touches `data/` or `app/var/` of a developer's checkout.
 */

const { execFileSync } = require('child_process');
const fs   = require('fs');
const os   = require('os');
const path = require('path');

const rootDir = path.resolve(__dirname, '..', '..');
const appDir  = path.join(rootDir, 'app');
const consolePath = path.join(appDir, 'bin', 'console');

/**
 * Layout of user data inside an environment directory.
 *
 * @param {string} dir
 * @returns {Record<string, string>} environment variables read by app/config/services.yaml and .env
 */
function envForDir(dir) {
    return {
        DATABASE_URL:                 `sqlite:///${path.join(dir, 'data.db')}`,
        QUEUE_DATABASE_URL:           `sqlite:///${path.join(dir, 'queue.db')}`,
        CONFIG_PATH:                  path.join(dir, 'config.json'),
        PLUGINS_CONFIG_PATH:          path.join(dir, 'plugins.json'),
        PLUGINS_DIR:                  path.join(dir, 'plugins'),
        MEDIA_DIR:                    path.join(dir, 'media'),
        IMPORT_STAGING_DIR:           path.join(dir, 'import-staging'),
        IMPORT_REJECTION_PATH:        path.join(dir, 'import-rejected.json'),
        IMPORT_APPLIED_PATH:          path.join(dir, 'import-applied.json'),
        IMPORT_V1_REPORT_PATH:        path.join(dir, 'import-v1-report.json'),
        NATIVE_TRANSLATIONS_OVERLAY_DIR: path.join(dir, 'native-translations'),
        MARKET_REGISTRY_CACHE_PATH:   path.join(dir, 'market-registry-cache.json'),
        MARKET_SNAPSHOT_CACHE_PATH:   path.join(dir, 'market-snapshot-cache.json'),
        MARKET_REFRESH_LOCK_PATH:     path.join(dir, 'market-refresh.lock'),
        BACKUPS_DIR:                  path.join(dir, 'backups'),
    };
}

/**
 * @param {string[]} args
 * @param {Record<string, string>} env
 */
function runConsole(args, env) {
    try {
        execFileSync('php', [consolePath, ...args, '--no-interaction'], {
            cwd: appDir,
            env: { ...process.env, ...env },
            stdio: 'pipe',
        });
    } catch (err) {
        throw new Error(`console ${args[0]} failed:\n${(err.stderr || err.stdout || err.message).toString()}`);
    }
}

/**
 * Builds the fixture from scratch into `dir` (an empty directory): schema, queue transport, data.
 *
 * @param {string} dir
 */
function buildFixture(dir) {
    fs.mkdirSync(dir, { recursive: true });
    const env = envForDir(dir);

    runConsole(['doctrine:migrations:migrate'], env);
    runConsole(['messenger:setup-transports'], env);
    runConsole(['app:fixture:load'], env);
}

/** @type {string|null} */
let templateDir = null;

/**
 * The built fixture of this process; built on first use, removed by disposeFixture().
 *
 * @returns {string}
 */
function fixtureDir() {
    if (templateDir === null) {
        const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'animedb-fixture-'));
        try {
            buildFixture(dir);
        } catch (err) {
            fs.rmSync(dir, { recursive: true, force: true });
            throw err;
        }
        templateDir = dir;
    }

    return templateDir;
}

function disposeFixture() {
    if (templateDir !== null) {
        fs.rmSync(templateDir, { recursive: true, force: true });
        templateDir = null;
    }
}

/**
 * Copies the fixture into a fresh temp directory. The fixture is built in this same process from
 * the current migrations, so the copy needs no further migration.
 *
 * @returns {{ dir: string, env: Record<string, string>, cleanup: () => void }}
 */
function createIsolatedEnv() {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'animedb-env-'));
    fs.cpSync(fixtureDir(), dir, { recursive: true });

    return {
        dir,
        env: envForDir(dir),
        cleanup: () => fs.rmSync(dir, { recursive: true, force: true }),
    };
}

module.exports = { buildFixture, createIsolatedEnv, disposeFixture, envForDir };
