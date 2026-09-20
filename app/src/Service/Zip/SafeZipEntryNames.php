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

namespace App\Service\Zip;

/**
 * Zip-slip defence in depth shared by every ZIP-derived import path in this app — originally
 * {@see \App\Service\Plugin\ZipPluginInstaller} (issue #248), extracted here so
 * {@see \App\Service\Import\CatalogStageService} (issue #669) can reuse the exact same check
 * instead of keeping its own copy. An untrusted ZIP archive could contain entry names with `..`
 * segments or absolute paths designed to write outside the intended extraction directory. Modern
 * {@see \ZipArchive::extractTo()} already rejects those, but that behaviour is not part of its
 * documented contract, so entry names are validated explicitly before extraction rather than
 * relying on it.
 */
final class SafeZipEntryNames
{
    /**
     * Returns the first unsafe entry name found in $zip, or null if every entry is safe.
     */
    public static function findUnsafe(\ZipArchive $zip): ?string
    {
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }

            $isAbsolute = str_starts_with($name, '/') || str_starts_with($name, '\\') || preg_match('#^[A-Za-z]:#', $name) === 1;
            $hasParentTraversal = \in_array('..', explode('/', str_replace('\\', '/', $name)), true);

            if ($isAbsolute || $hasParentTraversal) {
                return $name;
            }
        }

        return null;
    }
}
