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

namespace App\Tests\Unit\Repository;

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\Enum\AnimeNameType;
use App\Entity\Enum\AnimeSortField;
use App\Entity\Enum\AnimeType;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\SortDirection;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\WatchStatus;
use App\Entity\Label;
use App\Entity\MovieAnime;
use App\Entity\Storage;
use App\Entity\Studio;
use App\Entity\TvAnime;
use App\Entity\ValueObject\Rating;
use App\Repository\AnimeListFilter;
use App\Repository\AnimeListSort;
use App\Repository\AnimeRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/**
 * Exercises AnimeRepository against a real EntityManager/SQLite connection, following the
 * same setup as AnimeDiscriminatorPersistenceTest. The key property under test (issue #74)
 * is that countByFilter() and findByFilter() never drift apart: both are built from the
 * same createFilteredQueryBuilder(), so total must always equal the number of rows you get
 * by paging through the whole result set.
 */
final class AnimeRepositoryTest extends TestCase
{
    private EntityManager $entityManager;
    private AnimeRepository $repository;

    private Studio $sunrise;
    private Studio $toei;
    private Label $favorite;

    protected function setUp(): void
    {
        if (!Type::hasType(UnixTimestampType::NAME)) {
            Type::addType(UnixTimestampType::NAME, UnixTimestampType::class);
        }
        if (!Type::hasType(RatingType::NAME)) {
            Type::addType(RatingType::NAME, RatingType::class);
        }

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 3).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->entityManager = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
        $this->createAnimeFtsSchema();

        $this->repository = new AnimeRepository($this->entityManager);

