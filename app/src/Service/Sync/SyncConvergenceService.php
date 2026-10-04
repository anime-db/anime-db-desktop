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
 * First contact for local specifically (no {@see AnimeSyncState} row for it yet) only skips
 * manufacturing a conflict when local is provably virgin — {@see Anime::getWatchProgressUpdatedAt()}
 * is still null, meaning nothing (no manual edit, no prior sync apply, not even the
 * Version20260812000000 backfill for pre-existing rows) has ever recorded real watch progress for
 * it — in which case local's last-seen is synthesized equal to its own current reading, so a
 * brand-new title's very first pull is not read as a conflict against a baseline that was never
 * real data to begin with.
 *
 * An existing title with actual watch history reaching this class with no snapshot row yet (an
 * old title predating this feature, or one added after connect-seed's own one-shot posev, issue
 * #367, already ran) is deliberately NOT given this treatment: if its real local projection
 * disagrees with $originParticipantId's incoming one, both are left in the changed set, which the
 * engine surfaces as a genuine ">=2 changed, different" conflict (persistent review-item) instead
 * of silently overwriting local's history with the source's value (issue #366 review, "первый
 * контакт затирает локаль").
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

        // Synthesize a same-as-current last-seen for local only when it is provably virgin (no
        // real watch progress ever recorded) and only has no row yet — see the class docblock.
        // The origin itself is never synthesized: its own lack of a row is new information worth
        // reconciling, not something to assume agreement on.
        if (!isset($lastSeen['local']) && $anime->getWatchProgressUpdatedAt() === null) {
            $lastSeen['local'] = $localState;
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
            $this->flagConflict($anime, $result, $available);
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

            $pushed = $this->pushTo($anime, $participantId, $sync, $result->winner, $result->winnerUpdatedAt);
            if ($pushed !== null) {
                $confirmed[$participantId] = $pushed;
            }
        }

        $this->persistConfirmedState($anime, $confirmed, $lastSeenRowById, $entityManager);
    }

    /**
     * Applies a user's own choice from the sync results page (issue #367) — the third arbitration
     * path alongside a clean reconcile() winner and a best-effort conflict pick: here the human
     * *is* the arbiter, so there is no ReconciliationResult to drive convergence from, only the
     * projection they picked.
     *
     * Pinning (issue #380) is deliberately not a stored flag: applying $chosen through
     * {@see Anime::applyWatchProgress()} with $updatedAt = now() makes local the most recently
     * updated participant, so it wins the next arbitration on its own — see .claude-docs/sync.md's
     * "Алгоритм реконсиляции" step 3 (max updatedAt) — for as long as no source makes a *later*
     * edit of its own.
     *
     * Every other participant is forwarded the *actually applied* local projection/timestamp
     * (read back from $anime after applyWatchProgress()), never $chosen itself: a pair that
     * violates a local invariant (Completed while not yet released) is rejected by
     * applyWatchProgress() — local stays unchanged and unpinned — and forwarding the rejected
     * $chosen anyway would push a value local itself never actually holds, only for the next
     * reconcile to see that fresher-but-unpinned remote value and recreate the very conflict the
     * user just tried to resolve.
     *
     * "Diverging" is judged against each participant's last-seen snapshot (issue #365), not a
     * fresh network read — {@see SyncInterface} has no per-title "read current state" call, same
     * constraint {@see reconcilePulledItem()} works under. A participant with no snapshot row yet
     * is treated as diverging (unknown is not "agrees"), same stance {@see SyncReconciler} takes
     * for an absent $lastSeen entry.
     *
     * Returns false without touching any snapshot or forward-propagating anything when $chosen
     * itself violates a local invariant (Completed while not yet released) — applyWatchProgress()
     * rejects it silently (leaves $anime unchanged, flags getWatchProgressRejectedAt() instead of
     * throwing), so the caller must check the return value to tell an applied pick from a
     * rejected one rather than assuming success.
     */
    public function applyManualResolution(Anime $anime, SyncProjection $chosen, EntityManagerInterface $entityManager): bool
    {
        $now = new \DateTimeImmutable();
        $anime->applyWatchProgress($chosen->status, $chosen->watchedEpisodes, $now);

        if ($anime->getWatchProgressRejectedAt() !== null) {
            return false;
        }

        $lastSeenRowById = [];
        foreach ($this->stateRepository->findByAnime($anime, $entityManager) as $row) {
            $lastSeenRowById[$row->participantId] = $row;
        }

        $appliedProjection = $this->localProjection($anime);
        $appliedUpdatedAt = $anime->getWatchProgressUpdatedAt();
        $confirmed = ['local' => new ParticipantState('local', $appliedProjection, $appliedUpdatedAt)];

        foreach ($this->syncRegistry->allActive() as $participantId => $sync) {
            $externalId = $anime->getCachedExternalId(new PluginId($participantId));
            if ($externalId === null) {
                continue;
            }

            $lastSeenRow = $lastSeenRowById[$participantId] ?? null;
            $current = $lastSeenRow !== null ? new SyncProjection($lastSeenRow->lastStatus, $lastSeenRow->lastWatchedEpisodes) : null;
            if ($current !== null && $current->equals($appliedProjection)) {
                continue;
            }

            $pushed = $this->pushTo($anime, $participantId, $sync, $appliedProjection, $appliedUpdatedAt);
            if ($pushed !== null) {
                $confirmed[$participantId] = $pushed;
            }
        }

        $this->persistConfirmedState($anime, $confirmed, $lastSeenRowById, $entityManager);

        return true;
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
    private function pushTo(Anime $anime, string $participantId, SyncInterface $sync, SyncProjection $projection, ?\DateTimeImmutable $updatedAt): ?ParticipantState
    {
        $externalId = $anime->getCachedExternalId(new PluginId($participantId));
        if ($externalId === null) {
            return null;
        }

        $item = new SyncItem(
            $externalId,
            WatchStatusMapper::toSyncStatus($projection->status),
            $anime->getTitle(),
            updatedAt: $updatedAt,
            watchedEpisodes: $projection->watchedEpisodes,
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
            $confirmed->updatedAt ?? $updatedAt,
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

    /**
     * Closes out every confirmed participant's snapshot for this item as a single atomic unit
     * (issue #859 review): {@see reconcilePulledItem()}/{@see applyManualResolution()} may
     * confirm several participants (origin, local, every forward-propagation target), each
     * persisted through its own {@see AnimeSyncStateRepository::save()} call, which flushes on
     * its own. Without an enclosing transaction, a later participant's failed flush leaves an
     * earlier one already durably committed — including $anime's own in-memory change, applied
     * before this method ever runs — so the item ends up half-applied instead of rolled back.
     * {@see EntityManagerInterface::wrapInTransaction()} turns every nested flush() here into a
     * savepoint inside one outer transaction instead: any exception rolls all of them back
     * together and leaves $entityManager closed (Doctrine's own reaction to a failed commit),
     * which is exactly the signal the per-item recovery in {@see
     * \App\Service\Plugin\PullSyncService} already watches for.
     *
     * @param array<string, ParticipantState> $confirmed
     * @param array<string, AnimeSyncState>   $lastSeenRowById
     */
    private function persistConfirmedState(Anime $anime, array $confirmed, array $lastSeenRowById, EntityManagerInterface $entityManager): void
    {
        $entityManager->wrapInTransaction(function () use ($anime, $confirmed, $lastSeenRowById, $entityManager): void {
            foreach ($confirmed as $participantId => $state) {
                $this->persistLastSeen($anime, $participantId, $state, $lastSeenRowById[$participantId] ?? null, $entityManager);
            }
        });
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

    /**
     * $available is the same list {@see reconcilePulledItem()} already built for reconcile()
     * itself — reused here rather than re-derived so 'candidates' reflects exactly the readings
     * the engine actually arbitrated over, not a fresh (and possibly different) lookup.
     *
     * @param list<ParticipantState> $available
     */
    private function flagConflict(Anime $anime, ReconciliationResult $result, array $available): void
    {
        $animeId = $anime->id ?? throw new \LogicException('Anime must have an id at this point in its lifecycle.');

        if ($this->alreadyFlagged($animeId)) {
            return;
        }

        $candidates = [];
        foreach ($available as $state) {
            if (!\in_array($state->participantId, $result->changedParticipantIds, true)) {
                continue;
            }

            $candidates[] = [
                'participant_id' => $state->participantId,
                'status' => $state->projection->status->value,
                'watched_episodes' => $state->projection->watchedEpisodes,
                'updated_at' => $state->updatedAt?->getTimestamp(),
            ];
        }

        $this->reviewService->create(SyncReviewItemKind::NeedsCorrection, [
            'anime_id' => $animeId,
            'participants' => $result->changedParticipantIds,
            'winner_status' => $result->winner->status->value,
            'winner_watched_episodes' => $result->winner->watchedEpisodes,
            'candidates' => $candidates,
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
