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

        $distinctProjections = [];
        foreach ($changed as $state) {
            $distinctProjections[$this->projectionKey($state->projection)] ??= $state->projection;
        }

        if (\count($distinctProjections) === 1) {
            return new ReconciliationResult(
                reset($distinctProjections),
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
     * still differs from the winner, except $originParticipantId — the participant whose own
     * fresh observation this run's reconciliation was triggered by, if any (a routine periodic
     * pass over already-agreeing participants has no single origin). Excluding it is what breaks
     * the pull->push echo (issue #352) while still allowing forward propagation to every other
     * receiver (issue #366 pitfall #2).
     *
     * @param list<ParticipantState> $available
     *
     * @return list<string>
     */
    public function participantsToConverge(ReconciliationResult $result, array $available, ?string $originParticipantId): array
    {
        if (!$result->hasChanges) {
            return [];
        }

        $targets = [];
        foreach ($available as $state) {
            if ($state->participantId === $originParticipantId) {
                continue;
            }

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

    private function projectionKey(SyncProjection $projection): string
    {
        return $projection->status->value.'|'.($projection->watchedEpisodes ?? 'null');
    }
}
