<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Status of a single (infoHash, anime) pairing row in {@see \App\Entity\Download}. A single
 * flag doubling as "download finished AND folder linked to the catalog entry" — the poller
 * only transitions Pending => Completed once both have happened, so a row's status alone tells
 * whether {@see \AnimeDb\PluginContracts\Download\DownloadCompletedEvent} has already fired for
 * it (see Download::markCompleted()).
 *
 * Failed (issue #348) is the async counterpart of
 * {@see \App\Service\Exception\InsufficientDiskSpaceException}: a magnet's size is only known
 * after qBittorrent has fetched its metadata, so a not-enough-free-space verdict for it can only
 * be discovered later, by {@see \App\Service\Download\DownloadCompletionPoller} — there is no
 * calling UI context left to throw into by then, so the row is marked Failed (and the torrent
 * paused) instead.
 */
enum DownloadStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
}
