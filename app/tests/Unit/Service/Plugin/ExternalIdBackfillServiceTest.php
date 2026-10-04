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

use AnimeDb\PluginContracts\Sync\SyncInterface;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Entity\ValueObject\PluginId;
use App\Repository\AnimeRepository;
use App\Service\JobLock\JobLockService;
use App\Service\JobLock\ProcessLivenessChecker;
use App\Service\Plugin\ExternalIdBackfillService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * Exercises ExternalIdBackfillService end to end: a real EntityManager/SQLite
 * connection for the catalog plus a real JobLockService backed by an in-memory "queue"
 * connection (same setup as ScanStorageMessageHandlerTest) — the job_locks re-entrancy
 * guard and the actual metadata write only mean something against real storage, not mocks.
 */
final class ExternalIdBackfillServiceTest extends TestCase
{
    private const string PLUGIN_ID = 'animedb-shikimori';

    private EntityManager $entityManager;
    private Connection $queueConnection;

    protected function setUp(): void
    {
        if (!Type::hasType(UnixTimestampType::NAME)) {
            Type::addType(UnixTimestampType::NAME, UnixTimestampType::class);
        }
        if (!Type::hasType(RatingType::NAME)) {
            Type::addType(RatingType::NAME, RatingType::class);
        }

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 4).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->entityManager = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->queueConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    public function testResolvesAndCachesExternalIdForAnimeWithAMatchingSource(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching)->addSource('https://shikimori.one/animes/1');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $animeId = $this->requireId($anime);

        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->once())->method('resolveExternalId')->willReturn('1');

        $this->newService()->backfill(new PluginId(self::PLUGIN_ID), $sync);

        $reloaded = $this->requireAnime($animeId);
        $this->assertSame('1', $reloaded->getCachedExternalId(new PluginId(self::PLUGIN_ID)));
    }

    public function testSkipsAnimeThatAlreadyHasACachedExternalId(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching)->addSource('https://shikimori.one/animes/1');
        $anime->rememberExternalId(new PluginId(self::PLUGIN_ID), '1');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->never())->method('resolveExternalId');

        $this->newService()->backfill(new PluginId(self::PLUGIN_ID), $sync);
    }

    public function testSkipsWhileAnotherBackfillForTheSamePluginIsAlreadyRunning(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching)->addSource('https://shikimori.one/animes/1');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $this->queueConnection->executeStatement('CREATE TABLE IF NOT EXISTS job_locks (
            job_key VARCHAR(255) PRIMARY KEY NOT NULL,
            pid INTEGER NOT NULL,
            heartbeat_at INTEGER NOT NULL,
            started_at INTEGER NOT NULL
        )');
        $this->queueConnection->insert('job_locks', [
            'job_key' => 'sync_backfill:'.self::PLUGIN_ID,
            'pid' => 424242,
            'heartbeat_at' => 1000,
            'started_at' => 1000,
        ]);

        $livenessChecker = $this->createStub(ProcessLivenessChecker::class);
        $livenessChecker->method('getStartedAt')->willReturn(new \DateTimeImmutable('@1000'));

        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->never())->method('resolveExternalId');

        $this->newService($livenessChecker)->backfill(new PluginId(self::PLUGIN_ID), $sync);
    }

    public function testContinuesPastAnAnimeWhoseResolveExternalIdThrows(): void
    {
        $failing = new MovieAnime();
        $failing->setTitle('Broken')->setWatchStatus(WatchStatus::Watching)->addSource('https://shikimori.one/animes/1');
        $this->entityManager->persist($failing);

        $healthy = new MovieAnime();
        $healthy->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching)->addSource('https://shikimori.one/animes/2');
        $this->entityManager->persist($healthy);

        $this->entityManager->flush();
        $failingId = $this->requireId($failing);
        $healthyId = $this->requireId($healthy);

        $sync = $this->createStub(SyncInterface::class);
        $sync->method('resolveExternalId')->willReturnCallback(
            static fn (array $urls): string => match (true) {
                \in_array('https://shikimori.one/animes/1', $urls, true) => throw new \RuntimeException('boom'),
                default => '2',
            },
        );

        $this->newService()->backfill(new PluginId(self::PLUGIN_ID), $sync);

        $this->entityManager->clear();
        $reloadedFailing = $this->requireAnime($failingId);
        $reloadedHealthy = $this->requireAnime($healthyId);

        $this->assertNull($reloadedFailing->getCachedExternalId(new PluginId(self::PLUGIN_ID)));
        $this->assertSame('2', $reloadedHealthy->getCachedExternalId(new PluginId(self::PLUGIN_ID)));
    }

    public function testSkipsAnimeWhoseResolvedIdIsAlreadyHeldByAnotherRecord(): void
    {
        $holder = new MovieAnime();
        $holder->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching)->addSource('https://shikimori.one/animes/1');
        $holder->rememberExternalId(new PluginId(self::PLUGIN_ID), '1');
        $this->entityManager->persist($holder);

        $duplicate = new MovieAnime();
        $duplicate->setTitle('Cowboy Bebop (copy)')->setWatchStatus(WatchStatus::Watching)->addSource('https://shikimori.one/animes/1');
        $this->entityManager->persist($duplicate);

        $this->entityManager->flush();
        $duplicateId = $this->requireId($duplicate);

        $sync = $this->createStub(SyncInterface::class);
        $sync->method('resolveExternalId')->willReturn('1');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->anything(),
            $this->callback(static fn (array $context): bool => $context['external_id'] === '1' && $context['anime_id'] === $duplicateId && $context['plugin_id'] === self::PLUGIN_ID),
        );

        $this->newService(null, $logger)->backfill(new PluginId(self::PLUGIN_ID), $sync);

        $this->assertTrue($this->entityManager->isOpen());
        $this->entityManager->clear();
        $this->assertNull($this->requireAnime($duplicateId)->getCachedExternalId(new PluginId(self::PLUGIN_ID)));
    }

    public function testCachesOnlyOneOfTwoNewRecordsResolvingToTheSameId(): void
    {
        $first = new MovieAnime();
        $first->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching)->addSource('https://shikimori.one/animes/1');
        $this->entityManager->persist($first);

        $second = new MovieAnime();
        $second->setTitle('Cowboy Bebop (copy)')->setWatchStatus(WatchStatus::Watching)->addSource('https://shikimori.one/animes/1');
        $this->entityManager->persist($second);

        $this->entityManager->flush();
        $firstId = $this->requireId($first);
        $secondId = $this->requireId($second);

        $sync = $this->createStub(SyncInterface::class);
        $sync->method('resolveExternalId')->willReturn('1');

        $this->newService()->backfill(new PluginId(self::PLUGIN_ID), $sync);

        $this->assertTrue($this->entityManager->isOpen());
        $this->entityManager->clear();
        $pluginId = new PluginId(self::PLUGIN_ID);
        $this->assertSame('1', $this->requireAnime($firstId)->getCachedExternalId($pluginId));
        $this->assertNull($this->requireAnime($secondId)->getCachedExternalId($pluginId));
    }

    private function requireId(Anime $anime): int
    {
        return $anime->id ?? throw new \LogicException('Anime must have an id after persisting.');
    }

    private function requireAnime(int $id): Anime
    {
        return $this->entityManager->find(Anime::class, $id) ?? throw new \LogicException(\sprintf('Anime #%d must exist.', $id));
    }

    private function newService(?ProcessLivenessChecker $livenessChecker = null, ?LoggerInterface $logger = null): ExternalIdBackfillService
    {
        return new ExternalIdBackfillService(
            $this->entityManager,
            new AnimeRepository($this->entityManager),
            $this->newJobLockService($livenessChecker),
            $logger ?? new NullLogger(),
        );
    }

    private function newJobLockService(?ProcessLivenessChecker $livenessChecker = null): JobLockService
    {
        return new JobLockService(
            $this->queueConnection,
            $livenessChecker ?? $this->createStub(ProcessLivenessChecker::class),
            new MockClock(new \DateTimeImmutable('@1000')),
            30,
            3,
        );
    }
}
