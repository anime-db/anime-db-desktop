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
use App\Entity\Exception\InvalidCountryCodeException;
use App\Entity\Exception\InvalidDateRangeException;
use App\Entity\Exception\InvalidDurationException;
use App\Entity\Exception\InvalidEpisodeCountException;
use App\Entity\Exception\InvalidNameException;
use App\Entity\Exception\InvalidWatchStatusException;
use App\Entity\Label;
use App\Entity\MovieAnime;
use App\Entity\Storage;
use App\Entity\Studio;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\Entity\ValueObject\Rating;
use PHPUnit\Framework\TestCase;

final class AnimeTest extends TestCase
{
    public function testMetadataDefaultsToNull(): void
    {
        $anime = new TvAnime();

        $this->assertNull($anime->getMetadata());
    }

    public function testSetAndGetTitle(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop');

        $this->assertSame('Cowboy Bebop', $anime->getTitle());
    }

    public function testSetTitleReturnsSelf(): void
    {
        $anime = new TvAnime();

        $this->assertSame($anime, $anime->setTitle('Trigun'));
    }

    public function testPutPluginDataStoresUnderPluginNamespace(): void
    {
        $anime = new TvAnime();
        $anime->putPluginData(new PluginId('animedb-shikimori'), ['mal_id' => 1]);

        $this->assertSame(['mal_id' => 1], $anime->getPluginData(new PluginId('animedb-shikimori')));
    }

    public function testPutPluginDataMergesWithoutTouchingOtherPlugins(): void
    {
        $anime = new TvAnime();
        $anime->putPluginData(new PluginId('animedb-shikimori'), ['mal_id' => 1]);
        $anime->putPluginData(new PluginId('animedb-mal'), ['mal_id' => 2]);
        $anime->putPluginData(new PluginId('animedb-shikimori'), ['rating' => 8.5]);

        $this->assertSame(['mal_id' => 1, 'rating' => 8.5], $anime->getPluginData(new PluginId('animedb-shikimori')));
        $this->assertSame(['mal_id' => 2], $anime->getPluginData(new PluginId('animedb-mal')));
    }

    public function testMigrateCarriesMetadataOverAsTheWholeBlob(): void
    {
        $source = new MovieAnime();
        $source->setTitle('Cowboy Bebop: The Movie')
            ->setWatchStatus(WatchStatus::Plan)
            ->setDescription('ru', 'Описание');

        $target = Anime::migrate($source, AnimeType::Tv);

        $this->assertSame(['descriptions' => ['ru' => 'Описание']], $target->getMetadata());
    }

    public function testGetPluginDataDefaultsToEmptyArray(): void
    {
        $anime = new TvAnime();

        $this->assertSame([], $anime->getPluginData(new PluginId('animedb-shikimori')));
    }

    public function testPutPluginDataReturnsSelf(): void
    {
        $anime = new TvAnime();

        $this->assertSame($anime, $anime->putPluginData(new PluginId('animedb-shikimori'), []));
    }

    public function testSetDescriptionIsReadByGetSummary(): void
    {
        $anime = new TvAnime();
        $anime->setDescription('ru', 'Описание');

        $this->assertSame('Описание', $anime->getSummary('ru'));
    }

    public function testSetDescriptionDoesNotTouchPluginData(): void
    {
        $anime = new TvAnime();
        $anime->putPluginData(new PluginId('animedb-shikimori'), ['mal_id' => 1]);
        $anime->setDescription('ru', 'Описание');

        $this->assertSame(['mal_id' => 1], $anime->getPluginData(new PluginId('animedb-shikimori')));
        $this->assertSame('Описание', $anime->getSummary('ru'));
    }

    public function testSetAndGetWatchStatus(): void
    {
        $anime = new TvAnime();
        $anime->setWatchStatus(WatchStatus::Watching);

        $this->assertSame(WatchStatus::Watching, $anime->getWatchStatus());
    }

    public function testGetType(): void
    {
        $anime = new TvAnime();

        $this->assertSame(AnimeType::Tv, $anime->getType());
    }

    public function testDateEndEarlierThanDatePremiereIsRejectedViaSetDateEnd(): void
    {
        $anime = new TvAnime();
        $anime->setDatePremiere(new \DateTimeImmutable('2026-06-01'));

        $this->expectException(InvalidDateRangeException::class);
        $anime->setDateEnd(new \DateTimeImmutable('2026-01-01'));
    }

    public function testDateEndEarlierThanDatePremiereIsRejectedViaSetDatePremiere(): void
    {
        $anime = new TvAnime();
        $anime->setDateEnd(new \DateTimeImmutable('2026-01-01'));

        $this->expectException(InvalidDateRangeException::class);
        $anime->setDatePremiere(new \DateTimeImmutable('2026-06-01'));
    }

