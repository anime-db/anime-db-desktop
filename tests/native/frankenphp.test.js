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

const fs   = require('fs');
const os   = require('os');
const path = require('path');

jest.mock('electron', () => ({
    app: { getVersion: jest.fn(() => '1.2.3') },
}));
jest.mock('../../native/paths', () => ({
    getAppRootDir:         jest.fn(() => '/fake/app'),
    getDbPath:             jest.fn(() => '/fake/userData/data.db'),
    getQueueDbPath:        jest.fn(() => '/fake/userData/queue.db'),
    getPhpIniDir:          jest.fn(() => '/fake/userData'),
    getPhpIniPath:         jest.fn(() => '/fake/userData/php.ini'),
    getRuntimeDir:         jest.fn(() => '/fake/userData/var'),
    getShareDir:           jest.fn(() => '/fake/userData/share'),
    getMediaDir:           jest.fn(() => '/fake/userData/media'),
    getImportStagingDir:   jest.fn(() => '/fake/userData/import-staging'),
    getImportRejectionPath: jest.fn(() => '/fake/userData/import-rejected.json'),
    getImportAppliedPath: jest.fn(() => '/fake/userData/import-applied.json'),
    getConfigPath:         jest.fn(() => '/fake/userData/config.json'),
    getPluginsConfigPath:  jest.fn(() => '/fake/userData/plugins.json'),
    getFfprobeBinPath:     jest.fn(() => '/fake/bin/ffprobe/ffprobe.exe'),
    getPluginsDir:         jest.fn(() => '/fake/userData/plugins'),
    getNativeTranslationsDir:        jest.fn(() => '/fake/app/native/translations'),
    getNativeTranslationsOverlayDir: jest.fn(() => '/fake/userData/native-translations'),
    getMarketRegistryCachePath: jest.fn(() => '/fake/userData/market-registry-cache.json'),
    getMarketSnapshotCachePath: jest.fn(() => '/fake/userData/market-snapshot-cache.json'),
    getMarketRefreshLockPath:   jest.fn(() => '/fake/userData/market-refresh.lock'),
    getBackupsDir:        jest.fn(() => '/fake/userData/backups'),
    getMeilisearchDataDir: jest.fn(() => '/fake/userData/meilisearch'),
    getMeilisearchKeyPath: jest.fn(() => '/fake/userData/meilisearch-key.txt'),
    getUserDataDir:        jest.fn(() => '/fake/userData'),
}));
jest.mock('../../native/config', () => ({
    getOrCreateAppSecret: jest.fn(() => 'a'.repeat(64)),
}));
jest.mock('../../native/supervisor/logrotate', () => ({
    pruneOldLogs:   jest.fn(),
    openLogStream:  jest.fn(),
}));
jest.mock('../../native/supervisor/port', () => ({
    findFreePort: jest.fn(),
}));
jest.mock('../../native/supervisor/healthcheck', () => ({
    waitForHealth: jest.fn(),
}));

const paths = require('../../native/paths');
const { buildEnv, ensurePhpIni } = require('../../native/supervisor/frankenphp');

const CONTEXT = { appPort: 8000, qbittorrentPort: 9999, meiliPort: 7700, meiliKey: 'test-key' };
const WS_PORT = 9000;

