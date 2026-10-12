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

namespace App\Service\Sync;

use App\Entity\ValueObject\Exception\InvalidPluginIdException;
use App\Entity\ValueObject\PluginId;
use App\Service\Notification\Kind\SyncReauthNotificationKind;
use App\Service\Notification\NotificationCenter;
use App\Service\Plugin\InstalledPluginsRegistry;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * The host-side `needs_reauth` state of a sync plugin (issue #1027), kept in `plugin_sync_state`.
 *
 * The false→true transition is one conditional `UPDATE ... WHERE needs_reauth = 0`: pull and push
 * run in different consumers and may both catch the dead session at once, only one of them
 * touches the row, so one transition gives at most one notification. The row is created first
 * (`INSERT OR IGNORE`), otherwise the UPDATE would touch nothing and the notification would be lost.
 *
 * Neither method throws: a failure is logged and swallowed, so the reauth branches of pull/push
 * keep their outcome (`false` / `UnrecoverableMessageHandlingException`) and a successful sync
 * operation is never failed by the bookkeeping. Call {@see self::onReauthRequired()} only outside
 * a transaction: it records the notification right away.
 */
class SyncReauthTracker
{
    public function __construct(
        private readonly Connection $connection,
        private readonly NotificationCenter $notifications,
        private readonly InstalledPluginsRegistry $installedPlugins,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Sets the flag and, only if this call made the transition, records the notification.
     */
    public function onReauthRequired(string $pluginId): void
    {
        try {
            if (!$this->markNeedsReauth($pluginId)) {
                return;
            }
            $this->notifications->record(SyncReauthNotificationKind::draft($pluginId, $this->pluginName($pluginId)));
        } catch (\Throwable $e) {
            $this->logger->error('Marking sync plugin "{plugin}" as needing reauthorization failed.', ['plugin' => $pluginId, 'exception' => $e]);
        }
    }

    /**
     * Clears the flag after a successful sync operation with the plugin's API.
     */
    public function onSyncSucceeded(string $pluginId): void
    {
        try {
            $this->connection->executeStatement('UPDATE plugin_sync_state SET needs_reauth = 0 WHERE plugin_id = ? AND needs_reauth = 1', [$pluginId]);
        } catch (\Throwable $e) {
            $this->logger->error('Clearing the reauthorization flag of sync plugin "{plugin}" failed.', ['plugin' => $pluginId, 'exception' => $e]);
        }
    }

    private function markNeedsReauth(string $pluginId): bool
    {
        $this->connection->executeStatement('INSERT OR IGNORE INTO plugin_sync_state (plugin_id, needs_reauth) VALUES (?, 0)', [$pluginId]);

        return $this->connection->executeStatement('UPDATE plugin_sync_state SET needs_reauth = 1 WHERE plugin_id = ? AND needs_reauth = 0', [$pluginId]) === 1;
    }

    private function pluginName(string $pluginId): string
    {
        try {
            $plugin = $this->installedPlugins->get(new PluginId($pluginId));
        } catch (InvalidPluginIdException) {
            return $pluginId;
        }

        return $plugin?->manifest->name ?? $pluginId;
    }
}
