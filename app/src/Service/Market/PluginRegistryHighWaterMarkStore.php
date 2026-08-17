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

namespace App\Service\Market;

use App\Service\AppConfigStore;

/**
 * Persists the plugin registry anti-rollback high-water-mark `sequence` at
 * `%AppData%/config.json`, the same file {@see \App\Service\AppSettingsProvider} and
 * {@see \App\Service\ProxyConfigProvider} use — deliberately *outside*
 * {@see PluginRegistryCache}'s cache file: that cache can be dropped (reinstall) or pruned to a
 * snapshot, and doing so must not reset the anti-rollback baseline, or a compromised mirror could
 * replay an older, still validly signed registry right after (issue #437).
 *
 * Every write goes through {@see AppConfigStore}, which holds an exclusive lock for the whole
 * read-modify-write cycle, so a concurrent write to a different key cannot be lost the same way
 * AppSettingsProvider/ProxyConfigProvider already rely on.
 */
final class PluginRegistryHighWaterMarkStore
{
    private const string CONFIG_KEY = 'marketRegistryHighWaterMarkSequence';

    public function __construct(private readonly AppConfigStore $configStore)
    {
    }

    /**
     * Null until the first registry has ever been accepted through this store (fresh install, or
     * an install that predates it). {@see PluginRegistryLoader} does not treat that as "nothing
     * to compare against yet" on its own — for a pre-existing install it still floors the
     * comparison against the cached registry's `sequence` ({@see PluginRegistryCache}), so an
     * upgrade cannot open a rollback window before this store gets to persist its own baseline.
     */
    public function getSequence(): ?int
    {
        $sequence = $this->configStore->read()[self::CONFIG_KEY] ?? null;

        return \is_int($sequence) ? $sequence : null;
    }

    /**
     * Raises the persisted high-water-mark to $sequence, but only if it is higher than what is
     * already stored (or nothing is stored yet) — never lowers it. The comparison runs inside
     * AppConfigStore's lock rather than relying on the caller's own already-checked comparison,
     * so a race between concurrent writers (the FrankenPHP worker pool) can't let a lower
     * sequence clobber a higher one already persisted by another worker.
     */
    public function raise(int $sequence): void
    {
        $this->configStore->update(static function (array $config) use ($sequence): array {
            $current = $config[self::CONFIG_KEY] ?? null;
            if (!\is_int($current) || $sequence > $current) {
                $config[self::CONFIG_KEY] = $sequence;
            }

            return $config;
        });
    }
}
