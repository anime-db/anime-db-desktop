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

use AnimeDb\PluginContracts\OAuth\ReauthRequiredException;
use AnimeDb\PluginContracts\Sync\SyncInterface;
use AnimeDb\PluginContracts\Sync\SyncRemovalInterface;
use App\Entity\ValueObject\PluginId;
use App\Repository\AnimeRepository;
use App\Repository\SyncTombstoneRepository;
use Psr\Log\LoggerInterface;

/**
 * Deferred deletion of a title from the user's list on a source (issue #918), shared by
 * {@see \App\MessageHandler\RemoveFromSourceMessageHandler} (one pair) and the catch-up before a
 * plugin's pull ({@see self::retryPending()}).
 *
 * The queued message only wakes the handler up; the state is decided here, from the
 * {@see \App\Entity\SyncTombstone}: the pending flag must be set, and no live entry may hold the
 * (plugin_id, external_id) pair. A live entry holding it means the user added the title again after
 * the deletion, and the push put it back on the list: it must not be removed, the flag is cleared.
 * A successful removal deletes the tombstone, the only place that does.
 */
final class SourceRemovalService
{
    public function __construct(
        private readonly SyncTombstoneRepository $tombstones,
        private readonly AnimeRepository $animeRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws ReauthRequiredException when the source needs authorization again; the flag stays
     * @throws \Throwable              any other failure of the plugin; the flag stays
     */
    public function remove(PluginId $pluginId, SyncInterface $sync, string $externalId): SourceRemovalOutcome
    {
        $plugin = (string) $pluginId;

        if (!$this->tombstones->isRemovalPending($plugin, $externalId)) {
            $this->logger->info('Deferred removal of "{externalId}" on "{plugin}" skipped: nothing is pending for it.', [
                'plugin' => $plugin,
                'externalId' => $externalId,
            ]);

            return SourceRemovalOutcome::NotPending;
        }

        if ($this->animeRepository->holdsExternalId($pluginId, $externalId)) {
            $this->tombstones->clearRemovalPending($plugin, $externalId);
            $this->logger->info('Deferred removal of "{externalId}" on "{plugin}" cancelled: an entry holds it again.', [
                'plugin' => $plugin,
                'externalId' => $externalId,
            ]);

            return SourceRemovalOutcome::Cancelled;
        }

        if (!$sync instanceof SyncRemovalInterface) {
            $this->logger->info('Deferred removal of "{externalId}" on "{plugin}" kept pending: the plugin cannot remove list entries.', [
                'plugin' => $plugin,
                'externalId' => $externalId,
            ]);

            return SourceRemovalOutcome::Unsupported;
        }

        $sync->remove($externalId);
        $this->tombstones->remove($plugin, $externalId);
        $this->logger->info('Removed "{externalId}" from the list on "{plugin}".', [
            'plugin' => $plugin,
            'externalId' => $externalId,
        ]);

        return SourceRemovalOutcome::Removed;
    }

    /**
     * The catch-up before a pull: tries every pending deletion of the plugin, so one that did not
     * get through (offline, authorization) is repeated at the next sync. Must run after the
     * external-id backfill: the cache of ids has to be complete first, or a live entry that only
     * has a source URL would not be seen as holding the id and its list item would be removed. Never throws; a failure is logged and the flag stays.
     * A dead authorization stops the catch-up, the pull after it will report that itself.
     */
    public function retryPending(PluginId $pluginId, SyncInterface $sync): void
    {
        foreach ($this->tombstones->findRemovalPending($pluginId) as $externalId) {
            try {
                $this->remove($pluginId, $sync, $externalId);
            } catch (ReauthRequiredException $exception) {
                $this->logger->warning('Sync plugin "{plugin}" needs reauthorization; its pending removals stay for later.', [
                    'plugin' => (string) $pluginId,
                    'exception' => $exception,
                ]);

                return;
            } catch (\Throwable $exception) {
                $this->logger->warning('Deferred removal of "{externalId}" on "{plugin}" failed; it stays pending.', [
                    'plugin' => (string) $pluginId,
                    'externalId' => $externalId,
                    'exception' => $exception,
                ]);
            }
        }
    }
}
