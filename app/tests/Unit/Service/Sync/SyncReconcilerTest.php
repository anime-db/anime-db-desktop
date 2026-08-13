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

namespace App\Tests\Unit\Service\Sync;

use App\Entity\Enum\WatchStatus;
use App\Service\Sync\ParticipantState;
use App\Service\Sync\SyncProjection;
use App\Service\Sync\SyncReconciler;
use PHPUnit\Framework\TestCase;

/**
 * Direct unit coverage of the pure decision engine (issue #366 review): every branch of
 * reconcile() (changed-set formation, the 0/1/>=2-same/>=2-different winner selection,
 * max-updatedAt arbitration with its null semantics and deterministic tie-break) and of
 * participantsToConverge() (echo avoidance and the origin-drift self-healing case), none of
 * which the integration-level PullSyncServiceTest exercises beyond a single-participant
 * happy path.
 */
final class SyncReconcilerTest extends TestCase
{
    private SyncReconciler $reconciler;

    protected function setUp(): void
    {
        $this->reconciler = new SyncReconciler();
    }

    public function testReconcileRequiresAtLeastOneAvailableParticipant(): void
    {
        $this->expectException(\LogicException::class);

        $this->reconciler->reconcile([], []);
    }

    public function testZeroChangedReturnsTheCurrentAlreadyConvergedState(): void
    {
        $local = $this->state('local', WatchStatus::Watching, 5, '2026-01-01');

        $result = $this->reconciler->reconcile([$local], ['local' => $local]);

        $this->assertFalse($result->hasChanges);
        $this->assertFalse($result->isConflict);
        $this->assertTrue($result->winner->equals($local->projection));
        $this->assertSame([], $result->changedParticipantIds);
    }

    public function testAParticipantWithNoLastSeenRowIsTreatedAsChanged(): void
    {
        $local = $this->state('local', WatchStatus::Watching, 5, '2026-01-01');

        $result = $this->reconciler->reconcile([$local], []);

        $this->assertTrue($result->hasChanges);
        $this->assertFalse($result->isConflict);
        $this->assertSame(['local'], $result->changedParticipantIds);
    }

    /**
     * hasChangedSinceLastSeen() treats "projection differs" and "updatedAt moved on with the
     * same projection" as two independent triggers (pitfall #3, "грубость updatedAt") — a
     * same-projection reaffirmation with a newer timestamp still counts as changed.
     */
    public function testSameProjectionWithANewerUpdatedAtStillCountsAsChanged(): void
    {
        $lastSeen = $this->state('local', WatchStatus::Watching, 5, '2026-01-01');
        $current = $this->state('local', WatchStatus::Watching, 5, '2026-01-02');

        $result = $this->reconciler->reconcile([$current], ['local' => $lastSeen]);

        $this->assertTrue($result->hasChanges);
        $this->assertSame(['local'], $result->changedParticipantIds);
    }

    public function testSameProjectionWithANullUpdatedAtIsNotChanged(): void
    {
        $lastSeen = $this->state('local', WatchStatus::Watching, 5, '2026-01-01');
        $current = new ParticipantState('local', new SyncProjection(WatchStatus::Watching, 5), null);

        $result = $this->reconciler->reconcile([$current], ['local' => $lastSeen]);

        $this->assertFalse($result->hasChanges);
    }

    public function testSingleChangedParticipantWins(): void
    {
        $local = $this->state('local', WatchStatus::Plan, null, '2026-01-01');
        $shiki = $this->state('animedb-shikimori', WatchStatus::Watching, 3, '2026-01-02');

        $result = $this->reconciler->reconcile(
            [$local, $shiki],
            ['local' => $local, 'animedb-shikimori' => $this->state('animedb-shikimori', WatchStatus::Plan, null, '2026-01-01')],
        );

        $this->assertTrue($result->hasChanges);
        $this->assertFalse($result->isConflict);
        $this->assertSame(WatchStatus::Watching, $result->winner->status);
        $this->assertSame(3, $result->winner->watchedEpisodes);
        $this->assertSame(['animedb-shikimori'], $result->changedParticipantIds);
    }

