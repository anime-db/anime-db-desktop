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

namespace App\Service\Download;

/**
 * The only production {@see DownloadStorageFilesystem}: thin wrappers around `is_dir()`,
 * `mkdir()` and, on Windows, the same `attrib +H` call {@see \App\Service\Storage\StorageMarkerService::writeMarker()}
 * uses to hide its own marker file — the app only ships for Windows (see
 * .claude-docs/architecture.md), and the test suite runner (ubuntu-latest) has no `attrib`
 * either, so hiding is skipped there the same way the marker service already does.
 */
final class NativeDownloadStorageFilesystem implements DownloadStorageFilesystem
{
    public function pathExists(string $path): bool
    {
        return is_dir($path);
    }

    public function ensureHiddenDirectoryExists(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, recursive: true);
        }

        if (\PHP_OS_FAMILY === 'Windows') {
            exec('attrib +H '.escapeshellarg($path));
        }
    }
}
