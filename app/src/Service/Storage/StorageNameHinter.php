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
 * Suggests a storage name for a path chosen in the new-storage form.
 *
 * The filesystem and processes are reached only for a drive root (`D:` or `D:\`). Anything else,
 * a folder or a UNC path, is parsed as a string: touching a UNC path (`\\host\share`) makes the
 * machine connect to that host and hand over the user's credentials. Nothing may be added above
 * the drive-root check that reads the filesystem.
 */
final class StorageNameHinter
{
    private const string DRIVE_ROOT_PATTERN = '/^([A-Za-z]):\\\\?$/';

    public function __construct(
        private readonly AutorunInfReader $autorunReader,
        private readonly VolumeProbeInterface $volumeProbe,
        /** sprintf() format of the drive root directory with the letter; overridden in tests. */
        private readonly string $driveRootFormat = '%s:\\',
    ) {
    }

    public function hint(string $path): ?string
    {
        $path = trim($path);

        if (preg_match(self::DRIVE_ROOT_PATTERN, $path, $matches) === 1) {
            return $this->driveRootName($matches[1]);
        }

        $segments = array_values(array_filter(
            preg_split('~[\\\\/]+~', $path) ?: [],
            static fn (string $segment): bool => $segment !== '',
        ));

        // `\\srv\share` -> `share`; a bare `\\srv` has no name of its own.
        if (preg_match('~^[\\\\/]{2}~', $path) === 1 && \count($segments) < 2) {
            return null;
        }

        $name = end($segments);

        return $name === false || preg_match('/^[A-Za-z]:$/', $name) === 1 ? null : $name;
    }

    private function driveRootName(string $letter): ?string
    {
        $file = sprintf($this->driveRootFormat, strtoupper($letter)).'autorun.inf';
        if (is_file($file)) {
            $contents = @file_get_contents($file);
            $label = \is_string($contents) ? $this->autorunReader->getValue($contents, 'autorun', 'label') : null;
            if ($label !== null) {
                return $label;
            }
        }

        $label = $this->volumeProbe->probe($letter)?->label;
        if ($label === null) {
            return null;
        }

        $label = trim($label);

        return $label === '' ? null : $label;
    }
}
