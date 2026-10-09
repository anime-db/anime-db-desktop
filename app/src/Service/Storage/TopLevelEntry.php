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

use App\Entity\Storage;
use App\Service\Media\MediaExtensions;

/**
 * Which top-level entries of a storage root the scanner sees: hidden names (a leading ".", which
 * includes the `.anime-db` downloads directory) are skipped, and a plain file counts only when it
 * is a video. The one predicate shared by {@see ScanStorageService} and {@see ManualLinkService},
 * so a manual link can never point at an entry the next scan would not find.
 */
final class TopLevelEntry
{
    public static function isVisibleToScanner(\SplFileInfo $entry): bool
    {
        if (str_starts_with($entry->getFilename(), '.')) {
            return false;
        }

        return !$entry->isFile() || \in_array(strtolower($entry->getExtension()), MediaExtensions::VIDEO, true);
    }

    /**
     * Whether the top-level entry $name is still in the storage root. $name is a bare name: anything
     * with a separator or a dot segment is not a top-level entry. Existence only — what the scanner
     * would show of it is {@see self::isVisibleToScanner()}'s business.
     */
    public static function exists(Storage $storage, string $name): bool
    {
        $root = $storage->getPath();
        if ($root === null || $name === '' || $name === '.' || $name === '..' || preg_match('/[\\\\\/]/', $name) === 1) {
            return false;
        }

        return file_exists(rtrim($root, '\\/').\DIRECTORY_SEPARATOR.$name);
    }
}
