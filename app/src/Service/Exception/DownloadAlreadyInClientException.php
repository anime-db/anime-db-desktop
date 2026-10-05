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
 * Thrown by {@see \App\Service\Download\QbittorrentDownloadService::enqueueTo()} when
 * qBittorrent already holds a torrent with the same v1 infoHash (under any tag) but no `downloads`
 * row exists for it — e.g. after "Unlink", a deleted anime card, or a failed `torrents/delete`.
 * qBittorrent would answer `torrents/add` for such a duplicate with a 409, so nothing is submitted
 * and no row is written.
 */
final class DownloadAlreadyInClientException extends \RuntimeException
{
    public function __construct(public readonly string $infoHash)
    {
        parent::__construct(\sprintf('Torrent "%s" is already present in qBittorrent without a download row.', $infoHash));
    }
}