describe('buildEnv', () => {
    test('includes APP_PORT as a string', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.APP_PORT).toBe('8000');
        expect(typeof env.APP_PORT).toBe('string');
    });

    test('includes WS_PORT as a string', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.WS_PORT).toBe('9000');
        expect(typeof env.WS_PORT).toBe('string');
    });

    test('includes APP_ROOT pointing to the app directory', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.APP_ROOT).toBe('/fake/app');
    });

    test('includes APP_ENV set to prod', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.APP_ENV).toBe('prod');
    });

    test('includes CORE_VERSION from app.getVersion()', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.CORE_VERSION).toBe('1.2.3');
    });

    test('includes DATABASE_URL as a sqlite:// URL', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.DATABASE_URL).toMatch(/^sqlite:\/\/\//);
        expect(env.DATABASE_URL).toContain('data.db');
    });

    test('includes QUEUE_DATABASE_URL as a sqlite:// URL pointing to a different file than DATABASE_URL', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.QUEUE_DATABASE_URL).toMatch(/^sqlite:\/\/\//);
        expect(env.QUEUE_DATABASE_URL).toContain('queue.db');
        expect(env.QUEUE_DATABASE_URL).not.toBe(env.DATABASE_URL);
    });

    test('includes MESSENGER_TRANSPORT_DSN pointing to the queue connection', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.MESSENGER_TRANSPORT_DSN).toBe('doctrine://queue?auto_setup=0');
    });

    test('includes PHPRC pointing to the php.ini directory', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.PHPRC).toBe('/fake/userData');
    });

    test('includes APP_RUNTIME_DIR pointing to the runtime dir', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.APP_RUNTIME_DIR).toBe('/fake/userData/var');
    });

    test('includes MEDIA_DIR pointing to the media dir', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.MEDIA_DIR).toBe('/fake/userData/media');
    });

    test('includes CONFIG_PATH pointing to config.json', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.CONFIG_PATH).toBe('/fake/userData/config.json');
    });

    test('includes PLUGINS_CONFIG_PATH pointing to plugins.json', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.PLUGINS_CONFIG_PATH).toBe('/fake/userData/plugins.json');
    });

    test('includes PLUGINS_DIR pointing to plugins', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.PLUGINS_DIR).toBe('/fake/userData/plugins');
    });

    test('includes QBITTORRENT_URL using the given qbittorrentPort', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.QBITTORRENT_URL).toBe('http://127.0.0.1:9999');
    });

    test('includes MEILISEARCH_URL using the given meiliPort', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.MEILISEARCH_URL).toBe('http://127.0.0.1:7700');

        const env2 = buildEnv({ ...CONTEXT, meiliPort: 8888 }, WS_PORT);
        expect(env2.MEILISEARCH_URL).toBe('http://127.0.0.1:8888');
    });

    test('includes MEILISEARCH_KEY matching the provided key', () => {
        const env = buildEnv({ ...CONTEXT, meiliKey: 'my-secret-key' }, WS_PORT);
        expect(env.MEILISEARCH_KEY).toBe('my-secret-key');
    });

    // Issue #871: without an explicit oauthCallbackOrigin on the context, this is the fallback
    // path (fixed port 41813 busy) — OAUTH_CALLBACK_ORIGIN still derives from appPort, same as
    // before #871. See tests/native/env.test.js for the fixed-port path.
    test('includes OAUTH_CALLBACK_ORIGIN using the given appPort (fallback, no fixed origin set)', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.OAUTH_CALLBACK_ORIGIN).toBe('http://127.0.0.1:8000');

        const env2 = buildEnv({ ...CONTEXT, appPort: 12345 }, WS_PORT);
        expect(env2.OAUTH_CALLBACK_ORIGIN).toBe('http://127.0.0.1:12345');
    });

    // Issue #871: once the supervisor's oauth-callback listener bound the fixed port, FrankenPHP's
    // web worker gets that fixed origin regardless of its own appPort.
    test('uses the fixed oauthCallbackOrigin instead of deriving from appPort when it is set', () => {
        const env = buildEnv({ ...CONTEXT, appPort: 12345, oauthCallbackOrigin: 'http://127.0.0.1:41813', oauthCallbackFixedPort: true }, WS_PORT);
        expect(env.OAUTH_CALLBACK_ORIGIN).toBe('http://127.0.0.1:41813');
        expect(env.OAUTH_CALLBACK_FIXED_PORT).toBe('1');
        expect(env.APP_PORT).toBe('12345');
    });

    test('inherits existing process.env variables', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env).toMatchObject(process.env);
    });

    test('includes APP_SECRET from getOrCreateAppSecret', () => {
        const env = buildEnv(CONTEXT, WS_PORT);
        expect(env.APP_SECRET).toBe('a'.repeat(64));
        expect(env.APP_SECRET).toHaveLength(64);
    });
});

// Mirrors the extensionDir computation in native/supervisor/frankenphp.js (path.join from
// __dirname up to the repo root, then into bin/frankenphp/ext) — tests/native sits at the same
// depth from the repo root as native/supervisor, so the two resolve to the same path.
const EXTENSION_DIR = path.join(__dirname, '..', '..', 'bin', 'frankenphp', 'ext').replace(/\\/g, '/');