    public function testDateEndEqualToDatePremiereIsAllowed(): void
    {
        $anime = new TvAnime();
        $anime->setDatePremiere(new \DateTimeImmutable('2026-06-01'));
        $anime->setDateEnd(new \DateTimeImmutable('2026-06-01'));

        $this->assertEquals(new \DateTimeImmutable('2026-06-01'), $anime->getDateEnd());
    }

    public function testProductionStatusIsAnnouncedWithoutDates(): void
    {
        $anime = new TvAnime();

        $this->assertSame(ProductionStatus::Announced, $anime->getProductionStatus());
    }

    public function testProductionStatusIsOngoingWhenPremiereIsTodayAndNoEnd(): void
    {
        $anime = new TvAnime();
        $anime->setDatePremiere(new \DateTimeImmutable('today'));

        $this->assertSame(ProductionStatus::Ongoing, $anime->getProductionStatus());
    }

    public function testProductionStatusIsOngoingWhenPremierePastAndEndInFuture(): void
    {
        $anime = new TvAnime();
        $anime->setDatePremiere(new \DateTimeImmutable('-1 day'));
        $anime->setDateEnd(new \DateTimeImmutable('+1 day'));

        $this->assertSame(ProductionStatus::Ongoing, $anime->getProductionStatus());
    }

    public function testProductionStatusIsReleasedWhenEndIsToday(): void
    {
        $anime = new TvAnime();
        $anime->setDatePremiere(new \DateTimeImmutable('-1 day'));
        $anime->setDateEnd(new \DateTimeImmutable('today'));

        $this->assertSame(ProductionStatus::Released, $anime->getProductionStatus());
    }

    public function testProductionStatusIsReleasedWhenEndIsInThePast(): void
    {
        $anime = new TvAnime();
        $anime->setDatePremiere(new \DateTimeImmutable('-2 days'));
        $anime->setDateEnd(new \DateTimeImmutable('-1 day'));

        $this->assertSame(ProductionStatus::Released, $anime->getProductionStatus());
    }

    public function testProductionStatusIsAnnouncedWhenPremiereIsInTheFuture(): void
    {
        $anime = new TvAnime();
        $anime->setDatePremiere(new \DateTimeImmutable('+1 day'));

        $this->assertSame(ProductionStatus::Announced, $anime->getProductionStatus());
    }

    public function testGetSummaryReturnsPreferredLocale(): void
    {
        $anime = new TvAnime();
        $anime->setDescription('ru', 'Описание');
        $anime->setDescription('en', 'Description');

        $this->assertSame('Описание', $anime->getSummary('ru'));
    }

    public function testGetSummaryFallsBackToEnglish(): void
    {
        $anime = new TvAnime();
        $anime->setDescription('en', 'Description');
        $anime->setDescription('de', 'Beschreibung');

        $this->assertSame('Description', $anime->getSummary('ru'));
    }

    public function testGetSummaryFallsBackToAnyAvailableLocale(): void
    {
        $anime = new TvAnime();
        $anime->setDescription('de', 'Beschreibung');

        $this->assertSame('Beschreibung', $anime->getSummary('ru'));
    }

    public function testGetSummaryReturnsEmptyStringWithoutMetadata(): void
    {
        $anime = new TvAnime();

        $this->assertSame('', $anime->getSummary('ru'));
    }

    public function testAddAndGetGenreCodes(): void
    {
        $anime = new TvAnime();
        $anime->addGenre(GenreCode::Action);
        $anime->addGenre(GenreCode::Drama);

        $this->assertSame([GenreCode::Action, GenreCode::Drama], $anime->getGenreCodes());
    }

    public function testAddGenreIsIdempotent(): void
    {
        $anime = new TvAnime();
        $anime->addGenre(GenreCode::Action);
        $anime->addGenre(GenreCode::Action);

        $this->assertCount(1, $anime->getGenreCodes());
    }

    public function testRemoveGenre(): void
    {
        $anime = new TvAnime();
        $anime->addGenre(GenreCode::Action);
        $anime->addGenre(GenreCode::Drama);
        $anime->removeGenre(GenreCode::Action);

        $this->assertSame([GenreCode::Drama], $anime->getGenreCodes());
    }

    public function testAddAndGetStudios(): void
    {
        $anime = new TvAnime();
        $studio = new Studio();
        $studio->rename('Sunrise');

        $anime->addStudio($studio);

        $this->assertTrue($anime->getStudios()->contains($studio));
    }

    public function testAddAndGetLabels(): void
    {
        $anime = new TvAnime();
        $label = new Label();
        $label->rename('favorite');

        $anime->addLabel($label);

        $this->assertTrue($anime->getLabels()->contains($label));
    }

