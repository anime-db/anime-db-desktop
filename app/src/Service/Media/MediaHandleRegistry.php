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

namespace App\Service\Media;

use AnimeDb\PluginContracts\Media\MediaFile;

/**
 * Remembers which record and absolute path every issued MediaFile handle stands for. Keyed by
 * the handle object itself (never by relativePath: two records can both hold `01.mkv`) in a
 * WeakMap, so an entry disappears together with its handle in the long-lived web worker.
 */
final class MediaHandleRegistry
{
    /** @var \WeakMap<MediaFile, MediaHandle> */
    private \WeakMap $handles;

    public function __construct()
    {
        $this->handles = new \WeakMap();
    }

    public function register(MediaFile $file, int $animeId, string $absolutePath): void
    {
        $this->handles[$file] = new MediaHandle($animeId, $absolutePath);
    }

    /** Returns null for a MediaFile that was not issued by MediaLibrary. */
    public function get(MediaFile $file): ?MediaHandle
    {
        return $this->handles[$file] ?? null;
    }
}