describe('ensurePhpIni', () => {
    let tmpDir;
    let iniPath;

    beforeEach(() => {
        tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'frankenphp-ini-test-'));
        iniPath = path.join(tmpDir, 'php.ini');
    });

    afterEach(() => {
        fs.rmSync(tmpDir, { recursive: true, force: true });
    });

    test('creates php.ini from the template when none exists yet', () => {
        paths.getPhpIniPath.mockReturnValueOnce(iniPath);
        paths.getPhpIniDir.mockReturnValueOnce(tmpDir);

        ensurePhpIni();

        const ini = fs.readFileSync(iniPath, 'utf8');
        expect(ini).toMatch(/extension_dir = "/);
        expect(ini).toContain('extension=intl');
        expect(ini).toContain('extension=zip');
        expect(ini).toContain('extension=pdo_sqlite');
        expect(ini).toContain('extension=openssl');
        expect(ini).toContain('extension=mbstring');
        expect(ini).toContain('extension=gd');
    });

    test('leaves an already up-to-date php.ini untouched', () => {
        const original = [
            `extension_dir = "${EXTENSION_DIR}"`,
            'memory_limit = 256M',
            'upload_max_filesize = 16M',
            'post_max_size = 16M',
            'extension=intl',
            'extension=zip',
            'extension=pdo_sqlite',
            'extension=openssl',
            'extension=mbstring',
            'extension=gd',
            '; user comment',
            '',
        ].join('\n');
        fs.writeFileSync(iniPath, original, 'utf8');
        paths.getPhpIniPath.mockReturnValueOnce(iniPath);

        ensurePhpIni();

        expect(fs.readFileSync(iniPath, 'utf8')).toBe(original);
    });

    // Regression for the case where an install generated php.ini before extension_dir/extension=
    // lines existed in the template (or before a given extension was added to it): the file must
    // gain the missing directives on the next start, not silently keep loading without them.
    test('appends directives missing from an ini generated by an older template, preserving user edits', () => {
        const legacy = [
            '[Date]',
            'date.timezone = Europe/Moscow',
            '; my custom tweak',
            'apc.shm_size=64M',
            '',
        ].join('\n');
        fs.writeFileSync(iniPath, legacy, 'utf8');
        paths.getPhpIniPath.mockReturnValueOnce(iniPath);

        ensurePhpIni();

        const updated = fs.readFileSync(iniPath, 'utf8');
        expect(updated.startsWith(legacy)).toBe(true);
        expect(updated).toMatch(/extension_dir = "/);
        expect(updated).toContain('extension=intl');
        expect(updated).toContain('extension=zip');
        expect(updated).toContain('extension=pdo_sqlite');
        expect(updated).toContain('extension=openssl');
        expect(updated).toContain('extension=mbstring');
        expect(updated).toContain('extension=gd');
        expect(updated).toContain('; my custom tweak');
        expect(updated).toContain('apc.shm_size=64M');
    });

    test('appends only the specific directives that are missing, without duplicating existing ones', () => {
        const partial = [
            'extension_dir = "/fake/ext"',
            'extension=intl',
            'extension=zip',
            '',
        ].join('\n');
        fs.writeFileSync(iniPath, partial, 'utf8');
        paths.getPhpIniPath.mockReturnValueOnce(iniPath);

        ensurePhpIni();

        const updated = fs.readFileSync(iniPath, 'utf8');
        expect((updated.match(/extension_dir/g) || []).length).toBe(1);
        expect((updated.match(/extension=intl/g) || []).length).toBe(1);
        expect(updated).toContain('extension=pdo_sqlite');
        expect(updated).toContain('extension=openssl');
        expect(updated).toContain('extension=mbstring');
        expect(updated).toContain('extension=gd');
    });

    // Regression for a reinstall into a different directory: AppData survives it, so the existing
    // ini's extension_dir points at the previous install path. Presence alone would consider it
    // fine and never fix it — the value itself must be checked and rewritten.
    test('rewrites a stale extension_dir left over from a previous install location, in place', () => {
        const stale = [
            'extension_dir = "/old/install/ext"',
            'extension=intl',
            'extension=zip',
            'extension=pdo_sqlite',
            'extension=openssl',
            'extension=mbstring',
            'extension=gd',
            '; user comment',
            '',
        ].join('\n');
        fs.writeFileSync(iniPath, stale, 'utf8');
        paths.getPhpIniPath.mockReturnValueOnce(iniPath);

        ensurePhpIni();

        const updated = fs.readFileSync(iniPath, 'utf8');
        expect((updated.match(/extension_dir/g) || []).length).toBe(1);
        expect(updated).not.toContain('/old/install/ext');
        expect(updated).toMatch(/extension_dir = "[^"]*\/ext"$/m);
        expect(updated).toContain('; user comment');
    });
});
