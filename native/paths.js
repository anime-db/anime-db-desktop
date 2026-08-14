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
const path = require('path');

const userDataDir = () => app.getPath('userData');
const appRootDir  = () => path.join(__dirname, '..', 'app');

module.exports = {
    getUserDataDir:        userDataDir,
    getDbPath:             () => path.join(userDataDir(), 'data.db'),
    getQueueDbPath:        () => path.join(userDataDir(), 'queue.db'),
    getMeilisearchDataDir: () => path.join(userDataDir(), 'meilisearch'),
    getPhpIniDir:          () => userDataDir(),
    getPhpIniPath:         () => path.join(userDataDir(), 'php.ini'),
    getAppRootDir:         appRootDir,
    getRuntimeDir:         () => path.join(userDataDir(), 'var'),
    getMeilisearchKeyPath: () => path.join(userDataDir(), 'meilisearch-key.txt'),
    // qbittorrent-nox's own "--profile=<dir>" layout (see qBittorrent's CustomProfile,
    // src/base/profile_p.cpp): it appends "qBittorrent/config/qBittorrent.<ext>" to the profile
    // root itself, ".ini" on Windows (".conf" on Linux/macOS is not relevant — this app is
    // Windows-only, see .claude-docs/architecture.md).
    getQbittorrentProfileDir: () => path.join(userDataDir(), 'qbittorrent'),
    getQbittorrentConfigPath: () => path.join(userDataDir(), 'qbittorrent', 'qBittorrent', 'config', 'qBittorrent.ini'),
    getMediaDir:           () => path.join(userDataDir(), 'media'),
    getConfigPath:         () => path.join(userDataDir(), 'config.json'),
    getPluginsConfigPath:  () => path.join(userDataDir(), 'plugins.json'),
    getPluginsDir:         () => path.join(userDataDir(), 'plugins'),
    getStatePath:          () => path.join(userDataDir(), 'state.json'),
    getBackupsDir:         () => path.join(userDataDir(), 'backups'),
    getMarketRegistryCachePath: () => path.join(userDataDir(), 'market-registry-cache.json'),
};
