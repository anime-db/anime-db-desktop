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

use App\Entity\Enum\AnimeNameType;
use App\Entity\Enum\AnimeType;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\ProductionStatus;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\WatchStatus;
use App\Entity\Exception\InvalidCountryCodeException;
use App\Entity\Exception\InvalidDateRangeException;
use App\Entity\Exception\InvalidDurationException;
use App\Entity\Exception\InvalidNameException;
use App\Entity\Exception\InvalidWatchStatusException;
use App\Entity\Label;
use App\Entity\MovieAnime;
use App\Entity\Storage;
use App\Entity\Studio;
use App\Entity\ValueObject\PluginId;
use App\Entity\ValueObject\Rating;
use PHPUnit\Framework\TestCase;

/**
 * Covers the fields/methods that live on the base Anime class and stay identical
 * regardless of the concrete subtype. Uses MovieAnime as the stand-in concrete class
 * precisely because it has no series-specific members, which keeps these tests honest
 * about testing base-class behaviour only. Series-specific behaviour lives in
 * SeriesAnimeTest, movie-specific behaviour (and the absence of series fields) in
 * MovieAnimeTest.
 */
final class AnimeTest extends TestCase
{
    public function testMetadataDefaultsToNull(): void
    {
        $anime = new MovieAnime();

        $this->assertNull($anime->getMetadata());
    }

    public function testSetAndGetTitle(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop');

        $this->assertSame('Cowboy Bebop', $anime->getTitle());
    }

    public function testSetTitleReturnsSelf(): void
    {
        $anime = new MovieAnime();

        $this->assertSame($anime, $anime->setTitle('Trigun'));
    }

    public function testPutPluginDataStoresUnderPluginNamespace(): void
    {
        $anime = new MovieAnime();
        $anime->putPluginData(new PluginId('animedb-shikimori'), ['mal_id' => 1]);

        $this->assertSame(['mal_id' => 1], $anime->getPluginData(new PluginId('animedb-shikimori')));
    }

    public function testPutPluginDataMergesWithoutTouchingOtherPlugins(): void
    {
        $anime = new MovieAnime();
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

        $target = $source->migrate(AnimeType::Tv);

        $this->assertSame(['descriptions' => ['ru' => 'Описание']], $target->getMetadata());
    }

    public function testGetPluginDataDefaultsToEmptyArray(): void
    {
        $anime = new MovieAnime();

        $this->assertSame([], $anime->getPluginData(new PluginId('animedb-shikimori')));
    }

    public function testPutPluginDataReturnsSelf(): void
    {
        $anime = new MovieAnime();

        $this->assertSame($anime, $anime->putPluginData(new PluginId('animedb-shikimori'), []));
    }

    public function testSetDescriptionIsReadByGetSummary(): void
    {
        $anime = new MovieAnime();
        $anime->setDescription('ru', 'Описание');

        $this->assertSame('Описание', $anime->getSummary('ru'));
    }

    public function testSetDescriptionDoesNotTouchPluginData(): void
    {
        $anime = new MovieAnime();
        $anime->putPluginData(new PluginId('animedb-shikimori'), ['mal_id' => 1]);
        $anime->setDescription('ru', 'Описание');

        $this->assertSame(['mal_id' => 1], $anime->getPluginData(new PluginId('animedb-shikimori')));
        $this->assertSame('Описание', $anime->getSummary('ru'));
    }

    public function testSetAndGetWatchStatus(): void
    {
        $anime = new MovieAnime();
        $anime->setWatchStatus(WatchStatus::Watching);

        $this->assertSame(WatchStatus::Watching, $anime->getWatchStatus());
    }

    public function testDateEndEarlierThanDatePremiereIsRejectedViaSetDateEnd(): void
    {
        $anime = new MovieAnime();
        $anime->setDatePremiere(new \DateTimeImmutable('2026-06-01'));

        $this->expectException(InvalidDateRangeException::class);
        $anime->setDateEnd(new \DateTimeImmutable('2026-01-01'));
    }

    public function testDateEndEarlierThanDatePremiereIsRejectedViaSetDatePremiere(): void
    {
        $anime = new MovieAnime();
        $anime->setDateEnd(new \DateTimeImmutable('2026-01-01'));

        $this->expectException(InvalidDateRangeException::class);
        $anime->setDatePremiere(new \DateTimeImmutable('2026-06-01'));
    }

    public function testDateEndEqualToDatePremiereIsAllowed(): void
    {
        $anime = new MovieAnime();
        $anime->setDatePremiere(new \DateTimeImmutable('2026-06-01'));
        $anime->setDateEnd(new \DateTimeImmutable('2026-06-01'));

        $this->assertEquals(new \DateTimeImmutable('2026-06-01'), $anime->getDateEnd());
    }

