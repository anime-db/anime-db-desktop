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

namespace App\Tests\Unit\Entity;

use App\Entity\Enum\AnimeType;
use App\Entity\Enum\WatchStatus;
use App\Entity\Exception\InvalidEpisodeCountException;
use App\Entity\MusicAnime;
use App\Entity\OnaAnime;
use App\Entity\OvaAnime;
use App\Entity\SeriesAnime;
use App\Entity\SpecialAnime;
use App\Entity\TvAnime;
use App\Event\WatchProgressChangedManuallyEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the episode-tracking logic shared by every SeriesAnime leaf (TvAnime/OvaAnime/
 * OnaAnime/SpecialAnime/MusicAnime). The detailed behaviour is exercised once against
 * TvAnime as a representative; testConcreteSubtypeInheritsEpisodeTracking() then confirms
 * via a data provider that all five concrete leaves inherit it, without repeating the
 * full logic five times (see leaves are intentionally empty markers, .claude-docs/decisions.md).
 */
final class SeriesAnimeTest extends TestCase
{
    public function testGetType(): void
    {
        $anime = new TvAnime();

        $this->assertSame(AnimeType::Tv, $anime->getType());
    }

    public function testSetWatchedEpisodesRejectsNegativeValue(): void
    {
        $anime = new TvAnime();

        $this->expectException(InvalidEpisodeCountException::class);
        $anime->setWatchedEpisodes(-1);
    }

    public function testSetWatchedEpisodesRejectsValueAboveEpisodesCount(): void
    {
        $anime = new TvAnime();
        $anime->setEpisodesCount(12);

        $this->expectException(InvalidEpisodeCountException::class);
        $anime->setWatchedEpisodes(13);
    }

    /**
     * Regression coverage for issue #371 ("camp #5" episode-only edit, this time via the
     * manual-edit domain method rather than a Doctrine changed-field check): a SeriesAnime
     * episode-only edit must record WatchProgressChangedManuallyEvent even when watchStatus
     * itself never moves — the event's current/previous watchStatus are equal in that case,
     * since watchedEpisodes, not watchStatus, is what triggered it.
     */
    public function testChangeWatchedEpisodesManuallyRecordsWatchProgressChangedManuallyEvent(): void
    {
        $anime = new TvAnime();
        $anime->setEpisodesCount(12);
        $anime->setWatchStatus(WatchStatus::Watching);
        $anime->setWatchedEpisodes(5);
        $anime->releaseEvents();

        $anime->changeWatchedEpisodesManually(6);

        $this->assertSame(6, $anime->getWatchedEpisodes());
        $events = $anime->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(WatchProgressChangedManuallyEvent::class, $events[0]);
        $this->assertSame(WatchStatus::Watching, $events[0]->currentWatchStatus);
        $this->assertSame(WatchStatus::Watching, $events[0]->previousWatchStatus);
    }

    /**
     * Regression coverage for issue #366 (review): a manual episode-count edit must stamp
     * watchProgressUpdatedAt := now() the same way changeWatchStatusManually() does — the
     * reconciliation engine's changed-set detection diffs against this timestamp.
     */
    public function testChangeWatchedEpisodesManuallyStampsWatchProgressUpdatedAtToNow(): void
    {
        $anime = new TvAnime();
        $anime->setEpisodesCount(12);
        $anime->setWatchStatus(WatchStatus::Watching);
        $anime->setWatchedEpisodes(5);
        $anime->releaseEvents();
        $initial = $anime->getWatchProgressUpdatedAt();
        $this->assertNull($initial);

        $before = new \DateTimeImmutable();
        $anime->changeWatchedEpisodesManually(6);
        $after = new \DateTimeImmutable();

        $stamped = $anime->getWatchProgressUpdatedAt();
        $this->assertNotNull($stamped);
        $this->assertGreaterThanOrEqual($before, $stamped);
        $this->assertLessThanOrEqual($after, $stamped);
    }

