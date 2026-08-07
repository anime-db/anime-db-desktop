<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Service;

use App\Service\Exception\AppConfigStoreException;
use App\Service\Exception\AppConfigStoreLockedException;

/**
 * Reads and writes %AppData%/config.json, the single shared file holding user-facing app
 * settings (locale, pagination mode, default search plugin, proxy, ...) and the appSecret
 * native/config.js provisions on first run.
 *
 * At least two PHP processes can write it concurrently: the app's own FrankenPHP worker pool
 * (Caddyfile spins up more than one worker per server block, and there are two server blocks —
 * APP_PORT and WS_PORT — sharing this file) plus the separate messenger-consumer process. A bare
 * temp-file + rename() only protects the integrity of a single write, not against a lost update
 * between two writers (both read, each changes its own key, the second rename() silently
 * discards the first). update() closes that gap by holding an exclusive flock() for the whole
 * read -> modify -> serialize -> write-temp -> rename cycle, the same approach
 * {@see Plugin\PluginsConfigStore} already uses for plugins.json (issue #219).
 * Readers need no lock: the atomic rename() alone guarantees they see a consistent version of
 * the file, wholly old or wholly new.
 *
 * The lock acquire is non-blocking with a short bounded retry (same trade-off as
 * PluginsConfigStore, issue #340): a writer that loses the race fails fast with
 * {@see AppConfigStoreLockedException} instead of queueing indefinitely behind another writer.
 *
 * Invariant this lock relies on: native/config.js only ever writes config.json on first run
 * (getOrCreateAppSecret()/getOrCreateLocale(), called from native/supervisor/frankenphp.js and
 * native/supervisor/messenger-consumer.js *before* the corresponding PHP process is spawned).
 * After that point Node only reads the file. This lock is PHP-only (flock() has no Node
 * counterpart); if Node ever started writing config.json at runtime alongside a live PHP
 * process, this store would stop being sufficient and a cross-runtime lock would be needed.
 *
 * AppSettingsProvider and ProxyConfigProvider are the only callers — every PHP write to
 * config.json must go through this class, never a standalone temp-file + rename() of its own,
 * or the lock stops actually excluding anyone.
 */
final class AppConfigStore
{
    private const int LOCK_ACQUIRE_MAX_ATTEMPTS = 10;
    private const int LOCK_ACQUIRE_RETRY_DELAY_MICROSECONDS = 5_000;

    public function __construct(private readonly string $configPath)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        if (!is_file($this->configPath)) {
            return [];
        }

        $contents = file_get_contents($this->configPath);
        if ($contents === false) {
            return [];
        }

        return $this->decode($contents);
    }

    /**
     * Atomically reads, modifies and writes back config.json. $modifier receives the current
     * config array and returns the array to persist; it runs under the lock, so it must only
     * manipulate the array in memory — no slow I/O inside it, or it would hold the lock (and
     * block every other writer) for longer than necessary.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $modifier
     *
     * @throws AppConfigStoreLockedException
     */
    public function update(callable $modifier): void
    {
        $directory = \dirname($this->configPath);
        if (!is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        // Locking config.json itself would not work: rename() below swaps in a new inode on
        // every write, so a second writer that opened its handle *before* that rename ends up
        // holding a lock on the now-orphaned old inode — its flock() no longer excludes anyone,
        // and it silently overwrites the first writer's update with stale data it read earlier.
        // A dedicated lock file, never replaced by rename(), keeps the same inode across every
        // acquisition, so flock() actually serializes writers.
        $lockHandle = fopen($this->configPath.'.lock', 'c');
        if ($lockHandle === false) {
            throw new AppConfigStoreException(\sprintf('Unable to open lock file for "%s".', $this->configPath));
        }

        try {
            $this->acquireLock($lockHandle);

            $config = $modifier($this->read());

            $encoded = json_encode($config, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                throw new AppConfigStoreException(\sprintf('Unable to encode "%s" as JSON.', $this->configPath));
            }

            // rename() on Windows overwrites an existing destination (unlike a bare POSIX
            // rename() pre-8.0), so this stays atomic on the app's only supported platform.
            $tmpPath = $this->configPath.'.tmp';
            if (file_put_contents($tmpPath, $encoded) === false) {
                throw new AppConfigStoreException(\sprintf('Unable to write "%s".', $tmpPath));
            }

            rename($tmpPath, $this->configPath);
        } finally {
            flock($lockHandle, \LOCK_UN);
            fclose($lockHandle);
        }
    }

    /**
     * A single LOCK_EX | LOCK_NB attempt would fail even a legitimate microsecond-scale race
     * between two fast writers, so this retries a short, bounded number of times with a small
     * pause between attempts before giving up — the total ceiling stays in the tens of
     * milliseconds, never an unbounded wait behind another writer.
     *
     * @param resource $lockHandle
     */
    private function acquireLock($lockHandle): void
    {
        for ($attempt = 1; $attempt <= self::LOCK_ACQUIRE_MAX_ATTEMPTS; ++$attempt) {
            if (flock($lockHandle, \LOCK_EX | \LOCK_NB)) {
                return;
            }

            if ($attempt < self::LOCK_ACQUIRE_MAX_ATTEMPTS) {
                usleep(self::LOCK_ACQUIRE_RETRY_DELAY_MICROSECONDS);
            }
        }

        throw new AppConfigStoreLockedException($this->configPath, self::LOCK_ACQUIRE_MAX_ATTEMPTS);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $contents): array
    {
        if ($contents === '') {
            return [];
        }

        $data = json_decode($contents, true);

        return \is_array($data) ? $data : [];
    }
}
