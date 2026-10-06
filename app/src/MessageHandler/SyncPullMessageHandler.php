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

namespace App\MessageHandler;

use App\Entity\ValueObject\PluginId;
use App\Message\SyncPullMessage;
use App\Message\SyncSeedMessage;
use App\Service\JobLock\JobLockService;
use App\Service\Plugin\ExternalIdBackfillService;
use App\Service\Plugin\PullSyncService;
use App\Service\Plugin\SyncRegistry;
use App\Service\Sync\SourceRemovalService;
use App\Service\Sync\SyncPullGate;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Periodic pull for one plugin (issue #870), queued by {@see SyncPullTickMessageHandler}.
 *
 * The plugin and {@see SyncPullGate::isDue()} are re-checked here: a pull longer than the tick
 * interval lets the next tick queue a second message for the same plugin. The pull holds the same
 * {@see SyncSeedMessage::jobKey()} lock as {@see SyncSeedMessageHandler}, so it never overlaps a
 * seed, another pull, or a push ({@see PushSyncMessageHandler} skips while it is held).
 *
 * Like the seed, the pull runs {@see ExternalIdBackfillService::backfill()} first (issue #867): `syncSeeded`
 * is set when the seed is dispatched, not when it finishes, so a seed that was lost or has not run yet
 * must not let the pull miss records that only have a source URL and create duplicates of them.
 *
 * A successful pull records `syncLastPullAt`. A `false` result (reauthorization needed) is only
 * logged: `syncSeeded` stays as is, and the next hourly tick retries.
 */
#[AsMessageHandler]
final class SyncPullMessageHandler
{
    public function __construct(
        private readonly SyncRegistry $syncRegistry,
        private readonly SyncPullGate $gate,
        private readonly ExternalIdBackfillService $backfillService,
        private readonly SourceRemovalService $sourceRemoval,
        private readonly PullSyncService $pullSyncService,
        private readonly JobLockService $jobLockService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncPullMessage $message): void
    {
        $pluginId = new PluginId($message->pluginId);
        $sync = $this->syncRegistry->findByPluginId($pluginId);

        if ($sync === null || !$this->gate->isDue($pluginId)) {
            $this->logger->info('Periodic pull for plugin "{pluginId}" skipped: the plugin is not an active, seeded sync plugin or was pulled recently.', [
                'pluginId' => $message->pluginId,
            ]);

            return;
        }

        $jobKey = SyncSeedMessage::jobKey($message->pluginId);
        if (!$this->jobLockService->acquire($jobKey)) {
            $this->logger->info('Periodic pull for plugin "{pluginId}" skipped: a seed or another pull for it is already running.', [
                'pluginId' => $message->pluginId,
            ]);

            return;
        }

        try {
            // Pending removals first, then the backfill, then the pull (issue #918), see SyncSeedMessageHandler.
            $this->sourceRemoval->retryPending($pluginId, $sync);
            $this->backfillService->backfill($pluginId, $sync);
            $pulled = $this->pullSyncService->pull($pluginId, $sync, fn () => $this->jobLockService->heartbeat($jobKey));
        } finally {
            $this->jobLockService->release($jobKey);
        }

        if ($pulled) {
            $this->gate->markPulled($pluginId);

            return;
        }

        $this->logger->info('Periodic pull for plugin "{pluginId}" did not complete (reauthorization required, or a failed item closed the EntityManager — see earlier log entries); it will be retried on a later tick.', [
            'pluginId' => $message->pluginId,
        ]);
    }
}
