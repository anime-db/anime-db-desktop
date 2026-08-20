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

use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\Exception\PluginsConfigStoreException;
use App\Service\Plugin\Exception\PluginsConfigStoreLockedException;

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
 *
 * The lock acquire is non-blocking (issue #340): a single lock file guards every plugin's
 * entry, including the host's own `enabled`/`features` toggles ({@see WidgetActiveTrait}), so a
 * writer that blocked indefinitely behind another one — e.g. a plugin's own $modifier stuck on
 * a network call it should never have made under the lock — would also block unrelated writers,
 * including the settings page trying to disable that very plugin. acquireLock() instead retries
 * a short, bounded number of times and then fails fast with
 * {@see PluginsConfigStoreLockedException}, turning an unbounded hang into an immediate,
 * recoverable error.
 *
 * A plugin's `enabled` flag lives only here (see {@see InstalledPluginsRegistry}), which is why
 * every read of it — including {@see AvailableLocalesProvider}, issue #453 — goes through
 * {@see self::read()} fresh rather than through an in-process cache: FrankenPHP's worker pool
 * gives each worker its own isolated memory, so a cache invalidated by an in-process event would
 * only ever update the one worker that handled this write.
 */
final class PluginsConfigStore
{
    private const int LOCK_ACQUIRE_MAX_ATTEMPTS = 10;
    private const int LOCK_ACQUIRE_RETRY_DELAY_MICROSECONDS = 5_000;

    public function __construct(
        private readonly string $pluginsConfigPath,
    ) {
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
     * Every plugin's settings entry, keyed by plugin id — the same data {@see self::getPluginSettings()}
     * returns one entry of, exposed in bulk so a caller that needs more than one plugin's settings
     * (e.g. {@see InstalledPluginsRegistry::readIndex()} resolving every plugin's `enabled` flag)
     * reads this file once instead of once per plugin.
     *
     * @return array<string, mixed>
     */
    public function getAllSettings(): array
    {
        return $this->read();
    }

    /**
     * Atomically reads, modifies and writes back the settings of a single plugin, leaving
     * every other plugin's entry untouched. $modifier receives the plugin's current settings
     * (empty array if none yet) and returns the settings to persist.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $modifier
     *
     * @throws PluginsConfigStoreLockedException
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
     * Reads the `settings` subsection of a plugin's entry — the payload behind
     * {@see \AnimeDb\PluginContracts\Settings\SettingsStoreInterface::read()} (issue #316), kept apart from the `enabled`/
     * `features` keys the rest of this class manages.
     *
     * @return array<string, mixed>
     */
    public function getSettingsStorePayload(PluginId $pluginId): array
    {
        $settings = $this->getPluginSettings($pluginId)['settings'] ?? null;

        return \is_array($settings) ? $settings : [];
    }

    /**
     * Removes the `settings` subsection of a plugin's entry entirely, leaving its `enabled`/
     * `features` keys in place. For the future plugin uninstaller (issues #220-225, not
     * implemented yet) to call once a plugin is removed, so an orphaned plaintext token does
     * not survive uninstall and resurface if the plugin is reinstalled later.
     */
    public function purgeSettingsStorePayload(PluginId $pluginId): void
    {
        $this->updatePluginSettings($pluginId, static function (array $settings): array {
            unset($settings['settings']);

            return $settings;
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
        if ($contents === false) {
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
        if ($lockHandle === false) {
            throw new PluginsConfigStoreException(\sprintf('Unable to open lock file for "%s".', $this->pluginsConfigPath));
        }

        try {
            $this->acquireLock($lockHandle);

            $plugins = $modifier($this->read());

            $encoded = json_encode($plugins, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                throw new PluginsConfigStoreException(\sprintf('Unable to encode "%s" as JSON.', $this->pluginsConfigPath));
            }

            // rename() on Windows overwrites an existing destination (unlike a bare POSIX
            // rename() pre-8.0), so this stays atomic on the app's only supported platform.
            $tmpPath = $this->pluginsConfigPath.'.tmp';
            if (file_put_contents($tmpPath, $encoded) === false) {
                throw new PluginsConfigStoreException(\sprintf('Unable to write "%s".', $tmpPath));
            }

            rename($tmpPath, $this->pluginsConfigPath);
        } finally {
            flock($lockHandle, \LOCK_UN);
            fclose($lockHandle);
        }
    }

    /**
     * A single LOCK_EX | LOCK_NB attempt would fail even a legitimate microsecond-scale race
     * between two fast writers, so this retries a short, bounded number of times with a small
     * pause between attempts before giving up — the total ceiling stays in the tens of
     * milliseconds, never an unbounded wait behind another writer (issue #340).
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

        throw new PluginsConfigStoreLockedException($this->pluginsConfigPath, self::LOCK_ACQUIRE_MAX_ATTEMPTS);
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
