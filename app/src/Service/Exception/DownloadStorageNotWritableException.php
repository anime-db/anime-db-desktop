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

use App\Entity\Enum\StorageType;

/**
 * Thrown by {@see \App\Service\Download\QbittorrentDownloadService::enqueueTo()} when the target
 * Storage's type is not writable ({@see StorageType::isWritable()}) — e.g. a read-only external
 * or a video-file storage can never hold an in-progress download. Nothing is submitted to
 * qBittorrent and no `downloads` row is written.
 */
final class DownloadStorageNotWritableException extends \RuntimeException
{
    public function __construct(public readonly StorageType $type)
    {
        parent::__construct(\sprintf('Storage type "%s" is not writable and cannot receive a download.', $type->value));
    }
}
