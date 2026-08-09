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

namespace App\Service\Exception;

/**
 * Thrown by {@see \App\Service\Download\FreeSpaceChecker} when a `.torrent` file's declared total
 * size (plus overhead) does not fit the free space on the configured downloads root's volume —
 * a synchronous, enqueue-time precheck (issue #348). Only ever thrown for a `.torrent`-file
 * source, where the size is known up front; a magnet's size is unknown until qBittorrent has
 * fetched its metadata, so that case is handled asynchronously by
 * {@see \App\Service\Download\DownloadCompletionPoller} instead (pause + failed status, not this
 * exception — there is no calling UI context left by then).
 */
final class InsufficientDiskSpaceException extends \RuntimeException
{
}
