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

namespace App\Message;

/**
 * Dispatched on the dedicated `sync` transport when a sync plugin becomes active (issue #381) — the
 * connect-seed step of opt-in sync: a full {@see \App\Service\Plugin\PullSyncService::pull()} run
 * for that one plugin, so the newly connected source's whole list is reconciled into the catalog
 * without blocking the HTTP request that just enabled it. Carries only the plugin id, the handler
 * re-resolves the {@see \AnimeDb\PluginContracts\Sync\SyncInterface} instance itself before
 * pulling.
 *
 * {@see self::jobKey()} is the per-plugin {@see \App\Service\JobLock\JobLockService} key held for
 * the whole seed: the seed runs in `plugins-consumer` while {@see PushSyncMessage} runs in
 * `messenger-consumer`, two processes with separate EntityManagers, so the lock is what keeps a
 * push from writing the same `AnimeSyncState` rows the pull is inserting.
 */
final readonly class SyncSeedMessage
{
    public function __construct(
        public string $pluginId,
    ) {
    }

    public static function jobKey(string $pluginId): string
    {
        return \sprintf('sync:%s', $pluginId);
    }
}
