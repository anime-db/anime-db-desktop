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

use App\Entity\Anime;
use App\Entity\Enum\AnimeNameType;
use App\Entity\Enum\AnimeType;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\ProductionStatus;
use App\Entity\Enum\WatchStatus;
use App\Entity\Exception\InvalidDateRangeException;
use App\Entity\Exception\InvalidEpisodeCountException;
use App\Entity\Label;
use App\Entity\Storage;
use App\Entity\Studio;
use PHPUnit\Framework\TestCase;

final class AnimeTest extends TestCase
{
    public function testMetadataDefaultsToNull(): void
    {
        $anime = new Anime();

        $this->assertNull($anime->getMetadata());
    }

    public function testSetAndGetTitle(): void
    {
        $anime = new Anime();
        $anime->setTitle('Cowboy Bebop');

        $this->assertSame('Cowboy Bebop', $anime->getTitle());
    }

    public function testSetTitleReturnsSelf(): void
    {
        $anime = new Anime();

        $this->assertSame($anime, $anime->setTitle('Trigun'));
    }

    public function testSetAndGetMetadata(): void
    {
        $metadata = [
            'mal_id' => 1,
            'studios' => ['Sunrise'],
            'external_ids' => ['shikimori' => 1],
        ];

        $anime = new Anime();
        $anime->setMetadata($metadata);

        $this->assertSame($metadata, $anime->getMetadata());
    }

    public function testSetMetadataToNull(): void
    {
        $anime = new Anime();
        $anime->setMetadata(['mal_id' => 1]);
        $anime->setMetadata(null);

        $this->assertNull($anime->getMetadata());
    }

    public function testSetMetadataReturnsSelf(): void
    {
        $anime = new Anime();

        $this->assertSame($anime, $anime->setMetadata(null));
    }

    public function testSetAndGetWatchStatus(): void
    {
        $anime = new Anime();
        $anime->setWatchStatus(WatchStatus::Watching);

        $this->assertSame(WatchStatus::Watching, $anime->getWatchStatus());
    }

    public function testSetAndGetType(): void
    {
        $anime = new Anime();
        $anime->setType(AnimeType::Tv);

        $this->assertSame(AnimeType::Tv, $anime->getType());
    }

    public function testDateEndEarlierThanDatePremiereIsRejectedViaSetDateEnd(): void
    {
        $anime = new Anime();
        $anime->setDatePremiere(new \DateTimeImmutable('2026-06-01'));

        $this->expectException(InvalidDateRangeException::class);
        $anime->setDateEnd(new \DateTimeImmutable('2026-01-01'));
    }

    public function testDateEndEarlierThanDatePremiereIsRejectedViaSetDatePremiere(): void
    {
        $anime = new Anime();
        $anime->setDateEnd(new \DateTimeImmutable('2026-01-01'));

        $this->expectException(InvalidDateRangeException::class);
        $anime->setDatePremiere(new \DateTimeImmutable('2026-06-01'));
    }

    public function testDateEndEqualToDatePremiereIsAllowed(): void
    {
        $anime = new Anime();
        $anime->setDatePremiere(new \DateTimeImmutable('2026-06-01'));
        $anime->setDateEnd(new \DateTimeImmutable('2026-06-01'));

        $this->assertEquals(new \DateTimeImmutable('2026-06-01'), $anime->getDateEnd());
    }

    public function testProductionStatusIsAnnouncedWithoutDates(): void
    {
        $anime = new Anime();

        $this->assertSame(ProductionStatus::Announced, $anime->getProductionStatus());
    }

    public function testProductionStatusIsOngoingWhenPremiereIsTodayAndNoEnd(): void
    {
        $anime = new Anime();
        $anime->setDatePremiere(new \DateTimeImmutable('today'));

        $this->assertSame(ProductionStatus::Ongoing, $anime->getProductionStatus());
    }

    public function testProductionStatusIsOngoingWhenPremierePastAndEndInFuture(): void
    {
        $anime = new Anime();
        $anime->setDatePremiere(new \DateTimeImmutable('-1 day'));
        $anime->setDateEnd(new \DateTimeImmutable('+1 day'));

        $this->assertSame(ProductionStatus::Ongoing, $anime->getProductionStatus());
    }

    public function testProductionStatusIsReleasedWhenEndIsToday(): void
    {
        $anime = new Anime();
        $anime->setDatePremiere(new \DateTimeImmutable('-1 day'));
        $anime->setDateEnd(new \DateTimeImmutable('today'));

        $this->assertSame(ProductionStatus::Released, $anime->getProductionStatus());
    }

    public function testProductionStatusIsReleasedWhenEndIsInThePast(): void
    {
        $anime = new Anime();
        $anime->setDatePremiere(new \DateTimeImmutable('-2 days'));
        $anime->setDateEnd(new \DateTimeImmutable('-1 day'));

        $this->assertSame(ProductionStatus::Released, $anime->getProductionStatus());
    }

    public function testProductionStatusIsAnnouncedWhenPremiereIsInTheFuture(): void
    {
        $anime = new Anime();
        $anime->setDatePremiere(new \DateTimeImmutable('+1 day'));

        $this->assertSame(ProductionStatus::Announced, $anime->getProductionStatus());
    }

    public function testGetSummaryReturnsPreferredLocale(): void
    {
        $anime = new Anime();
        $anime->setMetadata(['descriptions' => ['ru' => 'Описание', 'en' => 'Description']]);

        $this->assertSame('Описание', $anime->getSummary('ru'));
    }

    public function testGetSummaryFallsBackToEnglish(): void
    {
        $anime = new Anime();
        $anime->setMetadata(['descriptions' => ['en' => 'Description', 'de' => 'Beschreibung']]);

        $this->assertSame('Description', $anime->getSummary('ru'));
    }

