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

/**
 * Outcome of {@see SyncReconciler::reconcile()} for one anime (issue #366): the winning
 * projection `W` plus enough bookkeeping for the caller to drive convergence and, on a true
 * conflict, raise a review item.
 */
final readonly class ReconciliationResult
{
    /**
     * @param bool         $hasChanges            false when nobody in this run's changed set
     *                                            existed at all (0 changed) — the winner then
     *                                            just mirrors the current, already-converged
     *                                            state, and the caller has nothing to do
     * @param bool         $isConflict            true only for the ">=2 changed, different
     *                                            values" branch — $winner is a best-effort
     *                                            arbitration, not an agreed value
     * @param list<string> $changedParticipantIds participant ids that contributed to the
     *                                            changed set this run, for the review-item
     *                                            payload on a conflict
     */
    public function __construct(
        public SyncProjection $winner,
        public ?\DateTimeImmutable $winnerUpdatedAt,
        public bool $hasChanges,
        public bool $isConflict,
        public array $changedParticipantIds,
    ) {
    }
}
