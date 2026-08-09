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
use App\Entity\Download;
use App\Entity\Enum\WatchStatus;
use App\Entity\TvAnime;
use App\Repository\DownloadRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

final class DownloadRepositoryTest extends TestCase
{
    private const string HASH_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string HASH_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private EntityManager $entityManager;
    private DownloadRepository $repository;

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

        $this->repository = new DownloadRepository($this->entityManager);
    }

    private function persistAnime(string $title): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle($title)->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    public function testSavePersistsDownload(): void
    {
        $anime = $this->persistAnime('Anime A');

        $this->repository->save(new Download(self::HASH_A, $anime));

        $stored = $this->repository->findByInfoHashAndAnime(self::HASH_A, (int) $anime->id);
        $this->assertNotNull($stored);
        $this->assertSame(self::HASH_A, $stored->getInfoHash());
    }

    public function testFindByInfoHashAndAnimeReturnsNullWhenNoRowMatches(): void
    {
        $anime = $this->persistAnime('Anime A');

        $this->assertNull($this->repository->findByInfoHashAndAnime(self::HASH_A, (int) $anime->id));
    }

    public function testHasAnyForInfoHashDistinguishesKnownFromUnknownHashes(): void
    {
        $anime = $this->persistAnime('Anime A');
        $this->repository->save(new Download(self::HASH_A, $anime));

        $this->assertTrue($this->repository->hasAnyForInfoHash(self::HASH_A));
        $this->assertFalse($this->repository->hasAnyForInfoHash(self::HASH_B));
    }

    public function testSameInfoHashCanBeLinkedToSeveralAnimeSeasonPack(): void
    {
        $animeOne = $this->persistAnime('Season 1');
        $animeTwo = $this->persistAnime('Season 2');

        $this->repository->save(new Download(self::HASH_A, $animeOne));
        $this->repository->save(new Download(self::HASH_A, $animeTwo));

        $this->assertCount(2, $this->repository->findByInfoHash(self::HASH_A));
    }

    public function testFindPendingByInfoHashExcludesCompletedRows(): void
    {
        $animeOne = $this->persistAnime('Season 1');
        $animeTwo = $this->persistAnime('Season 2');

        $pending = new Download(self::HASH_A, $animeOne);
        $completed = new Download(self::HASH_A, $animeTwo);
        $completed->markCompleted();

        $this->repository->save($pending);
        $this->repository->save($completed);

        $result = $this->repository->findPendingByInfoHash(self::HASH_A);

        $this->assertCount(1, $result);
        $this->assertSame($animeOne->id, $result[0]->getAnime()->id);
    }

    public function testFindDistinctPendingInfoHashesReturnsEachHashOnceAndOmitsFullyCompletedOnes(): void
    {
        $animeOne = $this->persistAnime('Season 1');
        $animeTwo = $this->persistAnime('Season 2');
        $animeThree = $this->persistAnime('Unrelated');

        $this->repository->save(new Download(self::HASH_A, $animeOne));
        $this->repository->save(new Download(self::HASH_A, $animeTwo));

        $fullyCompleted = new Download(self::HASH_B, $animeThree);
        $fullyCompleted->markCompleted();
        $this->repository->save($fullyCompleted);

        $this->assertSame([self::HASH_A], $this->repository->findDistinctPendingInfoHashes());
    }
}
