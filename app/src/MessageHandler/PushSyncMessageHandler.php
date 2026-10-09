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

use AnimeDb\PluginContracts\OAuth\ReauthRequiredException;
use AnimeDb\PluginContracts\Sync\SyncItem;
use AnimeDb\PluginContracts\Sync\SyncStatus;
use App\Entity\Anime;
use App\Entity\AnimeSyncState;
use App\Entity\SeriesAnime;
use App\Entity\ValueObject\PluginId;
use App\Message\PushSyncMessage;
use App\Message\SyncSeedMessage;
use App\Repository\AnimeSyncStateRepository;
use App\Service\JobLock\JobLockService;
use App\Service\Plugin\SyncRegistry;
use App\Service\Plugin\WatchStatusMapper;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Each message carries its target plugin's id (issue #868): {@see PushSyncMessage::$pluginId},
 * dispatched once per active plugin by WatchProgressPushSubscriber, so a plugin's push() failure
 * only affects its own message's retries, never another plugin's. The plugin is re-resolved via
 * {@see SyncRegistry::findByPluginId()} rather than trusted from the message, since the plugin may
 * have been disabled between dispatch and processing — a no-longer-active plugin is skipped
 * without an exception (info log), not treated as a failure.
 *
 * A message without $pluginId is the backward-compatible shape: one already sitting in the queue
 * (data/queue.db) from before this property existed. For that shape only, the handler falls back
 * to the pre-#868 behavior of looping over every {@see SyncRegistry::allActive()} plugin in this
 * one message — see {@see self::pushToEveryActivePlugin()}'s docblock for how failures are
 * handled in that loop.
 *
 * Same no-catch stance as IndexAnimeMessageHandler for transient failures either way: a plugin's
 * push() failure (network error, external source down, ...) is deliberately left to propagate so
 * the `async` transport's own retry_strategy (issue #97) handles the retry, and Messenger's
 * built-in failure logging covers the case all retries are exhausted — no bespoke try/catch/log
 * needed for those. Re-pushing to a plugin that already succeeded is harmless, since push is the
 * safe sync direction (issue #214) — local state is the source of truth for what's sent, so there
 * is no collision/deduplication concern the way there is for pull.
 *
 * {@see ReauthRequiredException} is the one deliberate exception to that stance (issue #353): a
 * dead OAuth session is not transient, so retrying it three times and then dead-lettering it
 * silently is pure noise — the plugin itself is expected to have already cleared its stored
 * tokens when it throws this, which is enough for its settings page to show "not authorized" on
 * its own. Logged, then re-thrown wrapped as unrecoverable so Messenger accepts the message as
 * handled instead of retrying/dead-lettering it. For the one-plugin shape that is immediate; for
 * the backward-compatible loop it is caught per plugin so one plugin needing reauthorization does
 * not stop the loop from reaching the others, and the wrapped exception is thrown once after the
 * loop finishes.
 *
 * Push-on-edit TTL (issue #366): a message older than $pushOnEditTtlSeconds since
 * PushSyncMessage::$dispatchedAt is dropped without pushing — see that property's docblock and
 * .claude-docs/sync.md's "Ритм: push-on-edit". This is not a lost edit: the edit itself already
 * landed in local when it was made, and being too stale to blind-push just means local now
 * disagrees with this plugin's last-seen snapshot, which the next reconciliation run (issue
 * #366's SyncConvergenceService, driven by PullSyncService) picks up as an ordinary dirty
 * participant — no separate "missed push" bookkeeping needed. Applies the same way to both
 * message shapes.
 *
 * Snapshot bookkeeping (issue #366 pitfall #6): after a successful push, $stateRepository is
 * updated from the plugin's own confirmed {@see SyncItem} return value, not from what was sent —
 * a source may normalize the write (e.g. a lossy status mapping) or report its own updatedAt, and
 * seeding the snapshot with anything else would make the next pull see a phantom "changed".
 *
 * Seed lock: while the connect-seed pull of a plugin runs ({@see SyncSeedMessage::jobKey()}, held by
 * SyncSeedMessageHandler in another process), a push for that plugin is skipped rather than
 * written — the pull inserts and overwrites the same `AnimeSyncState` rows through its own
 * EntityManager, so a concurrent push could collide on the (anime, plugin) primary key, which
 * costs the pull the rest of its new items. Skipping is the same non-loss as the TTL drop above:
 * the edit is already in local, and the pull's reconciliation sees it as a dirty participant.
 * The check is not atomic with the push that follows it, which is accepted: the window is the
 * length of one push() call against a lock held for the length of a whole seed.
 */
#[AsMessageHandler]
final class PushSyncMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SyncRegistry $syncRegistry,
        private readonly AnimeSyncStateRepository $stateRepository,
        private readonly LoggerInterface $logger,
        private readonly int $pushOnEditTtlSeconds,
        private readonly JobLockService $jobLockService,
    ) {
    }

    public function __invoke(PushSyncMessage $message): void
    {
        $age = (new \DateTimeImmutable())->getTimestamp() - $message->dispatchedAt->getTimestamp();
        if ($age > $this->pushOnEditTtlSeconds) {
            $this->logger->info('Push-on-edit message for anime #{animeId} is older than the {ttl}s TTL; dropping it, the next reconciliation will pick up the divergence.', [
                'animeId' => $message->animeId,
                'ttl' => $this->pushOnEditTtlSeconds,
            ]);

            return;
        }

        $anime = $this->entityManager->find(Anime::class, $message->animeId);
        if ($anime === null) {
            // Deleted (or the transaction that changed it never committed) by the time this
            // message is processed — nothing left to push.
            return;
        }

        $status = WatchStatusMapper::toSyncStatus($anime->getWatchStatus());
        $watchedEpisodes = $anime instanceof SeriesAnime ? $anime->getWatchedEpisodes() : null;

        $pluginId = $message->pluginId ?? null;
        if ($pluginId !== null) {
            $this->pushToSinglePlugin($anime, $pluginId, $status, $watchedEpisodes);

            return;
        }

        $this->pushToEveryActivePlugin($anime, $status, $watchedEpisodes);
    }

    /**
     * The one-plugin shape (issue #868): re-checks $pluginId is still active before pushing, since
     * it may have been disabled between dispatch and processing — a no-longer-active plugin is
     * skipped without an exception, not treated as a failure.
     */
    private function pushToSinglePlugin(Anime $anime, string $pluginId, SyncStatus $status, ?int $watchedEpisodes): void
    {
        $sync = $this->syncRegistry->findByPluginId(new PluginId($pluginId));
        if ($sync === null) {
            $this->logger->info('Sync plugin "{plugin}" is no longer active; skipping its queued push.', [
                'plugin' => $pluginId,
            ]);

            return;
        }

        if ($this->isSeeding($pluginId)) {
            return;
        }

        $externalId = $anime->getExternalId(new PluginId($pluginId), $sync);
        if ($externalId === null) {
            // This plugin doesn't recognize any of the anime's source URLs — nothing to push it
            // under.
            return;
        }

        try {
            $confirmed = $sync->push(new SyncItem($externalId, $status, $anime->getTitle(), type: null, updatedAt: $anime->getWatchProgressUpdatedAt(), watchedEpisodes: $watchedEpisodes));
        } catch (ReauthRequiredException $exception) {
            $this->logger->warning('Sync plugin "{plugin}" needs reauthorization; skipping push for it, not retrying.', [
                'plugin' => $pluginId,
                'exception' => $exception,
            ]);

            throw new UnrecoverableMessageHandlingException(sprintf('Sync plugin "%s" needs reauthorization, not retrying this message.', $pluginId));
        }

        $this->updateSnapshot($anime, $pluginId, $confirmed);
    }

    /**
     * The backward-compatible shape (issue #868): a message without $pluginId, already sitting
     * in the queue from before that property existed. Pushes to every active plugin in this one
     * run, same as the pre-#868 behavior — if one plugin's push() fails, the remaining plugins in
     * this run are skipped and retried alongside it on the next attempt; that is the exact
     * failure mode issue #868 removes for newly dispatched messages, kept here only so an
     * already-queued old-format message is not dropped outright.
     */
    private function pushToEveryActivePlugin(Anime $anime, SyncStatus $status, ?int $watchedEpisodes): void
    {
        /** @var list<string> $pluginsNeedingReauth */
        $pluginsNeedingReauth = [];

        foreach ($this->syncRegistry->allActive() as $id => $sync) {
            if ($this->isSeeding($id)) {
                continue;
            }

            $externalId = $anime->getExternalId(new PluginId($id), $sync);
            if ($externalId === null) {
                // This plugin doesn't recognize any of the anime's source URLs — nothing to
                // push it under.
                continue;
            }

            try {
                $confirmed = $sync->push(new SyncItem($externalId, $status, $anime->getTitle(), type: null, updatedAt: $anime->getWatchProgressUpdatedAt(), watchedEpisodes: $watchedEpisodes));
            } catch (ReauthRequiredException $exception) {
                $this->logger->warning('Sync plugin "{plugin}" needs reauthorization; skipping push for it, not retrying.', [
                    'plugin' => $id,
                    'exception' => $exception,
                ]);

                $pluginsNeedingReauth[] = $id;

                continue;
            }

            $this->updateSnapshot($anime, $id, $confirmed);
        }

        if ($pluginsNeedingReauth !== []) {
            throw new UnrecoverableMessageHandlingException(sprintf('Sync plugin(s) need reauthorization, not retrying this message: %s.', implode(', ', $pluginsNeedingReauth)));
        }
    }

    private function isSeeding(string $pluginId): bool
    {
        if (!$this->jobLockService->isLocked(SyncSeedMessage::jobKey($pluginId))) {
            return false;
        }

        $this->logger->info('Sync plugin "{plugin}" is being seeded; skipping its push, the seed\'s reconciliation picks up the edit.', [
            'plugin' => $pluginId,
        ]);

        return true;
    }

    private function updateSnapshot(Anime $anime, string $participantId, SyncItem $confirmed): void
    {
        $status = WatchStatusMapper::toWatchStatus($confirmed->status);
        // The contract falls back to the host's own value when the source reports no updatedAt
        // of its own (SyncItem::$updatedAt docblock) — here that is the anime's own progress time.
        $updatedAt = $confirmed->updatedAt ?? $anime->getWatchProgressUpdatedAt() ?? new \DateTimeImmutable();

        $existing = $this->stateRepository->find($anime, $participantId);
        if ($existing !== null) {
            $existing->update($status, $confirmed->watchedEpisodes, $updatedAt);
            $this->stateRepository->save($existing);

            return;
        }

        $this->stateRepository->save(new AnimeSyncState($anime, $participantId, $status, $confirmed->watchedEpisodes, $updatedAt));
    }
}