    public function testChangeWatchedEpisodesManuallyDoesNotRecordAnEventWhenUnchanged(): void
    {
        $anime = new TvAnime();
        $anime->setEpisodesCount(12);
        $anime->setWatchStatus(WatchStatus::Watching);
        $anime->setWatchedEpisodes(5);
        $anime->releaseEvents();

        $anime->changeWatchedEpisodesManually(5);

        $this->assertSame([], $anime->releaseEvents());
        $this->assertNull($anime->getWatchProgressUpdatedAt());
    }

    public function testWatchNextEpisodeManuallyRecordsWatchProgressChangedManuallyEvent(): void
    {
        $anime = new TvAnime();
        $anime->setEpisodesCount(12);
        $anime->setWatchStatus(WatchStatus::Plan);
        $anime->releaseEvents();

        $anime->watchNextEpisodeManually();

        $this->assertSame(1, $anime->getWatchedEpisodes());
        $this->assertCount(1, $anime->releaseEvents());
    }

    public function testPlainSetWatchedEpisodesAndWatchNextEpisodeDoNotRecordAnEvent(): void
    {
        $anime = new TvAnime();
        $anime->setEpisodesCount(12);
        $anime->setWatchStatus(WatchStatus::Plan);

        $anime->setWatchedEpisodes(3);
        $anime->watchNextEpisode();

        $this->assertSame([], $anime->releaseEvents());
    }

    public function testWatchNextEpisodeIncrementsWatchedEpisodes(): void
    {
        $anime = new TvAnime();
        $anime->setEpisodesCount(12);
        $anime->setWatchStatus(WatchStatus::Plan);

        $anime->watchNextEpisode();

        $this->assertSame(1, $anime->getWatchedEpisodes());
        $this->assertSame(WatchStatus::Watching, $anime->getWatchStatus());
    }

    public function testWatchNextEpisodeMovesToCompletedOnLastEpisode(): void
    {
        $anime = new TvAnime();
        $anime->setDateEnd(new \DateTimeImmutable('-1 day'));
        $anime->setEpisodesCount(2);
        $anime->setWatchStatus(WatchStatus::Watching);
        $anime->setWatchedEpisodes(1);

        $anime->watchNextEpisode();

        $this->assertSame(2, $anime->getWatchedEpisodes());
        $this->assertSame(WatchStatus::Completed, $anime->getWatchStatus());
    }

    public function testWatchNextEpisodeStaysWatchingWhenOngoingSeriesCatchesUp(): void
    {
        $anime = new TvAnime();
        $anime->setDatePremiere(new \DateTimeImmutable('-1 day'));
        $anime->setEpisodesCount(2);
        $anime->setWatchStatus(WatchStatus::Watching);
        $anime->setWatchedEpisodes(1);

        $anime->watchNextEpisode();

        $this->assertSame(2, $anime->getWatchedEpisodes());
        $this->assertSame(WatchStatus::Watching, $anime->getWatchStatus());
    }

    public function testWatchNextEpisodeMovesToWatchingWhenResumingDropped(): void
    {
        $anime = new TvAnime();
        $anime->setEpisodesCount(12);
        $anime->setWatchStatus(WatchStatus::Dropped);
        $anime->setWatchedEpisodes(3);

        $anime->watchNextEpisode();

        $this->assertSame(4, $anime->getWatchedEpisodes());
        $this->assertSame(WatchStatus::Watching, $anime->getWatchStatus());
    }

    public function testWatchNextEpisodeMovesToWatchingWhenRewatchingCompleted(): void
    {
        $anime = new TvAnime();
        $anime->setDateEnd(new \DateTimeImmutable('-1 day'));
        $anime->setEpisodesCount(12);
        $anime->setWatchStatus(WatchStatus::Completed);
        $anime->setWatchedEpisodes(0);

        $anime->watchNextEpisode();

        $this->assertSame(1, $anime->getWatchedEpisodes());
        $this->assertSame(WatchStatus::Watching, $anime->getWatchStatus());
    }