        $this->seedFixtures();
    }

    /**
     * SchemaTool builds the schema from Doctrine entity metadata only, so it has no concept
     * of the anime_fts FTS5 virtual table/triggers created by the raw-SQL Version20260713000000
     * migration (issue #195) — it must be created by hand here, same as in AnimeFtsSchemaTest.
     */
    private function createAnimeFtsSchema(): void
    {
        $connection = $this->entityManager->getConnection();

        $connection->executeStatement('CREATE VIRTUAL TABLE anime_fts USING fts5(name, anime_id UNINDEXED)');

        $connection->executeStatement('
            CREATE TRIGGER anime_fts_ai_anime AFTER INSERT ON anime BEGIN
                INSERT INTO anime_fts(rowid, anime_id, name) VALUES (new.id, new.id, new.title);
            END
        ');
        $connection->executeStatement('
            CREATE TRIGGER anime_fts_au_anime AFTER UPDATE OF title ON anime BEGIN
                UPDATE anime_fts SET name = new.title WHERE rowid = new.id;
            END
        ');
        $connection->executeStatement('
            CREATE TRIGGER anime_fts_ad_anime AFTER DELETE ON anime BEGIN
                DELETE FROM anime_fts WHERE rowid = old.id;
            END
        ');

        $connection->executeStatement('
            CREATE TRIGGER anime_fts_ai_anime_name AFTER INSERT ON anime_name BEGIN
                INSERT INTO anime_fts(rowid, anime_id, name) VALUES (-new.id, new.anime_id, new.name);
            END
        ');
        $connection->executeStatement('
            CREATE TRIGGER anime_fts_au_anime_name AFTER UPDATE OF name ON anime_name BEGIN
                UPDATE anime_fts SET name = new.name WHERE rowid = -new.id;
            END
        ');
        $connection->executeStatement('
            CREATE TRIGGER anime_fts_ad_anime_name AFTER DELETE ON anime_name BEGIN
                DELETE FROM anime_fts WHERE rowid = -old.id;
            END
        ');
    }

    private function seedFixtures(): void
    {
        $this->sunrise = new Studio();
        $this->sunrise->rename('Sunrise');
        $this->toei = new Studio();
        $this->toei->rename('Toei');
        $this->favorite = new Label();
        $this->favorite->rename('favorite');

        $this->entityManager->persist($this->sunrise);
        $this->entityManager->persist($this->toei);
        $this->entityManager->persist($this->favorite);

        // Watching, JP, rating 5, Action, Sunrise, favorite, premiered 2020.
        $a1 = new TvAnime();
        $a1->setTitle('Trigun')
            ->setWatchStatus(WatchStatus::Watching)
            ->setCountries(['JP'])
            ->setUserRating(new Rating(5))
            ->setDatePremiere(new \DateTimeImmutable('2020-01-01'));
        $a1->addGenre(GenreCode::Action)->addStudio($this->sunrise)->addLabel($this->favorite);
        $a1->addName('Toraiga', AnimeNameType::Synonym);

        // Watching, US, rating 3, Comedy, Toei, no label, premiered 2021.
        $a2 = new MovieAnime();
        $a2->setTitle('A Comedy Movie')
            ->setWatchStatus(WatchStatus::Watching)
            ->setCountries(['US'])
            ->setUserRating(new Rating(3))
            ->setDatePremiere(new \DateTimeImmutable('2021-06-01'));
        $a2->addGenre(GenreCode::Comedy)->addStudio($this->toei);

        // Plan (not Watching), JP, no rating, Action, Sunrise, premiered 2019.
        $a3 = new TvAnime();
        $a3->setTitle('Planned Show')
            ->setWatchStatus(WatchStatus::Plan)
            ->setCountries(['JP'])
            ->setDatePremiere(new \DateTimeImmutable('2019-01-01'));
        $a3->addGenre(GenreCode::Action)->addStudio($this->sunrise);

        // Watching, JP+US, rating 4, Action AND Drama, Toei, favorite, premiered 2022.
        $a4 = new TvAnime();
        $a4->setTitle('Drama Series')
            ->setWatchStatus(WatchStatus::Watching)
            ->setCountries(['JP', 'US'])
            ->setUserRating(new Rating(4))
            ->setDatePremiere(new \DateTimeImmutable('2022-01-01'));
        $a4->addGenre(GenreCode::Action)->addGenre(GenreCode::Drama)->addStudio($this->toei)->addLabel($this->favorite);

        foreach ([$a1, $a2, $a3, $a4] as $anime) {
            $this->entityManager->persist($anime);
        }
        $this->entityManager->flush();
        $this->entityManager->clear();

        // Re-fetch the managed studio/label instances after clear() so filters below can use their ids.
        $this->sunrise = $this->entityManager->getRepository(Studio::class)->findOneBy(['name' => 'Sunrise'])
            ?? throw new \LogicException('Sunrise studio fixture must exist after flush()');
        $this->toei = $this->entityManager->getRepository(Studio::class)->findOneBy(['name' => 'Toei'])
            ?? throw new \LogicException('Toei studio fixture must exist after flush()');
        $this->favorite = $this->entityManager->getRepository(Label::class)->findOneBy(['name' => 'favorite'])
            ?? throw new \LogicException('favorite label fixture must exist after flush()');
    }

    public function testHasAnyReturnsTrueWhenAnimeExists(): void
    {
        $this->assertTrue($this->repository->hasAny());
    }

    /**
     * Studio/Label ids are nullable at the type level (unset before persist); the fixtures
     * above are always persisted and flushed first, so this narrows the type for the filters below.
     */
    private static function requireId(Studio|Label $entity): int
    {
        return $entity->id ?? throw new \LogicException('entity id must be set after persisting');
    }

    private function defaultSort(): AnimeListSort
    {
        return new AnimeListSort(AnimeSortField::DateUpdate, SortDirection::Desc);
    }

    /** @return list<string> */
    private function titlesOf(AnimeListFilter $filter): array
    {
        $items = $this->repository->findByFilter($filter, $this->defaultSort(), 100, 0);

        return array_map(static fn (Anime $a): string => $a->getTitle(), $items);
    }

    public function testFilterByWatchStatusOnlyExcludesOtherStatuses(): void
    {
        $filter = new AnimeListFilter(watchStatus: WatchStatus::Watching);

        $this->assertSame(3, $this->repository->countByFilter($filter));
        $this->assertEqualsCanonicalizing(['Trigun', 'A Comedy Movie', 'Drama Series'], $this->titlesOf($filter));
    }

    public function testFilterByTypeNarrowsToTheGivenAnimeClass(): void
    {
        $filter = new AnimeListFilter(watchStatus: WatchStatus::Watching, type: AnimeType::Movie);

        $this->assertSame(['A Comedy Movie'], $this->titlesOf($filter));
    }

    public function testFilterByCountryChecksJsonArrayMembership(): void
    {
        $filter = new AnimeListFilter(watchStatus: WatchStatus::Watching, country: 'US');

        $this->assertEqualsCanonicalizing(['A Comedy Movie', 'Drama Series'], $this->titlesOf($filter));
    }

    public function testFilterByNameMatchesTheMainTitle(): void
    {
        $filter = new AnimeListFilter(watchStatus: WatchStatus::Watching, name: 'Trigun');

        $this->assertSame(['Trigun'], $this->titlesOf($filter));
    }

    public function testFilterByNameMatchesAnAlternativeName(): void
    {
        // 'Toraiga' is only in Trigun's anime_name records, never in Anime::$title.
        $filter = new AnimeListFilter(watchStatus: WatchStatus::Watching, name: 'Toraiga');

        $this->assertSame(['Trigun'], $this->titlesOf($filter));
    }

    public function testFilterByNameDoesNotAffectTheQueryWhenEmpty(): void
    {
        $withoutName = new AnimeListFilter(watchStatus: WatchStatus::Watching);
        $withNullName = new AnimeListFilter(watchStatus: WatchStatus::Watching, name: null);

        $this->assertSame($this->repository->countByFilter($withoutName), $this->repository->countByFilter($withNullName));
        $this->assertEqualsCanonicalizing($this->titlesOf($withoutName), $this->titlesOf($withNullName));
    }

    public function testFilterByGenresUsesOrSemantics(): void
    {
        $filter = new AnimeListFilter(watchStatus: WatchStatus::Watching, genres: [GenreCode::Comedy, GenreCode::Drama]);

        $this->assertEqualsCanonicalizing(['A Comedy Movie', 'Drama Series'], $this->titlesOf($filter));
    }

    public function testFilterByGenresDoesNotDuplicateAnimeMatchingSeveralSelectedGenres(): void
    {
        // Drama Series has both Action and Drama; selecting both must not double-count it.
        $filter = new AnimeListFilter(watchStatus: WatchStatus::Watching, genres: [GenreCode::Action, GenreCode::Drama]);

        $this->assertSame(2, $this->repository->countByFilter($filter));
        $this->assertEqualsCanonicalizing(['Trigun', 'Drama Series'], $this->titlesOf($filter));
    }

    public function testFilterByStudiosUsesOrSemantics(): void
    {
        $filter = new AnimeListFilter(
            watchStatus: WatchStatus::Watching,
            studioIds: [self::requireId($this->sunrise), self::requireId($this->toei)],
        );

        $this->assertEqualsCanonicalizing(['Trigun', 'A Comedy Movie', 'Drama Series'], $this->titlesOf($filter));

        $sunriseOnly = new AnimeListFilter(watchStatus: WatchStatus::Watching, studioIds: [self::requireId($this->sunrise)]);
        $this->assertSame(['Trigun'], $this->titlesOf($sunriseOnly));
    }

    public function testFilterByLabelsUsesOrSemantics(): void
    {
        $filter = new AnimeListFilter(watchStatus: WatchStatus::Watching, labelIds: [self::requireId($this->favorite)]);

        $this->assertEqualsCanonicalizing(['Trigun', 'Drama Series'], $this->titlesOf($filter));
    }

    public function testFilterByUserRatingRange(): void
    {
        $filter = new AnimeListFilter(watchStatus: WatchStatus::Watching, userRatingFrom: 4, userRatingTo: 5);

        $this->assertEqualsCanonicalizing(['Trigun', 'Drama Series'], $this->titlesOf($filter));
    }

    public function testFilterByDatePremiereRangeIsInclusiveOnBothEnds(): void
    {
        $filter = new AnimeListFilter(
            watchStatus: WatchStatus::Watching,
            datePremiereFrom: new \DateTimeImmutable('2021-01-01'),
            datePremiereTo: new \DateTimeImmutable('2022-01-01'),
        );

        $this->assertEqualsCanonicalizing(['A Comedy Movie', 'Drama Series'], $this->titlesOf($filter));
    }

    public function testTotalStaysConsistentWithSelectAcrossPaginationUnderAnActiveFilter(): void
    {
        // Combine a to-many OR filter with a range filter to stress the shared query builder.
        $filter = new AnimeListFilter(
            watchStatus: WatchStatus::Watching,
            genres: [GenreCode::Action, GenreCode::Comedy, GenreCode::Drama],
            userRatingFrom: 3,
        );

        $total = $this->repository->countByFilter($filter);
        $this->assertSame(3, $total);

        $seenIds = [];
        for ($offset = 0; $offset < $total; ++$offset) {
            $page = $this->repository->findByFilter($filter, $this->defaultSort(), 1, $offset);
            $this->assertCount(1, $page, "page at offset {$offset} must contain exactly one row");
            $seenIds[] = $page[0]->id;
        }

        // Every page returned a distinct row, and paging exactly $total times exhausted the result set.
        $this->assertCount($total, array_unique($seenIds));
        $this->assertCount(0, $this->repository->findByFilter($filter, $this->defaultSort(), 1, $total));
    }

    public function testFindByStorageOnlyReturnsAnimeLinkedToThatStorageWithAStoragePath(): void
    {
        $storage = new Storage('Main folder', sys_get_temp_dir(), StorageType::Folder);
        $otherStorage = new Storage('Other folder', sys_get_temp_dir(), StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->persist($otherStorage);

        $linked = new TvAnime();
        $linked->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $linked->setStorage($storage)->setStoragePath('Trigun');

        $linkedToOtherStorage = new TvAnime();
        $linkedToOtherStorage->setTitle('Bleach')->setWatchStatus(WatchStatus::Plan);
        $linkedToOtherStorage->setStorage($otherStorage)->setStoragePath('Bleach');

        $storageWithoutPath = new TvAnime();
        $storageWithoutPath->setTitle('Naruto')->setWatchStatus(WatchStatus::Plan);
        $storageWithoutPath->setStorage($storage);

        $orphan = new TvAnime();
        $orphan->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);

        foreach ([$linked, $linkedToOtherStorage, $storageWithoutPath, $orphan] as $anime) {
            $this->entityManager->persist($anime);
        }
        $this->entityManager->flush();

        $this->assertSame([$linked], $this->repository->findByStorage($storage));
    }
}