    public function testAddNameCreatesAnimeNameOwnedByAnime(): void
    {
        $anime = new TvAnime();
        $anime->addName('Cowboy Bebop', AnimeNameType::English);

        $names = $anime->getNames();
        $this->assertCount(1, $names);

        $name = $names->first();
        if (false === $name) {
            $this->fail('Expected one name');
        }
        $this->assertSame($anime, $name->anime);
        $this->assertSame('Cowboy Bebop', $name->name);
    }

    public function testAddImageCreatesAnimeImageOwnedByAnime(): void
    {
        $anime = new TvAnime();
        $anime->addImage('images/frame1.jpg');

        $this->assertCount(1, $anime->getImages());

        $image = $anime->getImages()->first();
        if (false === $image) {
            $this->fail('Expected one image');
        }
        $this->assertSame('images/frame1.jpg', $image->source);
    }

    public function testAddSourceCreatesAnimeSourceOwnedByAnime(): void
    {
        $anime = new TvAnime();
        $anime->addSource('https://shikimori.one/animes/1');

        $this->assertCount(1, $anime->getSources());

        $source = $anime->getSources()->first();
        if (false === $source) {
            $this->fail('Expected one source');
        }
        $this->assertSame('https://shikimori.one/animes/1', $source->url);
    }

    public function testSetStorage(): void
    {
        $anime = new TvAnime();
        $storage = new Storage();
        $storage->setName('Main folder');

        $anime->setStorage($storage);

        $this->assertSame($storage, $anime->getStorage());
    }

    public function testDateAddAndDateUpdateAreInitialized(): void
    {
        $anime = new TvAnime();

        $this->assertInstanceOf(\DateTimeImmutable::class, $anime->getDateAdd());
        $this->assertInstanceOf(\DateTimeImmutable::class, $anime->getDateUpdate());
    }

    public function testPreUpdateRefreshesDateUpdate(): void
    {
        $anime = new TvAnime();
        $before = $anime->getDateUpdate();

        usleep(1000);
        $anime->onPreUpdate();

        $this->assertGreaterThan($before, $anime->getDateUpdate());
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

    public function testUserRatingDefaultsToNull(): void
    {
        $anime = new TvAnime();

        $this->assertNull($anime->getUserRating());
    }

    public function testSetAndGetUserRating(): void
    {
        $anime = new TvAnime();
        $rating = new Rating(5);
        $anime->setUserRating($rating);

        $this->assertSame($rating, $anime->getUserRating());
    }

    public function testSetTitleTrimsWhitespace(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('  Cowboy Bebop  ');

        $this->assertSame('Cowboy Bebop', $anime->getTitle());
    }

    public function testSetTitleRejectsEmptyString(): void
    {
        $anime = new TvAnime();

        $this->expectException(InvalidNameException::class);
        $anime->setTitle('');
    }

    public function testSetTitleRejectsWhitespaceOnlyString(): void
    {
        $anime = new TvAnime();

        $this->expectException(InvalidNameException::class);
        $anime->setTitle('   ');
    }

    public function testSetDurationMinutesRejectsZero(): void
    {
        $anime = new TvAnime();

        $this->expectException(InvalidDurationException::class);
        $anime->setDurationMinutes(0);
    }

    public function testSetDurationMinutesRejectsNegativeValue(): void
    {
        $anime = new TvAnime();

        $this->expectException(InvalidDurationException::class);
        $anime->setDurationMinutes(-1);
    }

    public function testSetDurationMinutesAllowsNull(): void
    {
        $anime = new TvAnime();
        $anime->setDurationMinutes(24);
        $anime->setDurationMinutes(null);

        $this->assertNull($anime->getDurationMinutes());
    }

    public function testSetCountriesAcceptsValidCodes(): void
    {
        $anime = new TvAnime();
        $anime->setCountries(['JP', 'US']);

        $this->assertSame(['JP', 'US'], $anime->getCountries());
    }

    public function testSetCountriesRejectsLowercaseCode(): void
    {
        $anime = new TvAnime();

        $this->expectException(InvalidCountryCodeException::class);
        $anime->setCountries(['jp']);
    }

    public function testSetCountriesRejectsWrongLength(): void
    {
        $anime = new TvAnime();

        $this->expectException(InvalidCountryCodeException::class);
        $anime->setCountries(['JPN']);
    }

    public function testSetWatchStatusCompletedThrowsWhenAnnounced(): void
    {
        $anime = new TvAnime();

        $this->expectException(InvalidWatchStatusException::class);
        $anime->setWatchStatus(WatchStatus::Completed);
    }

    public function testSetWatchStatusCompletedThrowsWhenOngoing(): void
    {
        $anime = new TvAnime();
        $anime->setDatePremiere(new \DateTimeImmutable('-1 day'));

        $this->assertSame(ProductionStatus::Ongoing, $anime->getProductionStatus());
        $this->expectException(InvalidWatchStatusException::class);
        $anime->setWatchStatus(WatchStatus::Completed);
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
}