    public function testSetWatchedEpisodesMovesToWatchingDirectly(): void
    {
        $anime = new TvAnime();
        $anime->setEpisodesCount(12);
        $anime->setWatchStatus(WatchStatus::Plan);

        $anime->setWatchedEpisodes(3);

        $this->assertSame(WatchStatus::Watching, $anime->getWatchStatus());
    }

    public function testSetWatchedEpisodesMovesToCompletedDirectly(): void
    {
        $anime = new TvAnime();
        $anime->setDateEnd(new \DateTimeImmutable('-1 day'));
        $anime->setEpisodesCount(12);
        $anime->setWatchStatus(WatchStatus::Watching);

        $anime->setWatchedEpisodes(12);

        $this->assertSame(WatchStatus::Completed, $anime->getWatchStatus());
    }

    public function testSetWatchedEpisodesStaysWatchingWhenOngoingSeriesCatchesUp(): void
    {
        $anime = new TvAnime();
        $anime->setDatePremiere(new \DateTimeImmutable('-1 day'));
        $anime->setEpisodesCount(12);
        $anime->setWatchStatus(WatchStatus::Watching);

        $anime->setWatchedEpisodes(12);

        $this->assertSame(WatchStatus::Watching, $anime->getWatchStatus());
    }

    public function testWatchNextEpisodeRejectsGoingPastEpisodesCount(): void
    {
        $anime = new TvAnime();
        $anime->setDateEnd(new \DateTimeImmutable('-1 day'));
        $anime->setEpisodesCount(1);
        $anime->setWatchStatus(WatchStatus::Completed);
        $anime->setWatchedEpisodes(1);

        $this->expectException(InvalidEpisodeCountException::class);
        $anime->watchNextEpisode();
    }

    public function testSetEpisodesCountRejectsLoweringBelowWatchedEpisodes(): void
    {
        $anime = new TvAnime();
        $anime->setEpisodesCount(12);
        $anime->setWatchedEpisodes(10);

        $this->expectException(InvalidEpisodeCountException::class);
        $anime->setEpisodesCount(5);
    }

    public function testSetEpisodesCountAllowsRaisingAboveWatchedEpisodes(): void
    {
        $anime = new TvAnime();
        $anime->setEpisodesCount(12);
        $anime->setWatchedEpisodes(10);
        $anime->setEpisodesCount(24);

        $this->assertSame(24, $anime->getEpisodesCount());
    }

    public function testSetWatchStatusCompletedAllowedWhenReleased(): void
    {
        $anime = new TvAnime();
        $anime->setDateEnd(new \DateTimeImmutable('-1 day'));
        $anime->setEpisodesCount(12);
        $anime->setWatchedEpisodes(5);

        $anime->setWatchStatus(WatchStatus::Completed);

        $this->assertSame(WatchStatus::Completed, $anime->getWatchStatus());
        $this->assertSame(12, $anime->getWatchedEpisodes());
    }

    public function testSetWatchStatusCompletedSetsWatchedEpisodesToNullWhenEpisodesCountUnknown(): void
    {
        $anime = new TvAnime();
        $anime->setDateEnd(new \DateTimeImmutable('-1 day'));

        $anime->setWatchStatus(WatchStatus::Completed);

        $this->assertSame(WatchStatus::Completed, $anime->getWatchStatus());
        $this->assertNull($anime->getWatchedEpisodes());
    }

    public function testApplyWatchProgressRepresentsDroppedPartway(): void
    {
        $anime = new TvAnime();
        $anime->setEpisodesCount(12);
        $anime->setWatchStatus(WatchStatus::Watching);
        $anime->setWatchedEpisodes(4);

        $anime->applyWatchProgress(WatchStatus::Dropped, 5, new \DateTimeImmutable());

        $this->assertSame(WatchStatus::Dropped, $anime->getWatchStatus());
        $this->assertSame(5, $anime->getWatchedEpisodes());
    }

