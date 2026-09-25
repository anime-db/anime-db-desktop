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
 * Thrown by {@see \App\Service\Download\AnimeDownloadLinker} when the (storage, relative path)
 * pair a completed download would be linked to is already held by another Anime — the pair is
 * unique in the `anime` table, so writing it would violate UNIQ_ANIME_STORAGE_STORAGE_PATH.
 */
final class DownloadStoragePathConflictException extends \RuntimeException
{
    public function __construct(
        public readonly int $occupyingAnimeId,
        public readonly string $storagePath,
    ) {
        parent::__construct(sprintf('Storage path "%s" is already linked to anime #%d.', $storagePath, $occupyingAnimeId));
    }
}
