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

use App\Entity\Enum\WatchStatus;

/**
 * The N-way sync reconciliation engine (issue #366; 2-way is the degenerate N=2 case) — see
 * .claude-docs/sync.md's "Алгоритм реконсиляции" for the full write-up this class implements.
 *
 * Deliberately pure: no ORM, no I/O, no knowledge of *how* the caller collected a participant's
 * "current" reading (a freshly pulled {@see \AnimeDb\PluginContracts\Sync\SyncItem}, local's own
 * live state, or a stand-in taken from a not-freshly-observed participant's own last-seen row —
 * see {@see SyncConvergenceService}, which does carry that I/O). Both $available
 * and $lastSeen use the same {@see ParticipantState} shape for exactly that reason: the algorithm
 * below diffs "current" against "last-seen" uniformly, whatever each one's provenance.
 *
 * A participant absent from $available (an offline source, or one this run never queried) simply
 * never enters the changed set and is never a convergence target — its last-seen is left for the
 * caller to leave untouched, which is what closes it out on a later run (issue #366 pitfall
 * "недоступный участник").
 */
final class SyncReconciler
{
    /**
     * Steps 2-3 of the algorithm: the changed set and the winner `W`.
     *
     * A participant with no entry in $lastSeen is treated as changed — "current != last-seen" is
     * vacuously true with no baseline to compare against. The one deliberate exception is the
     * caller's own choice of what to pass as $lastSeen: {@see SyncConvergenceService} synthesizes
     * a same-as-current last-seen entry for a participant it has no real snapshot row for yet
     * (other than the one whose fresh pull this run is actually about), so a title's first-ever
     * contact with a second source does not itself manufacture a conflict — see that class for
     * the rationale.
     *
     * @param list<ParticipantState>          $available every participant this run has a
     *                                                   "current" reading for
     * @param array<string, ParticipantState> $lastSeen  previously-reconciled state, keyed by
     *                                                   participant id
     */
    public function reconcile(array $available, array $lastSeen): ReconciliationResult
    {
        if ($available === []) {
            throw new \LogicException('reconcile() requires at least one available participant.');
        }

        $changed = [];
        foreach ($available as $state) {
            if ($this->hasChangedSinceLastSeen($state, $lastSeen[$state->participantId] ?? null)) {
                $changed[] = $state;
            }
        }

        if ($changed === []) {
            $current = $available[0];

            return new ReconciliationResult($current->projection, $current->updatedAt, hasChanges: false, isConflict: false, changedParticipantIds: []);
        }

        $changedIds = array_map(static fn (ParticipantState $state): string => $state->participantId, $changed);

        if (\count($changed) === 1) {
            $only = $changed[0];

            return new ReconciliationResult($only->projection, $only->updatedAt, hasChanges: true, isConflict: false, changedParticipantIds: $changedIds);
        }

        $distinctProjections = $this->distinctProjections($changed);

        if (\count($distinctProjections) === 1) {
            return new ReconciliationResult(
                $distinctProjections[0],
                $this->latestUpdatedAt($changed),
                hasChanges: true,
                isConflict: false,
                changedParticipantIds: $changedIds,
            );
        }

        // True conflict (step 3, ">=2 changed, different values"): best-effort arbitration by
        // max updatedAt. A null updatedAt is the oldest possible value (contract convention, see
        // SyncItem::$updatedAt) and so never displaces a non-null pick; ties (including
        // all-null) resolve to the first participant in $available's own order, for a
        // deterministic result.
        $arbiter = $changed[0];
        foreach ($changed as $state) {
            if ($this->isNewer($state->updatedAt, $arbiter->updatedAt)) {
                $arbiter = $state;
            }
        }

        return new ReconciliationResult($arbiter->projection, $arbiter->updatedAt, hasChanges: true, isConflict: true, changedParticipantIds: $changedIds);
    }

    /**
     * Step 4's "who needs W sent to them": every available participant whose current projection
     * still differs from the winner — the origin (the participant whose own fresh observation
     * this run's reconciliation was triggered by, if any) is not special-cased here. The
     * pull->push echo (issue #352) is already broken by this same equals() check: whenever the
     * origin's own reading is the (sole, or unanimous) reason `W` was picked, `W` literally
     * equals its projection, so it is excluded exactly like any other already-agreeing
     * participant. Genuine divergence — the origin's own snapshot having drifted from `W`,
     * e.g. after a dropped push-on-edit TTL (see PushSyncMessageHandler) — is left in the
     * target list on purpose: the origin still needs `W` sent back to it, or the drift never
     * self-heals (issue #366 review).
     *
     * @param list<ParticipantState> $available
     *
     * @return list<string>
     */
    public function participantsToConverge(ReconciliationResult $result, array $available): array
    {
        if (!$result->hasChanges) {
            return [];
        }

        $targets = [];
        foreach ($available as $state) {
            if (!$state->projection->equals($result->winner)) {
                $targets[] = $state->participantId;
            }
        }

        return $targets;
    }

    private function hasChangedSinceLastSeen(ParticipantState $current, ?ParticipantState $lastSeen): bool
    {
        if ($lastSeen === null) {
            return true;
        }

        if (!$current->projection->equals($lastSeen->projection)) {
            return true;
        }

        return $current->updatedAt !== null && ($lastSeen->updatedAt === null || $current->updatedAt > $lastSeen->updatedAt);
    }

    private function isNewer(?\DateTimeImmutable $candidate, ?\DateTimeImmutable $current): bool
    {
        if ($candidate === null) {
            return false;
        }

        return $current === null || $candidate > $current;
    }

    /** @param list<ParticipantState> $states */
    private function latestUpdatedAt(array $states): ?\DateTimeImmutable
    {
        $latest = null;
        foreach ($states as $state) {
            if ($this->isNewer($state->updatedAt, $latest)) {
                $latest = $state->updatedAt;
            }
        }

        return $latest;
    }

    /**
     * Groups $changed's projections by SyncProjection::equals() (status equal, episodes equal or
     * either side null) rather than by literal (status, episodes) pairs — a participant that
     * didn't report episodes must not manufacture a distinct group of its own against one that
     * did (issue #366 review, "null-эпизоды не должны порождать различие"). Within one status,
     * only two different *reported* episode counts are genuinely distinct; a group that mixes a
     * null reading with a single reported one collapses to that reported value, never staying
     * null, so a known episode count is never lost behind a participant that simply didn't send
     * one.
     *
     * @param list<ParticipantState> $changed
     *
     * @return list<SyncProjection>
     */
    private function distinctProjections(array $changed): array
    {
        /** @var array<string, array{status: WatchStatus, episodes: array<int, true>}> $byStatus */
        $byStatus = [];
        foreach ($changed as $state) {
            $key = $state->projection->status->value;
            $byStatus[$key] ??= ['status' => $state->projection->status, 'episodes' => []];

            if ($state->projection->watchedEpisodes !== null) {
                $byStatus[$key]['episodes'][$state->projection->watchedEpisodes] = true;
            }
        }

        $distinct = [];
        foreach ($byStatus as $group) {
            if ($group['episodes'] === []) {
                $distinct[] = new SyncProjection($group['status'], null);
                continue;
            }

            foreach (array_keys($group['episodes']) as $episodes) {
                $distinct[] = new SyncProjection($group['status'], $episodes);
            }
        }

        return $distinct;
    }
}
