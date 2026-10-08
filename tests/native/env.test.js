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

jest.mock('electron', () => ({
    app: { getVersion: jest.fn(() => '1.2.3') },
}));
jest.mock('../../native/paths', () => ({
    getAppRootDir:        jest.fn(() => '/fake/app'),
    getDbPath:            jest.fn(() => '/fake/userData/data.db'),
    getQueueDbPath:       jest.fn(() => '/fake/userData/queue.db'),
    getPhpIniDir:         jest.fn(() => '/fake/userData'),
    getRuntimeDir:        jest.fn(() => '/fake/userData/var'),
    getMediaDir:          jest.fn(() => '/fake/userData/media'),
    getImportStagingDir:  jest.fn(() => '/fake/userData/import-staging'),
    getImportRejectionPath: jest.fn(() => '/fake/userData/import-rejected.json'),
    getImportAppliedPath: jest.fn(() => '/fake/userData/import-applied.json'),
    getImportV1ReportPath: jest.fn(() => '/fake/userData/import-v1-report.json'),
    getConfigPath:        jest.fn(() => '/fake/userData/config.json'),
    getPluginsConfigPath: jest.fn(() => '/fake/userData/plugins.json'),
    getPluginsDir:        jest.fn(() => '/fake/userData/plugins'),
    getNativeTranslationsDir:        jest.fn(() => '/fake/app/native/translations'),
    getNativeTranslationsOverlayDir: jest.fn(() => '/fake/userData/native-translations'),
    getMarketRegistryCachePath: jest.fn(() => '/fake/userData/market-registry-cache.json'),
    getMarketSnapshotCachePath: jest.fn(() => '/fake/userData/market-snapshot-cache.json'),
    getMarketRefreshLockPath:   jest.fn(() => '/fake/userData/market-refresh.lock'),
    getBackupsDir:        jest.fn(() => '/fake/userData/backups'),
    getFfprobeBinPath:    jest.fn(() => '/fake/bin/ffprobe/ffprobe.exe'),
}));
jest.mock('../../native/config', () => ({
    getOrCreateAppSecret: jest.fn(() => 'a'.repeat(64)),
}));

const { buildCommonEnv, buildWebWorkerEnv } = require('../../native/supervisor/env');

const CONTEXT = { meiliPort: 7700, meiliKey: 'test-key', qbittorrentPort: 9999, appPort: 8000 };

describe('buildCommonEnv', () => {
    test('includes the full set of user-data paths/addresses shared by every PHP process', () => {
        const env = buildCommonEnv(CONTEXT);
        expect(env.PLUGINS_DIR).toBe('/fake/userData/plugins');
        expect(env.PLUGINS_CONFIG_PATH).toBe('/fake/userData/plugins.json');
        expect(env.NATIVE_TRANSLATIONS_DIR).toBe('/fake/app/native/translations');
        expect(env.NATIVE_TRANSLATIONS_OVERLAY_DIR).toBe('/fake/userData/native-translations');
        expect(env.MARKET_REGISTRY_CACHE_PATH).toBe('/fake/userData/market-registry-cache.json');
        expect(env.MARKET_SNAPSHOT_CACHE_PATH).toBe('/fake/userData/market-snapshot-cache.json');
        expect(env.MARKET_REFRESH_LOCK_PATH).toBe('/fake/userData/market-refresh.lock');
        expect(env.QBITTORRENT_URL).toBe('http://127.0.0.1:9999');
        // No oauthCallbackOrigin/oauthCallbackFixedPort set on CONTEXT — this is the fallback path
        // (fixed port 41813 busy, issue #871), which reproduces the pre-#871 behavior exactly:
        // OAUTH_CALLBACK_ORIGIN still derives from appPort, and the fixed-port flag is absent.
        expect(env.OAUTH_CALLBACK_ORIGIN).toBe('http://127.0.0.1:8000');
        expect(env.OAUTH_CALLBACK_FIXED_PORT).toBeUndefined();
        expect(env.MEDIA_DIR).toBe('/fake/userData/media');
        expect(env.IMPORT_STAGING_DIR).toBe('/fake/userData/import-staging');
        expect(env.IMPORT_V1_REPORT_PATH).toBe('/fake/userData/import-v1-report.json');
        expect(env.IMPORT_REJECTION_PATH).toBe('/fake/userData/import-rejected.json');
        expect(env.IMPORT_APPLIED_PATH).toBe('/fake/userData/import-applied.json');
        expect(env.CONFIG_PATH).toBe('/fake/userData/config.json');
        expect(env.APP_RUNTIME_DIR).toBe('/fake/userData/var');
        expect(env.BACKUPS_DIR).toBe('/fake/userData/backups');
        expect(env.FFPROBE_BIN).toBe('/fake/bin/ffprobe/ffprobe.exe');
    });

    test('does not include worker-only ports', () => {
        const env = buildCommonEnv(CONTEXT);
        expect(env.APP_PORT).toBeUndefined();
        expect(env.WS_PORT).toBeUndefined();
    });

    // SAFE_MODE (issue #403) is what makes Kernel::installedPluginsRegistry() report no
    // installed plugins — every PHP process must receive it identically, or the plugin list
    // would disagree between e.g. migrations and the web worker within the same session.
    test('includes SAFE_MODE=1 when context.safeMode is true', () => {
        const env = buildCommonEnv({ ...CONTEXT, safeMode: true });
        expect(env.SAFE_MODE).toBe('1');
    });

    test('omits SAFE_MODE when context.safeMode is false or absent', () => {
        expect(buildCommonEnv({ ...CONTEXT, safeMode: false }).SAFE_MODE).toBeUndefined();
        expect(buildCommonEnv(CONTEXT).SAFE_MODE).toBeUndefined();
    });
});

