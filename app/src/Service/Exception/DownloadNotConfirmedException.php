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
 * Thrown by {@see \App\Service\Download\QbittorrentDownloadService::enqueueTo()} when a torrent
 * just submitted to qBittorrent's `torrents/add` could not be found back by its v1 infoHash in
 * `torrents/info` within a bounded number of retries — qBittorrent 5.2.3's `torrents/add`
 * endpoint answers 200 for some malformed input too (see the class docblock), so a 200 response
 * alone does not prove the torrent was actually added. No `downloads` row is written.
 */
final class DownloadNotConfirmedException extends \RuntimeException
{
    public function __construct(public readonly string $infoHash)
    {
        parent::__construct(\sprintf('qBittorrent did not report torrent "%s" back after it was submitted.', $infoHash));
    }
}