    public function testProductionStatusIsAnnouncedWithoutDates(): void
    {
        $anime = new MovieAnime();

        $this->assertSame(ProductionStatus::Announced, $anime->getProductionStatus());
    }

    public function testProductionStatusIsOngoingWhenPremiereIsTodayAndNoEnd(): void
    {
        $anime = new MovieAnime();
        $anime->setDatePremiere(new \DateTimeImmutable('today'));

        $this->assertSame(ProductionStatus::Ongoing, $anime->getProductionStatus());
    }

    public function testProductionStatusIsOngoingWhenPremierePastAndEndInFuture(): void
    {
        $anime = new MovieAnime();
        $anime->setDatePremiere(new \DateTimeImmutable('-1 day'));
        $anime->setDateEnd(new \DateTimeImmutable('+1 day'));

        $this->assertSame(ProductionStatus::Ongoing, $anime->getProductionStatus());
    }

    public function testProductionStatusIsReleasedWhenEndIsToday(): void
    {
        $anime = new MovieAnime();
        $anime->setDatePremiere(new \DateTimeImmutable('-1 day'));
        $anime->setDateEnd(new \DateTimeImmutable('today'));

        $this->assertSame(ProductionStatus::Released, $anime->getProductionStatus());
    }

    public function testProductionStatusIsReleasedWhenEndIsInThePast(): void
    {
        $anime = new MovieAnime();
        $anime->setDatePremiere(new \DateTimeImmutable('-2 days'));
        $anime->setDateEnd(new \DateTimeImmutable('-1 day'));

        $this->assertSame(ProductionStatus::Released, $anime->getProductionStatus());
    }

    public function testProductionStatusIsAnnouncedWhenPremiereIsInTheFuture(): void
    {
        $anime = new MovieAnime();
        $anime->setDatePremiere(new \DateTimeImmutable('+1 day'));

        $this->assertSame(ProductionStatus::Announced, $anime->getProductionStatus());
    }

    public function testGetSummaryReturnsPreferredLocale(): void
    {
        $anime = new MovieAnime();
        $anime->setDescription('ru', 'Описание');
        $anime->setDescription('en', 'Description');

        $this->assertSame('Описание', $anime->getSummary('ru'));
    }

    public function testGetSummaryFallsBackToEnglish(): void
    {
        $anime = new MovieAnime();
        $anime->setDescription('en', 'Description');
        $anime->setDescription('de', 'Beschreibung');

        $this->assertSame('Description', $anime->getSummary('ru'));
    }

    public function testGetSummaryFallsBackToAnyAvailableLocale(): void
    {
        $anime = new MovieAnime();
        $anime->setDescription('de', 'Beschreibung');

        $this->assertSame('Beschreibung', $anime->getSummary('ru'));
    }

    public function testGetSummaryReturnsEmptyStringWithoutMetadata(): void
    {
        $anime = new MovieAnime();

        $this->assertSame('', $anime->getSummary('ru'));
    }

    public function testAddAndGetGenreCodes(): void
    {
        $anime = new MovieAnime();
        $anime->addGenre(GenreCode::Action);
        $anime->addGenre(GenreCode::Drama);

        $this->assertSame([GenreCode::Action, GenreCode::Drama], $anime->getGenreCodes());
    }

    public function testAddGenreIsIdempotent(): void
    {
        $anime = new MovieAnime();
        $anime->addGenre(GenreCode::Action);
        $anime->addGenre(GenreCode::Action);

        $this->assertCount(1, $anime->getGenreCodes());
    }

    public function testRemoveGenre(): void
    {
        $anime = new MovieAnime();
        $anime->addGenre(GenreCode::Action);
        $anime->addGenre(GenreCode::Drama);
        $anime->removeGenre(GenreCode::Action);

        $this->assertSame([GenreCode::Drama], $anime->getGenreCodes());
    }

    public function testAddAndGetStudios(): void
    {
        $anime = new MovieAnime();
        $studio = new Studio();
        $studio->rename('Sunrise');

        $anime->addStudio($studio);

        $this->assertTrue($anime->getStudios()->contains($studio));
    }

    public function testAddAndGetLabels(): void
    {
        $anime = new MovieAnime();
        $label = new Label();
        $label->rename('favorite');

        $anime->addLabel($label);

        $this->assertTrue($anime->getLabels()->contains($label));
    }