    public function testApplyWatchProgressRejectsCompletedWithoutReleaseDatesWithoutThrowing(): void
    {
        $anime = new TvAnime();
        $anime->setEpisodesCount(12);
        $anime->setWatchStatus(WatchStatus::Watching);
        $anime->setWatchedEpisodes(6);

        $anime->applyWatchProgress(WatchStatus::Completed, 12, new \DateTimeImmutable());

        $this->assertSame(WatchStatus::Watching, $anime->getWatchStatus());
        $this->assertSame(6, $anime->getWatchedEpisodes());
        $this->assertNull($anime->getWatchProgressUpdatedAt());
        $this->assertNotNull($anime->getWatchProgressRejectedAt());
    }

    public function testApplyWatchProgressWithNullEpisodesLeavesLocalEpisodesUntouched(): void
    {
        $anime = new TvAnime();
        $anime->setEpisodesCount(12);
        $anime->setWatchStatus(WatchStatus::Watching);
        $anime->setWatchedEpisodes(5);

        $anime->applyWatchProgress(WatchStatus::OnHold, null, new \DateTimeImmutable());

        $this->assertSame(WatchStatus::OnHold, $anime->getWatchStatus());
        $this->assertSame(5, $anime->getWatchedEpisodes());
    }

    public function testApplyWatchProgressSetsWatchProgressUpdatedAtToTheSourceTimeNotNow(): void
    {
        $anime = new TvAnime();
        $anime->setEpisodesCount(12);
        $anime->setWatchStatus(WatchStatus::Plan);
        $sourceUpdatedAt = new \DateTimeImmutable('2020-01-01 00:00:00');

        $anime->applyWatchProgress(WatchStatus::Watching, 3, $sourceUpdatedAt);

        $this->assertEquals($sourceUpdatedAt, $anime->getWatchProgressUpdatedAt());
    }

    public function testApplyWatchProgressRejectionRollsBackBothEpisodesAndStatus(): void
    {
        $anime = new TvAnime();
        $anime->setEpisodesCount(12);
        $anime->setWatchStatus(WatchStatus::Watching);
        $anime->setWatchedEpisodes(6);

        // The episodes half of the pair would succeed on its own (12 <= episodesCount), but the
        // status half is rejected (not released) — the episodes mutation must not survive that.
        $anime->applyWatchProgress(WatchStatus::Completed, 12, new \DateTimeImmutable());

        $this->assertSame(WatchStatus::Watching, $anime->getWatchStatus());
        $this->assertSame(6, $anime->getWatchedEpisodes());
    }

    /** @return array<string, array{class-string<SeriesAnime>, AnimeType}> */
    public static function concreteSubtypes(): array
    {
        return [
            TvAnime::class => [TvAnime::class, AnimeType::Tv],
            OvaAnime::class => [OvaAnime::class, AnimeType::Ova],
            OnaAnime::class => [OnaAnime::class, AnimeType::Ona],
            SpecialAnime::class => [SpecialAnime::class, AnimeType::Special],
            MusicAnime::class => [MusicAnime::class, AnimeType::Music],
        ];
    }

    /**
     * @param class-string<SeriesAnime> $class
     */
    #[DataProvider('concreteSubtypes')]
    public function testConcreteSubtypeInheritsEpisodeTracking(string $class, AnimeType $expectedType): void
    {
        $anime = new $class();

        $this->assertInstanceOf(SeriesAnime::class, $anime);
        $this->assertSame($expectedType, $anime->getType());

        $anime->setEpisodesCount(12);
        $anime->setWatchStatus(WatchStatus::Plan);
        $anime->watchNextEpisode();

        $this->assertSame(1, $anime->getWatchedEpisodes());
        $this->assertSame(WatchStatus::Watching, $anime->getWatchStatus());
    }
}
