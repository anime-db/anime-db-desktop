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

namespace App\Service\Storage;

/**
 * Reads the modification time of a storage root from the filesystem. A separate service so that
 * the moments at which the filesystem is touched (the background scan tick, the snapshot taken by
 * {@see ScanStorageService}) can be replaced in tests.
 */
class StorageRootInspector
{
    /**
     * The root's mtime, or null when it is not readable or its mtime cannot be read. The stat cache
     * is dropped first: callers run in the long-lived messenger consumer, where PHP would otherwise
     * return the mtime cached by an earlier call.
     */
    public function modifiedAt(string $path): ?int
    {
        clearstatcache(true, $path);

        if (!is_readable($path)) {
            return null;
        }

        $mtime = filemtime($path);

        return $mtime === false ? null : $mtime;
    }
}
