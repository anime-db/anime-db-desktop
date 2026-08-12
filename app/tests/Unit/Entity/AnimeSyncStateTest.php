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

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\AnimeSyncState;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Repository\AnimeSyncStateRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/**
 * Persistence coverage for the anime_sync_state snapshot table (issue #365). Uses a real
 * EntityManager/SQLite connection since the composite (anime_id, participant_id) PK and the
 * ON DELETE CASCADE only get exercised on an actual flush/delete, not on in-memory state.
 */
final class AnimeSyncStateTest extends TestCase
{
    private EntityManager $entityManager;
    private AnimeSyncStateRepository $repository;

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
        $connection->executeStatement('PRAGMA foreign_keys = ON');
        $this->entityManager = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->repository = new AnimeSyncStateRepository($this->entityManager);
    }

    public function testSaveAndFindRoundTrip(): void
    {
        $anime = $this->persistAnime();
        $updatedAt = new \DateTimeImmutable('2026-01-01 10:00:00');

        $this->repository->save(new AnimeSyncState($anime, 'animedb-shikimori', WatchStatus::Watching, 5, $updatedAt));

        $found = $this->repository->find($anime, 'animedb-shikimori');

        $this->assertNotNull($found);
        $this->assertSame(WatchStatus::Watching, $found->lastStatus);
        $this->assertSame(5, $found->lastWatchedEpisodes);
        $this->assertEquals($updatedAt, $found->lastUpdatedAt);
    }

    public function testLocalIsAValidParticipantIdDespiteNotBeingAPluginIdFormat(): void
    {
        $anime = $this->persistAnime();

        $this->repository->save(new AnimeSyncState($anime, 'local', WatchStatus::Plan, null, new \DateTimeImmutable()));

        $this->assertNotNull($this->repository->find($anime, 'local'));
    }

    public function testFindByAnimeReturnsOneRowPerParticipant(): void
    {
        $anime = $this->persistAnime();
        $now = new \DateTimeImmutable();

        $this->repository->save(new AnimeSyncState($anime, 'local', WatchStatus::Plan, null, $now));
        $this->repository->save(new AnimeSyncState($anime, 'animedb-shikimori', WatchStatus::Watching, 3, $now));

        $this->assertCount(2, $this->repository->findByAnime($anime));
    }

    public function testUpdateMutatesTheExistingRowInPlace(): void
    {
        $anime = $this->persistAnime();
        $state = new AnimeSyncState($anime, 'local', WatchStatus::Plan, null, new \DateTimeImmutable('2026-01-01'));
        $this->repository->save($state);

        $newUpdatedAt = new \DateTimeImmutable('2026-02-01');
        $state->update(WatchStatus::Watching, 3, $newUpdatedAt);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->repository->find($anime, 'local');
        $this->assertNotNull($reloaded);
        $this->assertSame(WatchStatus::Watching, $reloaded->lastStatus);
        $this->assertSame(3, $reloaded->lastWatchedEpisodes);
        $this->assertEquals($newUpdatedAt, $reloaded->lastUpdatedAt);
    }

    public function testDeletingAnimeCascadesToItsSyncStateRows(): void
    {
        $anime = $this->persistAnime();
        $this->repository->save(new AnimeSyncState($anime, 'local', WatchStatus::Plan, null, new \DateTimeImmutable()));

        $this->entityManager->remove($anime);
        $this->entityManager->flush();

        $count = (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM anime_sync_state');
        $this->assertSame(0, $count);
    }

    private function persistAnime(): MovieAnime
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);

        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }
}
