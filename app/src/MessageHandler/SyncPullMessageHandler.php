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
use App\Service\Notification\Kind\SyncReviewNotificationKind;
use App\Service\Notification\NotificationCenter;
use App\Service\Plugin\ExternalIdBackfillService;
use App\Service\Plugin\PullSyncService;
use App\Service\Plugin\SyncRegistry;
use App\Service\Sync\SourceRemovalService;
use App\Service\Sync\SyncPullGate;
use App\Service\Sync\SyncReviewService;
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
 * When the run raised new unresolved review items (created, not the net change: a run may close others), a `sync_review` notification
 * is recorded after the lock is released, also when the run threw, i.e. after the pull's own commits (issue #1028).
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
        private readonly SyncReviewService $syncReview,
        private readonly NotificationCenter $notifications,
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

        // Before the backfill and the removals catch-up: review items they raise belong to this run too.
        $watermark = $this->takeWatermark();

        try {
            // Backfill, then the catch-up of pending removals, then the pull (issue #918), see SyncSeedMessageHandler.
            $this->backfillService->backfill($pluginId, $sync);
            $this->sourceRemoval->retryPending($pluginId, $sync);
            $pulled = $this->pullSyncService->pull($pluginId, $sync, fn () => $this->jobLockService->heartbeat($jobKey));
        } finally {
            $this->jobLockService->release($jobKey);
            // Also for a pull that threw: the items it raised before that are already committed.
            $this->notifyRaisedSince($watermark);
        }

        if ($pulled) {
            $this->gate->markPulled($pluginId);

            return;
        }

        $this->logger->info('Periodic pull for plugin "{pluginId}" did not complete (reauthorization required, or a failed item closed the EntityManager — see earlier log entries); it will be retried on a later tick.', [
            'pluginId' => $message->pluginId,
        ]);
    }

    /** Issue #1028: bookkeeping of the notification never changes the outcome of the sync run, so a failure here is only logged. */
    private function takeWatermark(): ?int
    {
        try {
            return $this->syncReview->watermark();
        } catch (\Throwable $e) {
            $this->logger->error('Sync review watermark could not be taken, the run goes on without a sync_review notification: {error}', ['error' => $e->getMessage(), 'exception' => $e]);

            return null;
        }
    }

    private function notifyRaisedSince(?int $watermark): void
    {
        if ($watermark === null) {
            return;
        }

        try {
            if ($this->syncReview->countUnresolvedRaisedSince($watermark) > 0) {
                $this->notifications->record(SyncReviewNotificationKind::draft($this->syncReview->countUnresolved()));
            }
        } catch (\Throwable $e) {
            $this->logger->error('The sync_review notification could not be recorded: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
        }
    }
}
