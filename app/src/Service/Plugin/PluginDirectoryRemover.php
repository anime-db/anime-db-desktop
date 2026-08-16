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

namespace App\Service\Plugin;

use App\Service\Plugin\Exception\PluginDirectoryRemovalException;

/**
 * Removes a plugin directory (or any of the installer's staging/backup directories) reliably,
 * shared by {@see ZipPluginInstaller} and {@see PluginRemover} instead of each keeping its own
 * copy that ignored failures.
 *
 * `$dir` is `rename()`d out of place first — into a `.removing-<random>` sibling — before its
 * contents are deleted recursively, so a directory at a well-known plugin path (still scanned by
 * {@see InstalledPluginsRegistry::reconcile()} while it is being torn down) either fully exists or
 * is fully gone from that path, never observed half-deleted. The rename is best-effort: if it
 * fails (e.g. a file inside is held open, which also blocks a plain `rename()` on Windows),
 * deletion falls back to removing `$dir` in place.
 *
 * Each `unlink()`/`rmdir()` return value is checked, and the whole removal retries a bounded
 * number of times with a short delay, the same pattern {@see PluginCacheWarmer::removeDirectory()}
 * already uses for its own throwaway directory — but unlike that one, a failure here is not
 * swallowed: after every retry is exhausted it throws {@see PluginDirectoryRemovalException}
 * rather than silently leaving a partially removed plugin directory (with e.g. `manifest.json`
 * still present) for the next {@see InstalledPluginsRegistry::reconcile()} to pick back up.
 */
final class PluginDirectoryRemover
{
    private const int MAX_ATTEMPTS = 5;
    private const int RETRY_DELAY_MICROSECONDS = 200_000;

    /**
     * @throws PluginDirectoryRemovalException
     */
    public static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $stagedDir = $dir.'.removing-'.bin2hex(random_bytes(8));
        $target = @rename($dir, $stagedDir) ? $stagedDir : $dir;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; ++$attempt) {
            if (self::tryRemove($target)) {
                return;
            }

            if ($attempt < self::MAX_ATTEMPTS) {
                usleep(self::RETRY_DELAY_MICROSECONDS);
            }
        }

        throw new PluginDirectoryRemovalException($target);
    }

    private static function tryRemove(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }

        $entries = scandir($dir);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.\DIRECTORY_SEPARATOR.$entry;
            if (is_dir($path) && !is_link($path)) {
                if (!self::tryRemove($path)) {
                    return false;
                }
            } elseif (!@unlink($path)) {
                return false;
            }
        }

        return @rmdir($dir);
    }
}