    public function testAddNameCreatesAnimeNameOwnedByAnime(): void
    {
        $anime = new MovieAnime();
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
        $anime = new MovieAnime();
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
        $anime = new MovieAnime();
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
        $anime = new MovieAnime();
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);

        $anime->setStorage($storage);

        $this->assertSame($storage, $anime->getStorage());
    }

    public function testStoragePathDefaultsToNull(): void
    {
        $anime = new MovieAnime();

        $this->assertNull($anime->getStoragePath());
    }

    public function testSetAndGetStoragePath(): void
    {
        $anime = new MovieAnime();
        $anime->setStoragePath('A Silent Voice.mkv');

        $this->assertSame('A Silent Voice.mkv', $anime->getStoragePath());
    }

    public function testDateAddAndDateUpdateAreInitialized(): void
    {
        $anime = new MovieAnime();

        $this->assertInstanceOf(\DateTimeImmutable::class, $anime->getDateAdd());
        $this->assertInstanceOf(\DateTimeImmutable::class, $anime->getDateUpdate());
    }

    public function testPreUpdateRefreshesDateUpdate(): void
    {
        $anime = new MovieAnime();
        $before = $anime->getDateUpdate();

        usleep(1000);
        $anime->onPreUpdate();

        $this->assertGreaterThan($before, $anime->getDateUpdate());
    }

    public function testUserRatingDefaultsToNull(): void
    {
        $anime = new MovieAnime();

        $this->assertNull($anime->getUserRating());
    }

    public function testSetAndGetUserRating(): void
    {
        $anime = new MovieAnime();
        $rating = new Rating(5);
        $anime->setUserRating($rating);

        $this->assertSame($rating, $anime->getUserRating());
    }

    public function testSetTitleTrimsWhitespace(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('  Cowboy Bebop  ');

        $this->assertSame('Cowboy Bebop', $anime->getTitle());
    }

    public function testSetTitleRejectsEmptyString(): void
    {
        $anime = new MovieAnime();

        $this->expectException(InvalidNameException::class);
        $anime->setTitle('');
    }

    public function testSetTitleRejectsWhitespaceOnlyString(): void
    {
        $anime = new MovieAnime();

        $this->expectException(InvalidNameException::class);
        $anime->setTitle('   ');
    }

    public function testSetDurationMinutesRejectsZero(): void
    {
        $anime = new MovieAnime();

        $this->expectException(InvalidDurationException::class);
        $anime->setDurationMinutes(0);
    }

    public function testSetDurationMinutesRejectsNegativeValue(): void
    {
        $anime = new MovieAnime();

        $this->expectException(InvalidDurationException::class);
        $anime->setDurationMinutes(-1);
    }

    public function testSetDurationMinutesAllowsNull(): void
    {
        $anime = new MovieAnime();
        $anime->setDurationMinutes(24);
        $anime->setDurationMinutes(null);

        $this->assertNull($anime->getDurationMinutes());
    }

    public function testSetCountriesAcceptsValidCodes(): void
    {
        $anime = new MovieAnime();
        $anime->setCountries(['JP', 'US']);

        $this->assertSame(['JP', 'US'], $anime->getCountries());
    }

    public function testSetCountriesRejectsLowercaseCode(): void
    {
        $anime = new MovieAnime();

        $this->expectException(InvalidCountryCodeException::class);
        $anime->setCountries(['jp']);
    }

    public function testSetCountriesRejectsWrongLength(): void
    {
        $anime = new MovieAnime();

        $this->expectException(InvalidCountryCodeException::class);
        $anime->setCountries(['JPN']);
    }

    public function testSetWatchStatusCompletedThrowsWhenAnnounced(): void
    {
        $anime = new MovieAnime();

        $this->expectException(InvalidWatchStatusException::class);
        $anime->setWatchStatus(WatchStatus::Completed);
    }

    public function testSetWatchStatusCompletedThrowsWhenOngoing(): void
    {
        $anime = new MovieAnime();
        $anime->setDatePremiere(new \DateTimeImmutable('-1 day'));

        $this->assertSame(ProductionStatus::Ongoing, $anime->getProductionStatus());
        $this->expectException(InvalidWatchStatusException::class);
        $anime->setWatchStatus(WatchStatus::Completed);
    }

    public function testSetWatchStatusCompletedAllowedWhenReleased(): void
    {
        $anime = new MovieAnime();
        $anime->setDateEnd(new \DateTimeImmutable('-1 day'));

        $anime->setWatchStatus(WatchStatus::Completed);

        $this->assertSame(WatchStatus::Completed, $anime->getWatchStatus());
    }
}