    public function testGetSummaryFallsBackToAnyAvailableLocale(): void
    {
        $anime = new Anime();
        $anime->setMetadata(['descriptions' => ['de' => 'Beschreibung']]);

        $this->assertSame('Beschreibung', $anime->getSummary('ru'));
    }

    public function testGetSummaryReturnsEmptyStringWithoutMetadata(): void
    {
        $anime = new Anime();

        $this->assertSame('', $anime->getSummary('ru'));
    }

    public function testAddAndGetGenreCodes(): void
    {
        $anime = new Anime();
        $anime->addGenre(GenreCode::Action);
        $anime->addGenre(GenreCode::Drama);

        $this->assertSame([GenreCode::Action, GenreCode::Drama], $anime->getGenreCodes());
    }

    public function testAddGenreIsIdempotent(): void
    {
        $anime = new Anime();
        $anime->addGenre(GenreCode::Action);
        $anime->addGenre(GenreCode::Action);

        $this->assertCount(1, $anime->getGenreCodes());
    }

    public function testRemoveGenre(): void
    {
        $anime = new Anime();
        $anime->addGenre(GenreCode::Action);
        $anime->addGenre(GenreCode::Drama);
        $anime->removeGenre(GenreCode::Action);

        $this->assertSame([GenreCode::Drama], $anime->getGenreCodes());
    }

    public function testAddAndGetStudios(): void
    {
        $anime = new Anime();
        $studio = new Studio();
        $studio->setName('Sunrise');

        $anime->addStudio($studio);

        $this->assertTrue($anime->getStudios()->contains($studio));
    }

    public function testAddAndGetLabels(): void
    {
        $anime = new Anime();
        $label = new Label();
        $label->setName('favorite');

        $anime->addLabel($label);

        $this->assertTrue($anime->getLabels()->contains($label));
    }

    public function testAddNameCreatesAnimeNameOwnedByAnime(): void
    {
        $anime = new Anime();
        $anime->addName('Cowboy Bebop', AnimeNameType::English);

        $names = $anime->getNames();
        $this->assertCount(1, $names);

        $name = $names->first();
        if (false === $name) {
            $this->fail('Expected one name');
        }
        $this->assertSame($anime, $name->getAnime());
        $this->assertSame('Cowboy Bebop', $name->getName());
    }

    public function testAddImageCreatesAnimeImageOwnedByAnime(): void
    {
        $anime = new Anime();
        $anime->addImage('images/frame1.jpg');

        $this->assertCount(1, $anime->getImages());

        $image = $anime->getImages()->first();
        if (false === $image) {
            $this->fail('Expected one image');
        }
        $this->assertSame('images/frame1.jpg', $image->getSource());
    }

    public function testAddSourceCreatesAnimeSourceOwnedByAnime(): void
    {
        $anime = new Anime();
        $anime->addSource('https://shikimori.one/animes/1');

        $this->assertCount(1, $anime->getSources());

        $source = $anime->getSources()->first();
        if (false === $source) {
            $this->fail('Expected one source');
        }
        $this->assertSame('https://shikimori.one/animes/1', $source->getUrl());
    }

    public function testSetStorage(): void
    {
        $anime = new Anime();
        $storage = new Storage();
        $storage->setName('Main folder');

        $anime->setStorage($storage);

        $this->assertSame($storage, $anime->getStorage());
    }

    public function testDateAddAndDateUpdateAreInitialized(): void
    {
        $anime = new Anime();

        $this->assertInstanceOf(\DateTimeImmutable::class, $anime->getDateAdd());
        $this->assertInstanceOf(\DateTimeImmutable::class, $anime->getDateUpdate());
    }

    public function testPreUpdateRefreshesDateUpdate(): void
    {
        $anime = new Anime();
        $before = $anime->getDateUpdate();

        usleep(1000);
        $anime->onPreUpdate();

        $this->assertGreaterThan($before, $anime->getDateUpdate());
    }

    public function testSetWatchedEpisodesRejectsNegativeValue(): void
    {
        $anime = new Anime();

        $this->expectException(InvalidEpisodeCountException::class);
        $anime->setWatchedEpisodes(-1);
    }

    public function testSetWatchedEpisodesRejectsValueAboveEpisodesCount(): void
    {
        $anime = new Anime();
        $anime->setEpisodesCount(12);

        $this->expectException(InvalidEpisodeCountException::class);
        $anime->setWatchedEpisodes(13);
    }

    public function testWatchNextEpisodeIncrementsWatchedEpisodes(): void
    {
        $anime = new Anime();
        $anime->setEpisodesCount(12);
        $anime->setWatchStatus(WatchStatus::Plan);

        $anime->watchNextEpisode();

        $this->assertSame(1, $anime->getWatchedEpisodes());
        $this->assertSame(WatchStatus::Watching, $anime->getWatchStatus());
    }

    public function testWatchNextEpisodeMovesToCompletedOnLastEpisode(): void
    {
        $anime = new Anime();
        $anime->setEpisodesCount(2);
        $anime->setWatchStatus(WatchStatus::Watching);
        $anime->setWatchedEpisodes(1);

        $anime->watchNextEpisode();

        $this->assertSame(2, $anime->getWatchedEpisodes());
        $this->assertSame(WatchStatus::Completed, $anime->getWatchStatus());
    }

    public function testWatchNextEpisodeRejectsGoingPastEpisodesCount(): void
    {
        $anime = new Anime();
        $anime->setEpisodesCount(1);
        $anime->setWatchStatus(WatchStatus::Completed);
        $anime->setWatchedEpisodes(1);

        $this->expectException(InvalidEpisodeCountException::class);
        $anime->watchNextEpisode();
    }
}
