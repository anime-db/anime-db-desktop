<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */

/*
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

declare(strict_types=1);

namespace App\Service\Download;

/**
 * Abstracts the filesystem checks/writes {@see QbittorrentDownloadService::enqueueTo()} needs
 * before handing a save-path to qBittorrent, the same shape as {@see FreeSpaceProvider} for the
 * same reason: a Windows path like "D:\Anime" never actually exists on the Linux CI runner this
 * test suite runs on, so a real implementation cannot be exercised there directly.
 */
interface DownloadStorageFilesystem
{
    /** Whether $path exists on disk right now. */
    public function pathExists(string $path): bool;

    /**
     * Creates $path if it does not already exist and marks it hidden (Windows `attrib +H`) —
     * used to create and hide a storage's `.anime-db` incoming directory before any save-path
     * under it is handed to qBittorrent.
     */
    public function ensureHiddenDirectoryExists(string $path): void;
}
