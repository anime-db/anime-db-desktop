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

namespace App\Tests\Unit\Service\Plugin;

use AnimeDb\PluginContracts\Model\AnimeId;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\AnimePluginData;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\PluginDataStore;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Uses a real in-memory SQLite connection (see AnimeTypeMigratorPersistenceTest for the same
 * rationale): the interesting behaviour here — retrying past a real Doctrine
 * OptimisticLockException by getting a fresh EntityManager from the registry — cannot be
 * observed against a mocked EntityManager, since the whole point is what Doctrine does
 * internally when a version-checked UPDATE affects zero rows.
 */
final class PluginDataStoreTest extends TestCase
{
    private Connection $connection;
    private \Doctrine\ORM\Configuration $ormConfig;
    private EntityManager $entityManager;
    private int $animeId;

    protected function setUp(): void
    {
        if (!Type::hasType(UnixTimestampType::NAME)) {
            Type::addType(UnixTimestampType::NAME, UnixTimestampType::class);
        }
        if (!Type::hasType(RatingType::NAME)) {
            Type::addType(RatingType::NAME, RatingType::class);
        }

        $this->ormConfig = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 4).'/src/Entity'], true);
        $this->ormConfig->enableNativeLazyObjects(true);

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $this->ormConfig);
        $this->entityManager = new EntityManager($this->connection, $this->ormConfig);

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $this->assertNotNull($anime->id);
        $this->animeId = $anime->id;
    }

    /** @return ManagerRegistry a stub whose getManagerForClass() tracks resetManager() calls */
    private function registry(): ManagerRegistry
    {
        $current = $this->entityManager;

        $registry = $this->createMock(ManagerRegistry::class);
        // Regular closures, not arrow functions: an arrow function captures $current by value at
        // creation time, so getManagerForClass() would keep returning the *original* manager
        // forever, never seeing resetManager()'s reassignment below.
        $registry->method('getManagerForClass')->willReturnCallback(function () use (&$current): EntityManager {
            return $current;
        });
        $registry->method('resetManager')->willReturnCallback(function () use (&$current): EntityManager {
            $current = new EntityManager($this->connection, $this->ormConfig);

            return $current;
        });

        return $registry;
    }

    public function testReadDefaultsToEmptyArray(): void
    {
        $store = new PluginDataStore(new PluginId('animedb-shikimori'), $this->registry());

        $this->assertSame([], $store->read(new AnimeId($this->animeId)));
    }

    public function testWriteThenReadRoundTrips(): void
    {
        $store = new PluginDataStore(new PluginId('animedb-shikimori'), $this->registry());

        $store->write(new AnimeId($this->animeId), ['mal_id' => 1]);

        $this->assertSame(['mal_id' => 1], $store->read(new AnimeId($this->animeId)));
    }

    public function testWriteOverridesRatherThanMergesWithPreviousPayload(): void
    {
        $store = new PluginDataStore(new PluginId('animedb-shikimori'), $this->registry());

        $store->write(new AnimeId($this->animeId), ['mal_id' => 1]);
        $store->write(new AnimeId($this->animeId), ['rating' => 8.5]);

        $this->assertSame(['rating' => 8.5], $store->read(new AnimeId($this->animeId)));
    }

    public function testDifferentPluginsDoNotShareARow(): void
    {
        $shikimori = new PluginDataStore(new PluginId('animedb-shikimori'), $this->registry());
        $mal = new PluginDataStore(new PluginId('animedb-mal'), $this->registry());

        $shikimori->write(new AnimeId($this->animeId), ['mal_id' => 1]);
        $mal->write(new AnimeId($this->animeId), ['mal_id' => 2]);

        $this->assertSame(['mal_id' => 1], $shikimori->read(new AnimeId($this->animeId)));
        $this->assertSame(['mal_id' => 2], $mal->read(new AnimeId($this->animeId)));
    }

    /**
     * Simulates a concurrent writer: the row is already loaded into this test's identity map
     * (stale, version 1) before another process's write is applied directly against the
     * database (bypassing the identity map, the same way a second PHP process/EntityManager
     * would). write()'s first attempt overrides onto the stale copy and its flush()'s
     * version-checked UPDATE affects zero rows, so Doctrine raises OptimisticLockException and
     * closes the EntityManager; the retry has to come back with a fresh one from the registry
     * (resetManager()) to see the row a concurrent writer already changed. write() is a full
     * replace (contracts v0.8.0), so the retry does not preserve what the concurrent writer
     * left — it overrides with $data again, discarding it.
     */
    public function testWriteRetriesPastAnOptimisticLockConflictAndStillOverrides(): void
    {
        $store = new PluginDataStore(new PluginId('animedb-shikimori'), $this->registry());
        $store->write(new AnimeId($this->animeId), ['mal_id' => 1]);

        // Load into this EntityManager's identity map now, at version 1, so the write() call
        // below reuses this stale in-memory copy instead of issuing a fresh SELECT.
        $row = $this->entityManager->getRepository(AnimePluginData::class)->findOneBy(['anime' => $this->animeId]);
        $this->assertNotNull($row);
        $this->assertSame(1, $row->getVersion());

        // A concurrent writer's already-committed change, applied directly so it never touches
        // this test's identity map.
        $this->connection->executeStatement(
            "UPDATE anime_plugin_data SET payload = '{\"rating\":8.5}', version = 2 WHERE anime_id = ?",
            [$this->animeId],
        );

        $store->write(new AnimeId($this->animeId), ['episodes_count' => 26]);

        $this->assertSame(
            ['episodes_count' => 26],
            $store->read(new AnimeId($this->animeId)),
        );
    }
}