describe('buildWebWorkerEnv', () => {
    test('adds APP_PORT/WS_PORT as strings on top of the common env, without mutating it', () => {
        const common = buildCommonEnv(CONTEXT);
        const webEnv = buildWebWorkerEnv(CONTEXT, 9000);

        expect(webEnv.APP_PORT).toBe('8000');
        expect(webEnv.WS_PORT).toBe('9000');
        expect(webEnv).toMatchObject(common);
        expect(common.APP_PORT).toBeUndefined();
    });

    // Pre-#871 fallback path: without an explicit oauthCallbackOrigin, OAUTH_CALLBACK_ORIGIN still
    // derives from context.appPort — same value APP_PORT uses — since this is only reached once
    // the fixed-port listener on 41813 could not bind (see env.js#buildCommonEnv).
    test('derives APP_PORT from the same context.appPort as OAUTH_CALLBACK_ORIGIN when no fixed origin is set (fallback)', () => {
        const webEnv = buildWebWorkerEnv({ ...CONTEXT, appPort: 12345 }, 9000);

        expect(webEnv.APP_PORT).toBe('12345');
        expect(webEnv.OAUTH_CALLBACK_ORIGIN).toBe('http://127.0.0.1:12345');
    });

    // Issue #871: once the fixed-port OAuth listener is bound, OAUTH_CALLBACK_ORIGIN is the fixed
    // port 41813 regardless of APP_PORT — the two are intentionally independent from this point on.
    test('keeps OAUTH_CALLBACK_ORIGIN fixed and independent of APP_PORT once oauthCallbackOrigin is set', () => {
        const webEnv = buildWebWorkerEnv({
            ...CONTEXT,
            appPort: 12345,
            oauthCallbackOrigin: 'http://127.0.0.1:41813',
            oauthCallbackFixedPort: true,
        }, 9000);

        expect(webEnv.APP_PORT).toBe('12345');
        expect(webEnv.OAUTH_CALLBACK_ORIGIN).toBe('http://127.0.0.1:41813');
        expect(webEnv.OAUTH_CALLBACK_FIXED_PORT).toBe('1');
    });
});

describe('OAUTH_CALLBACK_FIXED_PORT (issue #871)', () => {
    test('is "1" when oauthCallbackFixedPort is true, regardless of appPort', () => {
        const env = buildCommonEnv({ ...CONTEXT, oauthCallbackFixedPort: true, oauthCallbackOrigin: 'http://127.0.0.1:41813' });
        expect(env.OAUTH_CALLBACK_FIXED_PORT).toBe('1');
        expect(env.OAUTH_CALLBACK_ORIGIN).toBe('http://127.0.0.1:41813');
    });

    test('is "0" when oauthCallbackFixedPort is false, and still set on a context without appPort (pre-web-worker processes)', () => {
        const contextWithoutAppPort = { ...CONTEXT };
        delete contextWithoutAppPort.appPort;
        const env = buildCommonEnv({ ...contextWithoutAppPort, oauthCallbackFixedPort: false });
        expect(env.OAUTH_CALLBACK_FIXED_PORT).toBe('0');
        expect(env.OAUTH_CALLBACK_ORIGIN).toBeUndefined();
    });

    test('is omitted when oauthCallbackFixedPort is not provided', () => {
        const env = buildCommonEnv(CONTEXT);
        expect(env.OAUTH_CALLBACK_FIXED_PORT).toBeUndefined();
    });
});
