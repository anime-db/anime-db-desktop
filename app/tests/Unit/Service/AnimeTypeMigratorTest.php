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

namespace App\Tests\Unit\Service;

use App\Entity\Enum\AnimeNameType;
use App\Entity\Enum\AnimeType;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\WatchStatus;
use App\Entity\Exception\InvalidAnimeTypeMigrationException;
use App\Entity\Label;
use App\Entity\MovieAnime;
use App\Entity\Storage;
use App\Entity\Studio;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\Entity\ValueObject\Rating;
use App\Service\AnimeTypeMigrator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class AnimeTypeMigratorTest extends TestCase
{
    public function testMigrateFromMovieToSeriesCopiesCommonFields(): void
    {
        $storage = new Storage();
        $storage->setName('Main folder');

        $source = new MovieAnime();
        $source->setTitle('Cowboy Bebop: The Movie')
            ->setDatePremiere(new \DateTimeImmutable('+1 day'))
            ->setDateEnd(new \DateTimeImmutable('+2 days'))
            ->setDurationMinutes(115)
            ->setNotes('to rewatch')
            ->setUserRating(new Rating(5))
            ->setCover('cover.jpg')
            ->setStorage($storage)
            ->setCountries(['JP'])
            ->setWatchStatus(WatchStatus::Plan)
            ->putPluginData(new PluginId('animedb-shikimori'), ['mal_id' => 1]);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $migrator = new AnimeTypeMigrator($entityManager);

        $target = $migrator->migrate($source, AnimeType::Tv);

        $this->assertInstanceOf(TvAnime::class, $target);
        $this->assertSame('Cowboy Bebop: The Movie', $target->getTitle());
        $this->assertEquals($source->getDatePremiere(), $target->getDatePremiere());
        $this->assertEquals($source->getDateEnd(), $target->getDateEnd());
        $this->assertSame(115, $target->getDurationMinutes());
        $this->assertSame('to rewatch', $target->getNotes());
        $this->assertEquals(new Rating(5), $target->getUserRating());
        $this->assertSame('cover.jpg', $target->getCover());
        $this->assertSame($storage, $target->getStorage());
        $this->assertSame(['JP'], $target->getCountries());
        $this->assertSame(WatchStatus::Plan, $target->getWatchStatus());
        $this->assertSame(['mal_id' => 1], $target->getPluginData(new PluginId('animedb-shikimori')));
    }

    public function testMigrateFromSeriesToMovieDoesNotCarryEpisodeFields(): void
    {
        $source = new TvAnime();
        $source->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $migrator = new AnimeTypeMigrator($entityManager);

        $target = $migrator->migrate($source, AnimeType::Movie);

        $this->assertInstanceOf(MovieAnime::class, $target);
        $this->assertSame('Trigun', $target->getTitle());
    }

    public function testMigrateCarriesDurationMinutesOverWithoutReset(): void
    {
        $source = new MovieAnime();
        $source->setTitle('Akira')->setDurationMinutes(124)->setWatchStatus(WatchStatus::Plan);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $migrator = new AnimeTypeMigrator($entityManager);

        $target = $migrator->migrate($source, AnimeType::Tv);

        $this->assertSame(124, $target->getDurationMinutes());
    }

    public function testMigrateTransfersGenresStudiosLabelsNamesImagesAndSources(): void
    {
        $studio = new Studio();
        $studio->rename('Sunrise');
        $label = new Label();
        $label->rename('favorite');

        $source = new MovieAnime();
        $source->setTitle('Cowboy Bebop: The Movie')
            ->setWatchStatus(WatchStatus::Plan)
            ->addGenre(GenreCode::Action)
            ->addStudio($studio)
            ->addLabel($label)
            ->addName('Cowboy Bebop', AnimeNameType::English)
            ->addImage('images/frame1.jpg')
            ->addSource('https://shikimori.one/animes/1');

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $migrator = new AnimeTypeMigrator($entityManager);

        $target = $migrator->migrate($source, AnimeType::Tv);

        $this->assertSame([GenreCode::Action], $target->getGenreCodes());
        $this->assertTrue($target->getStudios()->contains($studio));
        $this->assertTrue($target->getLabels()->contains($label));

        $name = $target->getNames()->first();
        $this->assertNotFalse($name);
        $this->assertSame('Cowboy Bebop', $name->name);
        $this->assertSame($target, $name->anime);

        $image = $target->getImages()->first();
        $this->assertNotFalse($image);
        $this->assertSame('images/frame1.jpg', $image->source);

        $link = $target->getSources()->first();
        $this->assertNotFalse($link);
        $this->assertSame('https://shikimori.one/animes/1', $link->url);
    }

    public function testMigratePersistsTargetRemovesSourceAndFlushes(): void
    {
        $source = new MovieAnime();
        $source->setTitle('Akira')->setWatchStatus(WatchStatus::Plan);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist');
        $entityManager->expects($this->once())->method('remove')->with($source);
        $entityManager->expects($this->once())->method('flush');

        (new AnimeTypeMigrator($entityManager))->migrate($source, AnimeType::Tv);
    }

    public function testMigrateThrowsWhenTargetIsSameBranchAsMovie(): void
    {
        $source = new MovieAnime();
        $source->setTitle('Akira')->setWatchStatus(WatchStatus::Plan);

        $entityManager = $this->createStub(EntityManagerInterface::class);

        $this->expectException(InvalidAnimeTypeMigrationException::class);
        (new AnimeTypeMigrator($entityManager))->migrate($source, AnimeType::Movie);
    }

    public function testMigrateThrowsWhenTargetIsSameBranchAsSeries(): void
    {
        $source = new TvAnime();
        $source->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);

        $entityManager = $this->createStub(EntityManagerInterface::class);

        $this->expectException(InvalidAnimeTypeMigrationException::class);
        (new AnimeTypeMigrator($entityManager))->migrate($source, AnimeType::Ova);
    }

    public function testMigrateThrowsWhenProductionStatusIsOngoing(): void
    {
        $source = new MovieAnime();
        $source->setTitle('Akira')
            ->setDatePremiere(new \DateTimeImmutable('-1 day'))
            ->setWatchStatus(WatchStatus::Plan);

        $entityManager = $this->createStub(EntityManagerInterface::class);

        $this->expectException(InvalidAnimeTypeMigrationException::class);
        (new AnimeTypeMigrator($entityManager))->migrate($source, AnimeType::Tv);
    }

    public function testMigrateThrowsWhenProductionStatusIsReleased(): void
    {
        $source = new MovieAnime();
        $source->setTitle('Akira')
            ->setDatePremiere(new \DateTimeImmutable('-2 days'))
            ->setDateEnd(new \DateTimeImmutable('-1 day'))
            ->setWatchStatus(WatchStatus::Plan);

        $entityManager = $this->createStub(EntityManagerInterface::class);

        $this->expectException(InvalidAnimeTypeMigrationException::class);
        (new AnimeTypeMigrator($entityManager))->migrate($source, AnimeType::Tv);
    }
}
