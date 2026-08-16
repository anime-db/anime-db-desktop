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

/**
 * Serializes concurrent access to the plugin filesystem layer (`installed-plugins.php` and the
 * plugin directories themselves) across FrankenPHP worker threads/processes, the same problem
 * {@see PluginsConfigStore} already solves for `plugins.json` — a dedicated lock file, never
 * replaced by `rename()`, so `flock()` keeps serializing every acquirer regardless of how many
 * times the target file itself gets swapped.
 *
 * Unlike {@see PluginsConfigStore::acquireLock()}, this blocks until the lock is free rather than
 * failing fast: reconcile/install/update/remove are the operation themselves (not arbitrary
 * caller code running behind someone else's lock), and waiting out another install already in
 * flight is the entire point, not a hazard to bound.
 *
 * Reentrant per lock path within a single process: {@see InstalledPluginsRegistry::reconcile()}
 * acquires the same lock {@see ZipPluginInstaller::install()}/`update()` and
 * {@see PluginRemover::remove()} already hold for the whole operation they
 * call it from. `flock()` locks are associated with the open file description, not the process,
 * so a second independent `fopen()` + `flock()` on the same path from the same process would
 * otherwise block on itself; a per-path depth counter here lets the outer acquirer's already-open
 * handle cover the nested call instead of opening a second, self-blocking one.
 */
final class PluginFileLock
{
    /** @var array<string, resource> */
    private static array $handles = [];

    /** @var array<string, int> */
    private static array $depths = [];

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public static function synchronized(string $lockPath, callable $callback): mixed
    {
        self::acquire($lockPath);

        try {
            return $callback();
        } finally {
            self::release($lockPath);
        }
    }

    private static function acquire(string $lockPath): void
    {
        $depth = self::$depths[$lockPath] ?? 0;

        if ($depth === 0) {
            $directory = \dirname($lockPath);
            if (!is_dir($directory) && !mkdir($directory, recursive: true) && !is_dir($directory)) {
                throw new \RuntimeException(\sprintf('Unable to create directory "%s".', $directory));
            }

            $handle = fopen($lockPath, 'c');
            if ($handle === false) {
                throw new \RuntimeException(\sprintf('Unable to open lock file "%s".', $lockPath));
            }

            if (!flock($handle, \LOCK_EX)) {
                fclose($handle);

                throw new \RuntimeException(\sprintf('Unable to acquire lock on "%s".', $lockPath));
            }

            self::$handles[$lockPath] = $handle;
        }

        self::$depths[$lockPath] = $depth + 1;
    }

    private static function release(string $lockPath): void
    {
        $depth = (self::$depths[$lockPath] ?? 1) - 1;

        if ($depth > 0) {
            self::$depths[$lockPath] = $depth;

            return;
        }

        $handle = self::$handles[$lockPath] ?? null;
        if ($handle !== null) {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }

        unset(self::$handles[$lockPath], self::$depths[$lockPath]);
    }
}
