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

namespace App\Service\Sync;

use AnimeDb\PluginContracts\Sync\SyncInterface;
use AnimeDb\PluginContracts\Sync\SyncItem;
use App\Entity\Anime;
use App\Entity\AnimeSyncState;
use App\Entity\Enum\SyncReviewItemKind;
use App\Entity\SeriesAnime;
use App\Entity\ValueObject\PluginId;
use App\Repository\AnimeSyncStateRepository;
use App\Service\Plugin\SyncRegistry;
use App\Service\Plugin\WatchStatusMapper;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The I/O half of the reconciliation engine (issue #366): {@see SyncReconciler} itself is a pure
 * decision function, this class is what actually collects a title's participant maps, runs it,
 * and carries out step 4-5 of the algorithm (origin-aware convergence + snapshot persistence) —
 * see .claude-docs/sync.md.
 *
 * Called from {@see \App\Service\Plugin\PullSyncService} once per pulled item, i.e. one
 * already-fresh participant ($originParticipantId/$originProjection/$originUpdatedAt) at a time
 * — never the full N-way fan-out in one call, because {@see SyncInterface} has no per-title
 * "read current state" call, only bulk pull(). Every *other* active participant this run knows
 * about contributes its last-seen row as a stand-in for "current" instead of a fresh network
 * round trip; the N-way engine underneath still runs over the combined map, so a genuine 3-way
 * (or more) conflict is still caught the moment a second/third source's own pull run supplies its
 * own fresh reading — see the class docblock on SyncReconciler.
 *
 * First contact deliberately does not manufacture a conflict: a participant this run has no
 * {@see AnimeSyncState} row for yet — other than $originParticipantId, whose very lack of a row
 * is itself new information worth reconciling — is assumed to already agree with whatever
 * baseline gets established (i.e. its last-seen is synthesized equal to its own current reading).
 * Without this, every first-ever pull for an anime that already has local watch history would
 * read as an unresolved ">=2 changed, different" conflict instead of the source simply informing
 * local for the first time — connect-seed's own resolution flow (issue #367, "разовый посев")
 * pre-seeding the snapshot before a title ever reaches this class is what is expected to change
 * that going forward, not this class second-guessing a source it has never compared before.
 */
final class SyncConvergenceService
{
    public function __construct(
        private readonly SyncReconciler $reconciler,
        private readonly AnimeSyncStateRepository $stateRepository,
        private readonly SyncRegistry $syncRegistry,
        private readonly SyncReviewService $reviewService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * $entityManager is the one $anime is actually attached to this run — PullSyncService's
     * create-conflict recovery path (issue #297) re-fetches $anime through a throwaway recovery
     * EntityManager once the original is closed, and every write this method makes (the local
     * apply's flush-free in-memory mutation aside) must follow that same instance, or it either
     * no-ops silently or throws EntityManagerClosedException.
     */
    public function reconcilePulledItem(
        Anime $anime,
        string $originParticipantId,
        SyncProjection $originProjection,
        ?\DateTimeImmutable $originUpdatedAt,
        EntityManagerInterface $entityManager,
    ): void {
        $lastSeenRows = $this->stateRepository->findByAnime($anime, $entityManager);
        $lastSeenRowById = [];
        $lastSeen = [];
        foreach ($lastSeenRows as $row) {
            $lastSeenRowById[$row->participantId] = $row;
            $lastSeen[$row->participantId] = new ParticipantState($row->participantId, new SyncProjection($row->lastStatus, $row->lastWatchedEpisodes), $row->lastUpdatedAt);
        }

        $localState = new ParticipantState('local', $this->localProjection($anime), $anime->getWatchProgressUpdatedAt());
        $originState = new ParticipantState($originParticipantId, $originProjection, $originUpdatedAt);

        // Synthesize a same-as-current last-seen for any participant (local included) this run
        // has no real row for yet, other than the origin itself — see the class docblock.
        foreach ([$localState, $originState] as $state) {
            if ($state->participantId !== $originParticipantId && !isset($lastSeen[$state->participantId])) {
                $lastSeen[$state->participantId] = $state;
            }
        }

        $available = [$localState, $originState];
        $otherSyncs = $this->otherActiveResolvableSyncs($anime, $originParticipantId);
        foreach ($otherSyncs as $participantId => $sync) {
            if (isset($lastSeen[$participantId])) {
                $available[] = $lastSeen[$participantId];
            }
        }

        $result = $this->reconciler->reconcile($available, $lastSeen);

        if (!$result->hasChanges) {
            return;
        }

        if ($result->isConflict) {
            $this->flagConflict($anime, $result);
        }

        $targets = $this->reconciler->participantsToConverge($result, $available);

        // Every participant this run actually has a fresh reading for gets its snapshot closed
        // out, whether or not it needed convergence — that is what stops it from re-appearing in
        // the changed set next run for the same value it already reported this one. The origin
        // defaults to its own fresh reading here; it is only overwritten below if it turns out to
        // actually need $result->winner sent back to it (self-healing a drifted origin, issue
        // #366 review — see SyncReconciler::participantsToConverge()'s docblock).
        $confirmed = [$originParticipantId => $originState];
        $confirmed['local'] = \in_array('local', $targets, true) ? $this->applyToLocal($anime, $result) : $localState;

        foreach ($targets as $participantId) {
            if ($participantId === 'local') {
                continue;
            }

            $sync = $participantId === $originParticipantId
                ? $this->syncRegistry->findByPluginId(new PluginId($originParticipantId))
                : ($otherSyncs[$participantId] ?? null);
            if ($sync === null) {
                continue;
            }

            $pushed = $this->pushTo($anime, $participantId, $sync, $result);
            if ($pushed !== null) {
                $confirmed[$participantId] = $pushed;
            }
        }

        foreach ($confirmed as $participantId => $state) {
            $this->persistLastSeen($anime, $participantId, $state, $lastSeenRowById[$participantId] ?? null, $entityManager);
        }
    }

    private function localProjection(Anime $anime): SyncProjection
    {
        return new SyncProjection($anime->getWatchStatus(), $anime instanceof SeriesAnime ? $anime->getWatchedEpisodes() : null);
    }

    /**
     * Applies the winner to $anime via the sync-apply path (Anime::applyWatchProgress(), never
     * the manual-edit one — this is a reconciliation write, not a user edit, see .claude-docs/
     * sync.md's "Различие ручная/синк-правка"), then reads the entity back rather than trusting
     * $result->winner literally: applyWatchProgress() can reject an incoming pair that violates a
     * local invariant (Completed while not yet released, issue #366 pitfall #8) and leaves the
     * entity unchanged in that case — the snapshot must record what is actually true locally
     * (pitfall #7), not what the engine wished were true.
     */
    private function applyToLocal(Anime $anime, ReconciliationResult $result): ParticipantState
    {
        $anime->applyWatchProgress($result->winner->status, $result->winner->watchedEpisodes, $result->winnerUpdatedAt ?? new \DateTimeImmutable());

        return new ParticipantState('local', $this->localProjection($anime), $anime->getWatchProgressUpdatedAt());
    }

    /**
     * A forward-propagation push failing (network error, reauth needed, ...) must not fail the
     * whole pull run over one other, unrelated plugin — logged and skipped, same self-healing
     * stance PullSyncService already takes elsewhere: the target's snapshot is left untouched, so
     * it stays dirty and gets retried the next time this anime is reconciled (issue #366 pitfall
     * #1, "не отравляем снимок").
     */
    private function pushTo(Anime $anime, string $participantId, SyncInterface $sync, ReconciliationResult $result): ?ParticipantState
    {
        $externalId = $anime->getCachedExternalId(new PluginId($participantId));
        if ($externalId === null) {
            return null;
        }

        $item = new SyncItem(
            $externalId,
            WatchStatusMapper::toSyncStatus($result->winner->status),
            $anime->getTitle(),
            updatedAt: $result->winnerUpdatedAt,
            watchedEpisodes: $result->winner->watchedEpisodes,
        );

        try {
            $confirmed = $sync->push($item);
        } catch (\Throwable $exception) {
            $this->logger->warning('Forward-propagation push to sync plugin "{plugin}" failed; leaving its snapshot dirty for the next reconciliation.', [
                'plugin' => $participantId,
                'exception' => $exception,
            ]);

            return null;
        }

        return new ParticipantState(
            $participantId,
            new SyncProjection(WatchStatusMapper::toWatchStatus($confirmed->status), $confirmed->watchedEpisodes),
            $confirmed->updatedAt ?? $result->winnerUpdatedAt,
        );
    }

    /**
     * @return array<string, SyncInterface> active plugins other than $excludeParticipantId that
     *                                      resolve an external id for $anime, keyed by plugin id
     */
    private function otherActiveResolvableSyncs(Anime $anime, string $excludeParticipantId): array
    {
        $syncs = [];
        foreach ($this->syncRegistry->allActive() as $id => $sync) {
            if ($id === $excludeParticipantId) {
                continue;
            }

            if ($anime->getExternalId(new PluginId($id), $sync) !== null) {
                $syncs[$id] = $sync;
            }
        }

        return $syncs;
    }

    private function persistLastSeen(Anime $anime, string $participantId, ParticipantState $state, ?AnimeSyncState $existing, EntityManagerInterface $entityManager): void
    {
        $updatedAt = $state->updatedAt ?? new \DateTimeImmutable();

        if ($existing !== null) {
            $existing->update($state->projection->status, $state->projection->watchedEpisodes, $updatedAt);
            $this->stateRepository->save($existing, $entityManager);

            return;
        }

        $this->stateRepository->save(
            new AnimeSyncState($anime, $participantId, $state->projection->status, $state->projection->watchedEpisodes, $updatedAt),
            $entityManager,
        );
    }

    private function flagConflict(Anime $anime, ReconciliationResult $result): void
    {
        $animeId = $anime->id ?? throw new \LogicException('Anime must have an id at this point in its lifecycle.');

        if ($this->alreadyFlagged($animeId)) {
            return;
        }

        $this->reviewService->create(SyncReviewItemKind::NeedsCorrection, [
            'anime_id' => $animeId,
            'participants' => $result->changedParticipantIds,
            'winner_status' => $result->winner->status->value,
            'winner_watched_episodes' => $result->winner->watchedEpisodes,
        ]);
    }

    private function alreadyFlagged(int $animeId): bool
    {
        foreach ($this->reviewService->findUnresolved() as $item) {
            if ($item->kind === SyncReviewItemKind::NeedsCorrection && ($item->payload['anime_id'] ?? null) === $animeId) {
                return true;
            }
        }

        return false;
    }
}
