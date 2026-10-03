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

namespace App\Service\Exception;

/**
 * Thrown by {@see \App\Service\Download\QbittorrentDownloadService::enqueueTo()} when the target
 * Storage is not reachable: either its path does not exist on disk, or the `desktop.ini` marker
 * at its root does not carry this Storage's own id (a different storage occupies that path now,
 * or the path was never marked). Nothing is submitted to qBittorrent and no `downloads` row is
 * written.
 */
final class DownloadStorageUnavailableException extends \RuntimeException
{
    public function __construct(public readonly int $storageId, public readonly string $path)
    {
        parent::__construct(\sprintf('Storage #%d at "%s" is not available: its path does not exist, or the desktop.ini marker there does not match it.', $storageId, $path));
    }
}
