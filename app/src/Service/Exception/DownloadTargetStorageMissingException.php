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
 * Thrown by {@see \App\Service\Download\AnimeDownloadLinker::link()} when the download's
 * `target_storage_id` is null — reachable not just for a malformed row, but for a Pending
 * download whose Storage was deleted while it was still in flight: `target_storage_id` is
 * `ON DELETE SET NULL` (see Download entity), so the row survives the deletion with no storage
 * to link into. There is nothing to retry here, unlike a transient failure: the storage is gone
 * for good, so the caller must fail the download instead of leaving it Pending forever.
 */
final class DownloadTargetStorageMissingException extends \RuntimeException
{
    public function __construct(public readonly string $infoHash)
    {
        parent::__construct(\sprintf('Download "%s" has no target storage; it was likely deleted while the download was still in flight.', $infoHash));
    }
}