    /**
     * >=2 changed, all agreeing on the same new value: not a conflict, the winner is that
     * shared value and its updatedAt is the latest among the contributors (not an arbitrary one).
     */
    public function testMultipleChangedParticipantsAgreeingOnTheSameValueIsNotAConflict(): void
    {
        $lastSeen = $this->state('x', WatchStatus::Plan, null, '2026-01-01');

        $local = $this->state('local', WatchStatus::Watching, 5, '2026-01-03');
        $shiki = $this->state('animedb-shikimori', WatchStatus::Watching, 5, '2026-01-02');

        $result = $this->reconciler->reconcile(
            [$local, $shiki],
            ['local' => $lastSeen, 'animedb-shikimori' => $lastSeen],
        );

        $this->assertTrue($result->hasChanges);
        $this->assertFalse($result->isConflict);
        $this->assertSame(WatchStatus::Watching, $result->winner->status);
        $this->assertSame(5, $result->winner->watchedEpisodes);
        $this->assertSame('2026-01-03', $result->winnerUpdatedAt?->format('Y-m-d'));
    }

    /**
     * A participant that doesn't report episode progress at all (null, e.g. a plugin ahead of
     * anime-db-plugins#46) must not manufacture a conflict against one that does — "not reported"
     * is not "a different value" (issue #366 review, "null-эпизоды не должны порождать
     * различие"). The merged winner takes the reported episode count, not null.
     */
    public function testANullEpisodesReadingAgreesWithAReportedOneInsteadOfConflicting(): void
    {
        $lastSeen = $this->state('x', WatchStatus::Plan, null, '2026-01-01');

        $local = $this->state('local', WatchStatus::Watching, 6, '2026-01-02');
        $shiki = $this->state('animedb-shikimori', WatchStatus::Watching, null, '2026-01-03');

        $result = $this->reconciler->reconcile(
            [$local, $shiki],
            ['local' => $lastSeen, 'animedb-shikimori' => $lastSeen],
        );

        $this->assertTrue($result->hasChanges);
        $this->assertFalse($result->isConflict);
        $this->assertSame(WatchStatus::Watching, $result->winner->status);
        $this->assertSame(6, $result->winner->watchedEpisodes);
    }

    /**
     * True conflict (step 3, ">=2 changed, different values"): best-effort arbitration picks
     * the participant with the latest updatedAt.
     */
    public function testTrueConflictArbitratesByMaxUpdatedAt(): void
    {
        $lastSeen = $this->state('x', WatchStatus::Plan, null, '2026-01-01');

        $local = $this->state('local', WatchStatus::Watching, 5, '2026-01-02');
        $shiki = $this->state('animedb-shikimori', WatchStatus::Completed, 12, '2026-01-05');

        $result = $this->reconciler->reconcile(
            [$local, $shiki],
            ['local' => $lastSeen, 'animedb-shikimori' => $lastSeen],
        );

        $this->assertTrue($result->hasChanges);
        $this->assertTrue($result->isConflict);
        $this->assertSame(WatchStatus::Completed, $result->winner->status);
        $this->assertSame(12, $result->winner->watchedEpisodes);
        $this->assertSame(['local', 'animedb-shikimori'], $result->changedParticipantIds);
    }

    /**
     * A null updatedAt is the oldest possible value (contract convention) and never displaces
     * a non-null pick, whichever side of the comparison it is on.
     */
    public function testANullUpdatedAtNeverDisplacesANonNullOneInConflictArbitration(): void
    {
        $lastSeen = $this->state('x', WatchStatus::Plan, null, '2026-01-01');

        $local = new ParticipantState('local', new SyncProjection(WatchStatus::Watching, 5), null);
        $shiki = $this->state('animedb-shikimori', WatchStatus::Completed, 12, '2026-01-05');

        $result = $this->reconciler->reconcile(
            [$local, $shiki],
            ['local' => $lastSeen, 'animedb-shikimori' => $lastSeen],
        );

        $this->assertTrue($result->isConflict);
        $this->assertSame(WatchStatus::Completed, $result->winner->status);
        $this->assertSame('2026-01-05', $result->winnerUpdatedAt?->format('Y-m-d'));
    }

