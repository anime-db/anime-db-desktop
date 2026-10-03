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

namespace App\Tests\Unit\Repository;

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Download;
use App\Entity\Enum\WatchStatus;
use App\Entity\TvAnime;
use App\Repository\DownloadRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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

    public function testRemoveDeletesOnlyThePairingRow(): void
    {
        $anime = $this->persistAnime('Anime A');
        $other = $this->persistAnime('Anime B');
        $this->repository->save(new Download(self::HASH_A, $anime));
        $this->repository->save($kept = new Download(self::HASH_B, $other));

        $stored = $this->repository->findByInfoHashAndAnime(self::HASH_A, (int) $anime->id);
        $this->assertNotNull($stored);
        $this->repository->remove($stored);

        $this->assertNull($this->repository->findByInfoHashAndAnime(self::HASH_A, (int) $anime->id));
        $this->assertSame($kept, $this->repository->findByInfoHashAndAnime(self::HASH_B, (int) $other->id));
        $this->assertNotNull($this->entityManager->find(TvAnime::class, $anime->id));
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

    public function testFindByInfoHashDistinguishesKnownFromUnknownHashes(): void
    {
        $anime = $this->persistAnime('Anime A');
        $this->repository->save(new Download(self::HASH_A, $anime));

        $this->assertCount(1, $this->repository->findByInfoHash(self::HASH_A));
        $this->assertSame([], $this->repository->findByInfoHash(self::HASH_B));
    }

    public function testSameInfoHashCannotBeLinkedToSeveralAnime(): void
    {
        $animeOne = $this->persistAnime('Anime One');
        $animeTwo = $this->persistAnime('Anime Two');

        $this->repository->save(new Download(self::HASH_A, $animeOne));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->repository->save(new Download(self::HASH_A, $animeTwo));
    }

    public function testFindAnimeIdByInfoHash(): void
    {
        $anime = $this->persistAnime('Anime A');
        $this->repository->save(new Download(self::HASH_A, $anime));

        $this->assertSame($anime->id, $this->repository->findAnimeIdByInfoHash(self::HASH_A));
        $this->assertNull($this->repository->findAnimeIdByInfoHash(self::HASH_B));
    }

    public function testFindPendingByInfoHashExcludesCompletedRows(): void
    {
        $animeOne = $this->persistAnime('Anime One');
        $animeTwo = $this->persistAnime('Anime Two');

        $pending = new Download(self::HASH_A, $animeOne);
        $completed = new Download(self::HASH_B, $animeTwo);
        $completed->markCompleted();

        $this->repository->save($pending);
        $this->repository->save($completed);

        $result = $this->repository->findPendingByInfoHash(self::HASH_A);

        $this->assertCount(1, $result);
        $this->assertSame($animeOne->id, $result[0]->getAnime()->id);
        $this->assertSame([], $this->repository->findPendingByInfoHash(self::HASH_B));
    }

    public function testFindByAnimeReturnsOnlyThatAnimesRows(): void
    {
        $anime = $this->persistAnime('Anime A');
        $other = $this->persistAnime('Anime B');
        $this->repository->save($kept = new Download(self::HASH_A, $anime));
        $this->repository->save(new Download(self::HASH_B, $other));

        $result = $this->repository->findByAnime((int) $anime->id);

        $this->assertSame([$kept], $result);
    }

    public function testFindByAnimeReturnsEmptyArrayWhenTheAnimeHasNoDownloads(): void
    {
        $anime = $this->persistAnime('Anime A');

        $this->assertSame([], $this->repository->findByAnime((int) $anime->id));
    }

    public function testFindDistinctPendingInfoHashesReturnsEachHashOnceAndOmitsFullyCompletedOnes(): void
    {
        $animeOne = $this->persistAnime('Anime One');
        $animeThree = $this->persistAnime('Unrelated');

        $this->repository->save(new Download(self::HASH_A, $animeOne));

        $fullyCompleted = new Download(self::HASH_B, $animeThree);
        $fullyCompleted->markCompleted();
        $this->repository->save($fullyCompleted);

        $this->assertSame([self::HASH_A], $this->repository->findDistinctPendingInfoHashes());
    }
}
