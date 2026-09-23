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

namespace App\Tests\Unit\Service;

use App\Entity\Enum\AnimeNameRole;
use App\Entity\Enum\AnimeType;
use App\Entity\Enum\Demographic;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\ThemeCode;
use App\Entity\Enum\WatchStatus;
use App\Entity\Exception\InvalidAnimeTypeMigrationException;
use App\Entity\Label;
use App\Entity\MovieAnime;
use App\Entity\OvaAnime;
use App\Entity\Storage;
use App\Entity\Studio;
use App\Entity\TvAnime;
use App\Entity\ValueObject\Rating;
use App\Service\AnimeTypeMigrator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class AnimeTypeMigratorTest extends TestCase
{
    private const MEDIA_DIR = '/nonexistent/media';

    private function stubEntityManager(): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $func) => $func());

        return $entityManager;
    }

    public function testMigrateFromMovieToSeriesCopiesCommonFields(): void
    {
        $storage = new Storage('Main folder', self::MEDIA_DIR, StorageType::Folder);

        $source = new MovieAnime();
        $source->setTitle('Cowboy Bebop: The Movie')
            ->setDatePremiere(new \DateTimeImmutable('+1 day'))
            ->setDateEnd(new \DateTimeImmutable('+2 days'))
            ->setDurationMinutes(115)
            ->setNotes('to rewatch')
            ->setUserRating(new Rating(5))
            ->setCover('cover.jpg')
            ->setStorage($storage)
            ->setStoragePath('Cowboy Bebop The Movie')
            ->setCountries(['JP'])
            ->setWatchStatus(WatchStatus::Plan);

        $entityManager = $this->stubEntityManager();
        $migrator = new AnimeTypeMigrator($entityManager, self::MEDIA_DIR);

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
        $this->assertSame('Cowboy Bebop The Movie', $target->getStoragePath());
        $this->assertSame(['JP'], $target->getCountries());
        $this->assertSame(WatchStatus::Plan, $target->getWatchStatus());
    }

    public function testMigrateFromSeriesToMovieDoesNotCarryEpisodeFields(): void
    {
        $source = new TvAnime();
        $source->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);

        $entityManager = $this->stubEntityManager();
        $migrator = new AnimeTypeMigrator($entityManager, self::MEDIA_DIR);

        $target = $migrator->migrate($source, AnimeType::Movie);

        $this->assertInstanceOf(MovieAnime::class, $target);
        $this->assertSame('Trigun', $target->getTitle());
    }

    public function testMigrateCarriesDurationMinutesOverWithoutReset(): void
    {
        $source = new MovieAnime();
        $source->setTitle('Akira')->setDurationMinutes(124)->setWatchStatus(WatchStatus::Plan);

        $entityManager = $this->stubEntityManager();
        $migrator = new AnimeTypeMigrator($entityManager, self::MEDIA_DIR);

        $target = $migrator->migrate($source, AnimeType::Tv);

        $this->assertSame(124, $target->getDurationMinutes());
    }

    public function testMigrateFromSeriesToMovieCarriesDurationMinutesOverWithoutReset(): void
    {
        $source = new TvAnime();
        $source->setTitle('Trigun')->setDurationMinutes(24)->setWatchStatus(WatchStatus::Plan);
        $source->setEpisodesCount(26);

        $entityManager = $this->stubEntityManager();
        $migrator = new AnimeTypeMigrator($entityManager, self::MEDIA_DIR);

        $target = $migrator->migrate($source, AnimeType::Movie);

        $this->assertSame(24, $target->getDurationMinutes());
    }

    public function testMigrateTransfersGenresThemesDemographicStudiosLabelsNamesImagesAndSources(): void
    {
        $studio = new Studio();
        $studio->rename('Sunrise');
        $label = new Label();
        $label->rename('favorite');

        $source = new MovieAnime();
        $source->setTitle('Cowboy Bebop: The Movie')
            ->setWatchStatus(WatchStatus::Plan)
            ->addGenre(GenreCode::Action)
            ->addTheme(ThemeCode::Isekai)
            ->setDemographic(Demographic::Seinen)
            ->addStudio($studio)
            ->addLabel($label)
            ->addName('Cowboy Bebop', 'en', AnimeNameRole::Official)
            ->addImage('images/frame1.jpg')
            ->addSource('https://shikimori.one/animes/1');

        $entityManager = $this->stubEntityManager();
        $migrator = new AnimeTypeMigrator($entityManager, self::MEDIA_DIR);

        $target = $migrator->migrate($source, AnimeType::Tv);

        $this->assertSame([GenreCode::Action], $target->getGenreCodes());
        $this->assertSame([ThemeCode::Isekai], $target->getThemeCodes());
        $this->assertSame(Demographic::Seinen, $target->getDemographic());
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
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $func) => $func());
        $entityManager->expects($this->once())->method('persist');
        $entityManager->expects($this->once())->method('remove')->with($source);
        // Two flushes, not one: the target needs its own id assigned (from the first flush)
        // before anime_plugin_data rows could be repointed to it — moot here since $source was
        // never persisted (no id, so the repoint step is skipped), but the flush split itself is
        // unconditional. See AnimeTypeMigrator::migrate().
        $entityManager->expects($this->exactly(2))->method('flush');

        (new AnimeTypeMigrator($entityManager, self::MEDIA_DIR))->migrate($source, AnimeType::Tv);
    }

    public function testMigrateThrowsWhenTargetIsSameBranchAsMovie(): void
    {
        $source = new MovieAnime();
        $source->setTitle('Akira')->setWatchStatus(WatchStatus::Plan);

        $entityManager = $this->stubEntityManager();

        $this->expectException(InvalidAnimeTypeMigrationException::class);
        (new AnimeTypeMigrator($entityManager, self::MEDIA_DIR))->migrate($source, AnimeType::Movie);
    }

    public function testMigrateBetweenSeriesLeavesCarriesEpisodeFields(): void
    {
        $source = new TvAnime();
        $source->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $source->setEpisodesCount(26);

        $entityManager = $this->stubEntityManager();
        $migrator = new AnimeTypeMigrator($entityManager, self::MEDIA_DIR);

        $target = $migrator->migrate($source, AnimeType::Ova);

        $this->assertInstanceOf(OvaAnime::class, $target);
        $this->assertSame('Trigun', $target->getTitle());
        $this->assertSame(26, $target->getEpisodesCount());
        $this->assertNull($target->getWatchedEpisodes());
    }

    public function testMigrateThrowsWhenTargetIsTheSourceTypeItself(): void
    {
        $source = new TvAnime();
        $source->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);

        $entityManager = $this->stubEntityManager();

        $this->expectException(InvalidAnimeTypeMigrationException::class);
        (new AnimeTypeMigrator($entityManager, self::MEDIA_DIR))->migrate($source, AnimeType::Tv);
    }

    public function testMigrateThrowsWhenProductionStatusIsOngoing(): void
    {
        $source = new MovieAnime();
        $source->setTitle('Akira')
            ->setDatePremiere(new \DateTimeImmutable('-1 day'))
            ->setWatchStatus(WatchStatus::Plan);

        $entityManager = $this->stubEntityManager();

        $this->expectException(InvalidAnimeTypeMigrationException::class);
        (new AnimeTypeMigrator($entityManager, self::MEDIA_DIR))->migrate($source, AnimeType::Tv);
    }

    public function testMigrateThrowsWhenProductionStatusIsReleased(): void
    {
        $source = new MovieAnime();
        $source->setTitle('Akira')
            ->setDatePremiere(new \DateTimeImmutable('-2 days'))
            ->setDateEnd(new \DateTimeImmutable('-1 day'))
            ->setWatchStatus(WatchStatus::Plan);

        $entityManager = $this->stubEntityManager();

        $this->expectException(InvalidAnimeTypeMigrationException::class);
        (new AnimeTypeMigrator($entityManager, self::MEDIA_DIR))->migrate($source, AnimeType::Tv);
    }
}