    /**
     * Ties (including all-null updatedAt) resolve to the first participant in $available's own
     * order, for a deterministic result — never "whichever PHP array-iterated last".
     */
    public function testATieOnUpdatedAtResolvesToTheFirstParticipantInAvailableOrder(): void
    {
        $lastSeen = $this->state('x', WatchStatus::Plan, null, '2026-01-01');

        $first = new ParticipantState('local', new SyncProjection(WatchStatus::Watching, 5), null);
        $second = new ParticipantState('animedb-shikimori', new SyncProjection(WatchStatus::Completed, 12), null);

        $result = $this->reconciler->reconcile(
            [$first, $second],
            ['local' => $lastSeen, 'animedb-shikimori' => $lastSeen],
        );

        $this->assertTrue($result->isConflict);
        $this->assertSame(WatchStatus::Watching, $result->winner->status);
        $this->assertSame(5, $result->winner->watchedEpisodes);
    }

    public function testParticipantsToConvergeReturnsNoTargetsWhenNothingChanged(): void
    {
        $local = $this->state('local', WatchStatus::Watching, 5, '2026-01-01');
        $result = $this->reconciler->reconcile([$local], ['local' => $local]);

        $targets = $this->reconciler->participantsToConverge($result, [$local]);

        $this->assertSame([], $targets);
    }

    /**
     * Echo avoidance (issue #352): when the origin is the sole changed contributor, the winner
     * literally equals its own projection, so the plain equals() filter excludes it from the
     * target list on its own — no explicit "except origin" special case needed.
     */
    public function testTheSoleChangedParticipantIsNotItsOwnConvergenceTarget(): void
    {
        $lastSeen = $this->state('animedb-shikimori', WatchStatus::Plan, null, '2026-01-01');
        $local = $this->state('local', WatchStatus::Plan, null, '2026-01-01');
        $shiki = $this->state('animedb-shikimori', WatchStatus::Watching, 3, '2026-01-02');

        $result = $this->reconciler->reconcile([$local, $shiki], ['local' => $local, 'animedb-shikimori' => $lastSeen]);
        $targets = $this->reconciler->participantsToConverge($result, [$local, $shiki]);

        $this->assertSame(['local'], $targets);
    }

    /**
     * Correctness regression (issue #366 review, pitfall #17): the origin must still be a
     * convergence target when its own current snapshot has drifted from the winner picked by
     * someone else — e.g. a manual local edit whose push-on-edit was dropped by the TTL, so the
     * origin's remote copy is stale. Excluding the origin unconditionally here would mean this
     * drift never self-heals on a single-plugin configuration (the origin, having lost its
     * push, would never re-appear as a target of its own pull).
     */
    public function testAnOriginThatHasDriftedFromTheWinnerIsStillAConvergenceTarget(): void
    {
        $staleShiki = $this->state('animedb-shikimori', WatchStatus::Plan, null, '2026-01-01');
        // The origin's own fresh pull reports the same stale value the snapshot already has —
        // it did not change, but local raced ahead of it (a dropped push-on-edit).
        $local = $this->state('local', WatchStatus::Watching, 4, '2026-01-05');

        $result = $this->reconciler->reconcile(
            [$local, $staleShiki],
            ['local' => $this->state('local', WatchStatus::Plan, null, '2026-01-01'), 'animedb-shikimori' => $staleShiki],
        );
        $targets = $this->reconciler->participantsToConverge($result, [$local, $staleShiki]);

        $this->assertTrue($result->hasChanges);
        $this->assertFalse($result->isConflict);
        $this->assertSame(WatchStatus::Watching, $result->winner->status);
        $this->assertSame(['animedb-shikimori'], $targets);
    }

    private function state(string $participantId, WatchStatus $status, ?int $watchedEpisodes, string $updatedAt): ParticipantState
    {
        return new ParticipantState($participantId, new SyncProjection($status, $watchedEpisodes), new \DateTimeImmutable($updatedAt));
    }
}
