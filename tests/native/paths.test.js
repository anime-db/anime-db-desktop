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
    app: { getPath: jest.fn() },
}));

const path = require('path');
const { app } = require('electron');
const paths = require('../../native/paths');

const USER_DATA = '/home/user/AnimeDB';

beforeEach(() => {
    app.getPath.mockReturnValue(USER_DATA);
});

test('getUserDataDir returns the electron userData path', () => {
    expect(paths.getUserDataDir()).toBe(USER_DATA);
});

test('getDbPath returns data.db inside userData', () => {
    expect(paths.getDbPath()).toBe(path.join(USER_DATA, 'data.db'));
});

test('getQueueDbPath returns queue.db inside userData', () => {
    expect(paths.getQueueDbPath()).toBe(path.join(USER_DATA, 'queue.db'));
});

test('getMeilisearchDataDir returns meilisearch/ inside userData', () => {
    expect(paths.getMeilisearchDataDir()).toBe(path.join(USER_DATA, 'meilisearch'));
});

test('getPhpIniDir returns the userData dir itself', () => {
    expect(paths.getPhpIniDir()).toBe(USER_DATA);
});

test('getPhpIniPath returns php.ini inside userData', () => {
    expect(paths.getPhpIniPath()).toBe(path.join(USER_DATA, 'php.ini'));
});

test('getAppRootDir returns the app/ directory (absolute path ending in app)', () => {
    const result = paths.getAppRootDir();
    expect(path.isAbsolute(result)).toBe(true);
    expect(path.basename(result)).toBe('app');
});

test('getRuntimeDir returns var/ inside userData', () => {
    expect(paths.getRuntimeDir()).toBe(path.join(USER_DATA, 'var'));
});

test('getMeilisearchKeyPath returns meilisearch-key.txt inside userData', () => {
    expect(paths.getMeilisearchKeyPath()).toBe(path.join(USER_DATA, 'meilisearch-key.txt'));
});

test('getConfigPath returns config.json inside userData', () => {
    expect(paths.getConfigPath()).toBe(path.join(USER_DATA, 'config.json'));
});

test('getPluginsConfigPath returns plugins.json inside userData', () => {
    expect(paths.getPluginsConfigPath()).toBe(path.join(USER_DATA, 'plugins.json'));
});

test('getNativeTranslationsDir returns the bundled translations directory', () => {
    const result = paths.getNativeTranslationsDir();
    expect(path.isAbsolute(result)).toBe(true);
    expect(path.basename(result)).toBe('translations');
});

test('getNativeTranslationsOverlayDir returns native-translations/ inside userData', () => {
    expect(paths.getNativeTranslationsOverlayDir()).toBe(path.join(USER_DATA, 'native-translations'));
});

test('getStatePath returns state.json inside userData', () => {
    expect(paths.getStatePath()).toBe(path.join(USER_DATA, 'state.json'));
});

test('getBackupsDir returns backups/ inside userData', () => {
    expect(paths.getBackupsDir()).toBe(path.join(USER_DATA, 'backups'));
});

test('getMarketRegistryCachePath returns market-registry-cache.json inside userData', () => {
    expect(paths.getMarketRegistryCachePath()).toBe(path.join(USER_DATA, 'market-registry-cache.json'));
});

test('getMarketSnapshotCachePath returns market-snapshot-cache.json inside userData', () => {
    expect(paths.getMarketSnapshotCachePath()).toBe(path.join(USER_DATA, 'market-snapshot-cache.json'));
});

test('getMarketRefreshLockPath returns market-refresh.lock inside userData', () => {
    expect(paths.getMarketRefreshLockPath()).toBe(path.join(USER_DATA, 'market-refresh.lock'));
});

test('all path functions reflect a different userData value (fallback scenario)', () => {
    const OTHER = '/other/AppData/AnimeDB';
    app.getPath.mockReturnValue(OTHER);
    expect(paths.getUserDataDir()).toBe(OTHER);
    expect(paths.getDbPath()).toBe(path.join(OTHER, 'data.db'));
    expect(paths.getQueueDbPath()).toBe(path.join(OTHER, 'queue.db'));
    expect(paths.getRuntimeDir()).toBe(path.join(OTHER, 'var'));
    expect(paths.getMeilisearchDataDir()).toBe(path.join(OTHER, 'meilisearch'));
    expect(paths.getMeilisearchKeyPath()).toBe(path.join(OTHER, 'meilisearch-key.txt'));
});
