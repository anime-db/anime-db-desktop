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

use AnimeDb\PluginContracts\Sync\SyncInterface;
use App\Entity\ValueObject\PluginId;
use App\Message\SyncSeedMessage;
use App\Service\JobLock\JobLockService;
use App\Service\Plugin\ExternalIdBackfillService;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\PullSyncService;
use App\Service\Plugin\SyncRegistry;
use App\Service\Sync\SourceRemovalService;
use App\Service\Sync\SyncPullGate;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Connect-seed handler (issue #381): runs the one-time full pull that seeds the catalog right
 * after a sync plugin is enabled, mirroring how {@see PushSyncMessageHandler} consumes
 * {@see SyncRegistry} for the push direction (issue #214). The actual reconciliation — applying
 * agreements straight to local and raising persistent review items for genuine conflicts — is
 * entirely {@see PullSyncService::pull()}'s job; this handler is only the trigger (consumed from the dedicated `sync` transport) that
 * keeps that potentially ~1000-item pull off the HTTP request which enabled the plugin.
 *
 * {@see SyncRegistry::findByPluginId()} is re-resolved here rather than trusting the dispatch-time
 * state: the same self-healing stance as {@see PushSyncMessageHandler} takes for a deleted Anime —
 * a plugin the user disabled again before this message was processed simply has nothing left to
 * seed, not an error.
 *
 * Before pulling, the handler runs {@see ExternalIdBackfillService::backfill()} for the same plugin
 * (issue #867), so ids derivable from existing source URLs are cached by the time the pull
 * builds its `indexByExternalId()` lookup.
 *
 * `features.sync` alone (what {@see SyncRegistry::findByPluginId()} gates on) does not guarantee
 * the plugin's OAuth is actually complete — {@see \App\Controller\Settings\PluginSettingsController}
 * sets the `syncSeeded` one-time flag before this handler ever runs, purely to make the dispatch
 * itself idempotent. If {@see PullSyncService::pull()} reports it stopped short on a dead/missing
 * OAuth session (its `false` return), the seed never actually happened, so this handler resets
 * `syncSeeded` back to `false` — the user's next visit to the settings page, presumably after
 * finishing OAuth, re-triggers connect-seed instead of the flag silently staying "done" forever.
 */
#[AsMessageHandler]
final class SyncSeedMessageHandler
{
    public function __construct(
        private readonly SyncRegistry $syncRegistry,
        private readonly ExternalIdBackfillService $backfillService,
        private readonly SourceRemovalService $sourceRemoval,
        private readonly PullSyncService $pullSyncService,
        private readonly PluginsConfigStore $pluginsConfigStore,
        private readonly JobLockService $jobLockService,
        private readonly SyncPullGate $pullGate,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncSeedMessage $message): void
    {
        $pluginId = new PluginId($message->pluginId);
        $sync = $this->syncRegistry->findByPluginId($pluginId);

        if ($sync === null) {
            $this->logger->info('Connect-seed for plugin "{pluginId}" skipped: it is no longer an active sync plugin.', [
                'pluginId' => $message->pluginId,
            ]);

            return;
        }

        // The seed runs in plugins-consumer, PushSyncMessage in messenger-consumer: separate
        // processes and EntityManagers, so without a lock a push made while the seed is running
        // could insert the same (anime, plugin) AnimeSyncState row the pull is inserting — a PK
        // violation that makes the pull skip the rest of its new items. PushSyncMessageHandler skips
        // while this lock is held; the divergence it leaves is picked up by the pull's own
        // reconciliation, same as for a push dropped by the push-on-edit TTL.
        $jobKey = SyncSeedMessage::jobKey($message->pluginId);
        if (!$this->jobLockService->acquire($jobKey)) {
            $this->logger->info('Connect-seed for plugin "{pluginId}" skipped: another seed for it is already running.', [
                'pluginId' => $message->pluginId,
            ]);

            return;
        }

        try {
            $this->seed($message, $pluginId, $sync, $jobKey);
        } finally {
            $this->jobLockService->release($jobKey);
        }
    }

    private function seed(SyncSeedMessage $message, PluginId $pluginId, SyncInterface $sync, string $jobKey): void
    {
        // Issue #867: the external-id backfill runs here, synchronously and before the pull, rather
        // than as a separately dispatched message — the pull matches pulled items against
        // AnimeRepository::indexByExternalId(), which only sees ids that are already cached, so a
        // backfill racing or trailing the pull would let it create second rows for titles that
        // already sit in the catalog with a source URL. The ordering has to live in this handler:
        // FIFO order between two messages is not guaranteed once they travel on different transports.
        // The catch-up of pending removals on the source (issue #918) goes after the backfill: the
        // cache of external ids has to be complete before deciding to delete, otherwise a live entry
        // that only has a source URL would not be seen as holding the id and its list item would go.
        $this->backfillService->backfill($pluginId, $sync);
        $this->sourceRemoval->retryPending($pluginId, $sync);

        $seeded = $this->pullSyncService->pull($pluginId, $sync, fn () => $this->jobLockService->heartbeat($jobKey));
        if ($seeded) {
            // Issue #870: the seed is a successful pull too, so the periodic one waits its full age.
            $this->pullGate->markPulled($pluginId);
        } else {
            $this->logger->info('Connect-seed for plugin "{pluginId}" did not complete (reauthorization required, or a failed item closed the EntityManager — see earlier log entries); resetting the seeded flag so the next settings-page visit or periodic sync tick retries it.', [
                'pluginId' => $message->pluginId,
            ]);

            $this->pluginsConfigStore->updatePluginSettings($pluginId, static function (array $settings): array {
                $settings['syncSeeded'] = false;

                return $settings;
            });
        }
    }
}
