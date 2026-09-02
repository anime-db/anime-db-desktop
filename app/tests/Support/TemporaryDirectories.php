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

namespace App\Tests\Support;

/**
 * Tracks every directory a test creates under the system temp directory and removes the whole
 * set — recursively — from that test's own tearDown(). A fixed list of paths would silently stop
 * covering a test as soon as it grew a second fixture directory; tracking what was actually
 * created keeps the cleanup correct without anyone having to update it by hand (issue #569).
 */
trait TemporaryDirectories
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    private function createTemporaryDirectory(string $prefix): string
    {
        $directory = sys_get_temp_dir().'/'.$prefix.uniqid();
        mkdir($directory, recursive: true);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    private function removeTemporaryDirectories(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->removeDirectory($directory);
        }

        $this->temporaryDirectories = [];
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
