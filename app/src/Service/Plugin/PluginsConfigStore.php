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

namespace App\Service\Plugin;

use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\Exception\PluginsConfigStoreException;

/**
 * Reads and writes %AppData%/plugins.json — one shared file for every installed plugin's
 * settings/credentials (OAuth refresh tokens, endpoint config, feature flags), keyed by
 * PluginId. Not encrypted: protection is filesystem ACLs on AppData, the same level the
 * app's SQLite files already rely on (issue #219).
 *
 * Short-lived access tokens do not belong here — they belong in a regular cache with a TTL
 * matching the token lifetime; only long-lived refresh tokens and durable settings go through
 * this store.
 *
 * At least two processes write concurrently: the HTTP worker (user changes settings via the
 * UI) and the background messenger consumer (a plugin refreshes its OAuth token). A bare
 * temp-file + rename() only protects the integrity of a single write, not against a lost
 * update between two writers (both read, each changes its own part, the second rename()
 * silently discards the first). updatePluginSettings() closes that gap by holding an
 * exclusive flock() for the whole read -> modify -> serialize -> write-temp -> rename cycle.
 * Readers need no lock: the atomic rename() alone guarantees they see a consistent version of
 * the file, wholly old or wholly new.
 */
final class PluginsConfigStore
{
    public function __construct(private readonly string $pluginsConfigPath)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function getPluginSettings(PluginId $pluginId): array
    {
        $settings = $this->read()[(string) $pluginId] ?? null;

        return \is_array($settings) ? $settings : [];
    }

    /**
     * Atomically reads, modifies and writes back the settings of a single plugin, leaving
     * every other plugin's entry untouched. $modifier receives the plugin's current settings
     * (empty array if none yet) and returns the settings to persist.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $modifier
     */
    public function updatePluginSettings(PluginId $pluginId, callable $modifier): void
    {
        $this->update(static function (array $plugins) use ($pluginId, $modifier): array {
            $current = $plugins[(string) $pluginId] ?? [];
            $plugins[(string) $pluginId] = $modifier(\is_array($current) ? $current : []);

            return $plugins;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        if (!is_file($this->pluginsConfigPath)) {
            return [];
        }

        $contents = file_get_contents($this->pluginsConfigPath);
        if (false === $contents) {
            return [];
        }

        return $this->decode($contents);
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $modifier
     */
    private function update(callable $modifier): void
    {
        $directory = \dirname($this->pluginsConfigPath);
        if (!is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        // Locking the config file itself would not work: rename() below swaps in a new inode on
        // every write, so a second writer that opened its handle *before* that rename ends up
        // holding a lock on the now-orphaned old inode — its flock() no longer excludes anyone,
        // and it silently overwrites the first writer's update with stale data it read earlier.
        // A dedicated lock file, never replaced by rename(), keeps the same inode across every
        // acquisition, so flock() actually serializes writers.
        $lockHandle = fopen($this->pluginsConfigPath.'.lock', 'c');
        if (false === $lockHandle) {
            throw new PluginsConfigStoreException(\sprintf('Unable to open lock file for "%s".', $this->pluginsConfigPath));
        }

        try {
            if (!flock($lockHandle, \LOCK_EX)) {
                throw new PluginsConfigStoreException(\sprintf('Unable to lock "%s".', $this->pluginsConfigPath));
            }

            $plugins = $modifier($this->read());

            $encoded = json_encode($plugins, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            if (false === $encoded) {
                throw new PluginsConfigStoreException(\sprintf('Unable to encode "%s" as JSON.', $this->pluginsConfigPath));
            }

            // rename() on Windows overwrites an existing destination (unlike a bare POSIX
            // rename() pre-8.0), so this stays atomic on the app's only supported platform.
            $tmpPath = $this->pluginsConfigPath.'.tmp';
            if (false === file_put_contents($tmpPath, $encoded)) {
                throw new PluginsConfigStoreException(\sprintf('Unable to write "%s".', $tmpPath));
            }

            rename($tmpPath, $this->pluginsConfigPath);
        } finally {
            flock($lockHandle, \LOCK_UN);
            fclose($lockHandle);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $contents): array
    {
        if ('' === $contents) {
            return [];
        }

        $data = json_decode($contents, true);

        return \is_array($data) ? $data : [];
    }
}
