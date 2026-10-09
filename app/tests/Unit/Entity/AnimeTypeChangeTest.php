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

namespace App\Tests\Unit\Entity;

use App\Entity\Enum\AnimeType;
use App\Entity\Enum\WatchStatus;
use App\Entity\Exception\InvalidAnimeTypeChangeException;
use App\Entity\MovieAnime;
use App\Entity\TvAnime;
use PHPUnit\Framework\TestCase;

/** What Anime::planTypeChange() leaves and drops (issue #1001). */
final class AnimeTypeChangeTest extends TestCase
{
    private function series(?string $premiere, ?string $end, WatchStatus $status = WatchStatus::Watching): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle('Detective Conan')
            ->setDatePremiereAndEnd($premiere !== null ? new \DateTimeImmutable($premiere) : null, $end !== null ? new \DateTimeImmutable($end) : null);
        $anime->setEpisodesCount(1150);
        $anime->setWatchedEpisodes(1149);
        $anime->setWatchStatus($status);

        return $anime;
    }

    public function testChangeWithinSeriesKeepsEverything(): void
    {
        $anime = $this->series('1996-01-08', '2010-07-04');

        $change = $anime->planTypeChange(AnimeType::Ova);

        $this->assertFalse($change->isLossy());
        $this->assertSame(1150, $change->episodesCount);
        $this->assertSame(1149, $change->watchedEpisodes);
        $this->assertEquals(new \DateTimeImmutable('1996-01-08'), $change->datePremiere);
        $this->assertEquals(new \DateTimeImmutable('2010-07-04'), $change->dateEnd);
        $this->assertNull($change->lostEpisodesCount);
        $this->assertNull($change->lostWatchedEpisodes);
        $this->assertNull($change->lostDateEnd);
    }

    public function testSeriesToMovieDropsEpisodesAndSetsEndToPremiere(): void
    {
        $anime = $this->series('1996-01-08', '2010-07-04');

        $change = $anime->planTypeChange(AnimeType::Movie);

        $this->assertTrue($change->isLossy());
        $this->assertNull($change->episodesCount);
        $this->assertNull($change->watchedEpisodes);
        $this->assertEquals(new \DateTimeImmutable('1996-01-08'), $change->datePremiere);
        $this->assertEquals(new \DateTimeImmutable('1996-01-08'), $change->dateEnd);
        $this->assertSame(1150, $change->lostEpisodesCount);
        $this->assertSame(1149, $change->lostWatchedEpisodes);
        $this->assertEquals(new \DateTimeImmutable('2010-07-04'), $change->lostDateEnd);
    }

    public function testSeriesToMovieWithEmptyPremiereTakesFormerEndAsPremiere(): void
    {
        $anime = $this->series(null, '2010-07-04');

        $change = $anime->planTypeChange(AnimeType::Movie);

        $this->assertEquals(new \DateTimeImmutable('2010-07-04'), $change->datePremiere);
        $this->assertEquals(new \DateTimeImmutable('2010-07-04'), $change->dateEnd);
        $this->assertNull($change->lostDateEnd, 'the end date is kept, so it is not listed as lost');
    }

    public function testSeriesToMovieKeepsCompletedWhenDatesStayInThePast(): void
    {
        $anime = $this->series(null, '2010-07-04', WatchStatus::Completed);

        $this->assertSame(WatchStatus::Completed, $anime->getWatchStatus());
        $this->assertSame(AnimeType::Movie, $anime->planTypeChange(AnimeType::Movie)->to);
    }

    public function testSeriesToMovieWithNoDatesAtAllIsPlannedWhenNotCompleted(): void
    {
        $anime = $this->series(null, null);

        $change = $anime->planTypeChange(AnimeType::Movie);

        $this->assertNull($change->datePremiere);
        $this->assertNull($change->dateEnd);
    }

    public function testSeriesToMovieIsRefusedWhenItWouldLeaveACompletedEntryWithoutDates(): void
    {
        $anime = $this->series('2010-07-04', '2010-07-04', WatchStatus::Completed);
        // Dates can be cleared after the status was set; both empty would make the entry "announced".
        $anime->setDatePremiereAndEnd(null, null);

        $this->expectException(InvalidAnimeTypeChangeException::class);

        $anime->planTypeChange(AnimeType::Movie);
    }

    public function testMovieToSeriesKeepsDatesAndLeavesEpisodesEmpty(): void
    {
        $movie = new MovieAnime();
        $movie->setTitle('Ghost in the Shell')
            ->setDatePremiereAndEnd(new \DateTimeImmutable('1995-11-18'), new \DateTimeImmutable('1995-11-18'))
            ->setWatchStatus(WatchStatus::Completed);

        $change = $movie->planTypeChange(AnimeType::Tv);

        $this->assertFalse($change->isLossy());
        $this->assertNull($change->episodesCount);
        $this->assertNull($change->watchedEpisodes);
        $this->assertEquals(new \DateTimeImmutable('1995-11-18'), $change->datePremiere);
        $this->assertEquals(new \DateTimeImmutable('1995-11-18'), $change->dateEnd);
    }

    public function testChangeToTheSameTypeIsRefused(): void
    {
        $this->expectException(InvalidAnimeTypeChangeException::class);

        $this->series('1996-01-08', null)->planTypeChange(AnimeType::Tv);
    }

    public function testChangeIsNotLimitedToAnnouncedEntries(): void
    {
        $anime = $this->series('1996-01-08', '2010-07-04', WatchStatus::Completed);

        $this->assertSame(\App\Entity\Enum\ProductionStatus::Released, $anime->getProductionStatus());
        $this->assertSame(AnimeType::Special, $anime->planTypeChange(AnimeType::Special)->to);
    }
}
