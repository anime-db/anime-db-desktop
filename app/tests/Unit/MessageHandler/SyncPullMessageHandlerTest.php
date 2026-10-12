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

namespace App\Tests\Unit\MessageHandler;

use AnimeDb\PluginContracts\Filler\PluginAnimeData;
use AnimeDb\PluginContracts\OAuth\ReauthRequiredException;
use AnimeDb\PluginContracts\Sync\SyncInterface;
use AnimeDb\PluginContracts\Sync\SyncItem;
use AnimeDb\PluginContracts\Sync\SyncRemovalInterface;
use AnimeDb\PluginContracts\Sync\SyncStatus;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\Enum\SyncReviewItemKind;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Entity\SyncReviewItem;
use App\Entity\ValueObject\PluginId;
use App\Message\SyncPullMessage;
use App\Message\SyncSeedMessage;
use App\MessageHandler\SyncPullMessageHandler;
use App\Repository\AnimeRepository;
use App\Repository\SyncReviewItemRepository;
use App\Repository\SyncTombstoneRepository;
use App\Service\JobLock\JobLockService;
use App\Service\JobLock\ProcessLivenessChecker;
use App\Service\Notification\Kind\SyncReviewNotificationKind;
use App\Service\Plugin\ExternalIdBackfillService;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\SyncRegistry;
use App\Service\Sync\SourceRemovalService;
use App\Service\Sync\SyncPullGate;
use App\Service\Sync\SyncReviewService;
use App\Tests\Support\BuildsPullSyncService;
use App\Tests\Support\RecordingNotificationCenter;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

final class SyncPullMessageHandlerTest extends TestCase
{
    use BuildsPullSyncService;

    private const ID = 'animedb-shikimori';
    private const NOW = '2026-01-01T12:00:00+00:00';

    private EntityManager $entityManager;
    private string $path;

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
        $this->entityManager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->path = sys_get_temp_dir().'/anime-sync-pull-test-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testPullsADuePluginAndRecordsTheLastPullTime(): void
    {
        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->once())->method('pull')->willReturn([]);

        $store = $this->store(['features' => ['sync' => true], 'syncSeeded' => true]);
        $this->handler($sync, $store)(new SyncPullMessage(self::ID));

        $settings = $store->getPluginSettings(new PluginId(self::ID));
        $this->assertSame(self::NOW, $settings['syncLastPullAt'] ?? null);
        $this->assertTrue($settings['syncSeeded']);
    }

    public function testAFailedPendingRemovalKeepsItsFlagAndDoesNotStopThePullThenTheNextPullRetriesIt(): void
    {
        $tombstones = new SyncTombstoneRepository($this->entityManager);
        $tombstones->record(self::ID, '99', new \DateTimeImmutable(), true);

        $attempts = 0;
        $pulls = 0;
        $sync = $this->createMock(SyncRemovalInterface::class);
        $sync->method('remove')->willReturnCallback(static function (string $externalId) use (&$attempts): void {
            if (++$attempts === 1) {
                throw new \RuntimeException('offline');
            }
        });
        $sync->method('pull')->willReturnCallback(static function () use (&$pulls): array {
            ++$pulls;

            return [];
        });

        $store = $this->store(['features' => ['sync' => true], 'syncSeeded' => true]);
        $this->handler($sync, $store)(new SyncPullMessage(self::ID));

        $this->assertSame(1, $pulls, 'The pull still runs after a failed catch-up.');
        $this->assertTrue($tombstones->isRemovalPending(self::ID, '99'));

        // The mark is fresh now; make the plugin due again for the next tick.
        $store->updatePluginSettings(new PluginId(self::ID), static function (array $settings): array {
            unset($settings['syncLastPullAt']);

            return $settings;
        });
        $this->handler($sync, $store)(new SyncPullMessage(self::ID));

        $this->assertSame(2, $attempts);
        $this->assertFalse($tombstones->exists(self::ID, '99'));
    }

    /** Issue #918: the backfill goes first, so the cache of external ids is complete before a removal is decided. */
    public function testBackfillRunsBeforeThePendingRemovalsAndThePull(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan)->addSource('https://shikimori.one/animes/1');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        (new SyncTombstoneRepository($this->entityManager))->record(self::ID, '99', new \DateTimeImmutable(), true);

        $events = [];
        $sync = $this->createMock(SyncRemovalInterface::class);
        $sync->method('remove')->willReturnCallback(static function (string $externalId) use (&$events): void {
            $events[] = 'remove:'.$externalId;
        });
        $sync->method('resolveExternalId')->willReturnCallback(static function () use (&$events): string {
            $events[] = 'resolve';

            return '1';
        });
        $sync->method('pull')->willReturnCallback(static function () use (&$events): array {
            $events[] = 'pull';

            return [];
        });

        $store = $this->store(['features' => ['sync' => true], 'syncSeeded' => true]);
        $this->handler($sync, $store)(new SyncPullMessage(self::ID));

        $this->assertSame(['resolve', 'remove:99', 'pull'], $events);
    }

    public function testReauthDuringTheCatchUpStopsItButThePullStillReportsItself(): void
    {
        $tombstones = new SyncTombstoneRepository($this->entityManager);
        $tombstones->record(self::ID, '1', new \DateTimeImmutable(), true);
        $tombstones->record(self::ID, '2', new \DateTimeImmutable(), true);

        $sync = $this->createMock(SyncRemovalInterface::class);
        $sync->expects($this->once())->method('remove')->willThrowException(new ReauthRequiredException('dead'));
        $sync->expects($this->once())->method('pull')->willThrowException(new ReauthRequiredException('dead'));

        $store = $this->store(['features' => ['sync' => true], 'syncSeeded' => true]);
        $this->handler($sync, $store)(new SyncPullMessage(self::ID));

        $this->assertSame(['1', '2'], $tombstones->findRemovalPending(new PluginId(self::ID)));
    }

    public function testDoesNotPullWhenTheMarkIsAlreadyFresh(): void
    {
        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->never())->method('pull');

        $mark = '2026-01-01T11:00:00+00:00';
        $store = $this->store(['features' => ['sync' => true], 'syncSeeded' => true, 'syncLastPullAt' => $mark]);
        $this->handler($sync, $store)(new SyncPullMessage(self::ID));

        $this->assertSame($mark, $store->getPluginSettings(new PluginId(self::ID))['syncLastPullAt']);
    }

    public function testNeedsReauthorizationIsLoggedAndChangesNeitherTheSeededFlagNorTheMark(): void
    {
        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->once())->method('pull')->willThrowException(new ReauthRequiredException('Refresh token is dead.'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info');

        $initial = ['features' => ['sync' => true], 'syncSeeded' => true];
        $store = $this->store($initial);
        $this->handler($sync, $store, $logger)(new SyncPullMessage(self::ID));

        $this->assertSame($initial, $store->getPluginSettings(new PluginId(self::ID)));
    }

    public function testSkipsAPluginThatIsNoLongerActive(): void
    {
        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->never())->method('pull');

        $store = $this->store(['features' => ['sync' => false], 'syncSeeded' => true]);
        $this->handler($sync, $store)(new SyncPullMessage(self::ID));

        $this->assertArrayNotHasKey('syncLastPullAt', $store->getPluginSettings(new PluginId(self::ID)));
    }

    public function testSkipsAPluginThatIsNotSeeded(): void
    {
        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->never())->method('pull');

        $store = $this->store(['features' => ['sync' => true], 'syncSeeded' => false]);
        $this->handler($sync, $store)(new SyncPullMessage(self::ID));

        $this->assertArrayNotHasKey('syncLastPullAt', $store->getPluginSettings(new PluginId(self::ID)));
    }

    public function testSkipsWithoutPullingOrRecordingWhenTheSyncLockIsHeld(): void
    {
        $locks = $this->jobLockService();
        $locks->acquire(SyncSeedMessage::jobKey(self::ID));

        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->never())->method('pull');

        $store = $this->store(['features' => ['sync' => true], 'syncSeeded' => true]);
        $this->handler($sync, $store, null, $locks)(new SyncPullMessage(self::ID));

        $this->assertArrayNotHasKey('syncLastPullAt', $store->getPluginSettings(new PluginId(self::ID)));
    }

    public function testHoldsTheLockDuringThePullAndReleasesItAfterwards(): void
    {
        $locks = $this->jobLockService();
        $held = null;
        $sync = $this->createMock(SyncInterface::class);
        $sync->method('pull')->willReturnCallback(static function () use ($locks, &$held): array {
            $held = $locks->isLocked(SyncSeedMessage::jobKey(self::ID));

            return [];
        });

        $store = $this->store(['features' => ['sync' => true], 'syncSeeded' => true]);
        $this->handler($sync, $store, null, $locks)(new SyncPullMessage(self::ID));

        $this->assertTrue($held);
        $this->assertFalse($locks->isLocked(SyncSeedMessage::jobKey(self::ID)));
    }

    public function testReleasesTheLockWhenThePullThrows(): void
    {
        $locks = $this->jobLockService();
        $sync = $this->createMock(SyncInterface::class);
        $sync->method('pull')->willThrowException(new \RuntimeException('boom'));

        $store = $this->store(['features' => ['sync' => true], 'syncSeeded' => true]);

        try {
            $this->handler($sync, $store, null, $locks)(new SyncPullMessage(self::ID));
            $this->fail('The exception must propagate.');
        } catch (\RuntimeException) {
        }

        $this->assertFalse($locks->isLocked(SyncSeedMessage::jobKey(self::ID)));
        $this->assertArrayNotHasKey('syncLastPullAt', $store->getPluginSettings(new PluginId(self::ID)));
    }

    /** Issue #867: `syncSeeded` is set at dispatch, so the pull must not duplicate a record the seed never backfilled. */
    public function testBackfillsExternalIdsSoTheStaleSeedDoesNotLetThePullDuplicateARecord(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan)->addSource('https://shikimori.one/animes/1');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $sync = $this->createMock(SyncInterface::class);
        $sync->method('resolveExternalId')->willReturn('1');
        $sync->method('findById')->willReturn(new PluginAnimeData(title: 'Cowboy Bebop'));
        $sync->expects($this->once())->method('pull')->willReturn([new SyncItem('1', SyncStatus::Watching, 'Cowboy Bebop', type: null)]);

        $store = $this->store(['features' => ['sync' => true], 'syncSeeded' => true]);
        $this->handler($sync, $store)(new SyncPullMessage(self::ID));

        $this->entityManager->clear();
        $all = $this->entityManager->getRepository(Anime::class)->findAll();
        $this->assertCount(1, $all);
        $this->assertSame('1', $all[0]->getCachedExternalId(new PluginId(self::ID)));
        $this->assertSame($anime->id, $all[0]->id);
    }

    /** @param array<string, mixed> $settings */
    private function store(array $settings): PluginsConfigStore
    {
        file_put_contents($this->path, (string) json_encode([self::ID => $settings]));

        return new PluginsConfigStore($this->path);
    }

    public function testPullThatRaisesReviewItemsRecordsOneSyncReviewNotificationAfterTheirCommit(): void
    {
        $sync = $this->createMock(SyncInterface::class);
        $sync->method('pull')->willReturnCallback(function (): array {
            $this->addReviewItems(2);

            return [];
        });

        $notifications = new RecordingNotificationCenter();
        $notifications->onRecord = fn () => $this->assertSame(3, (new SyncReviewService(new SyncReviewItemRepository($this->entityManager)))->countUnresolved(), 'record() runs after the items are committed');
        $this->addReviewItems(1);
        $store = $this->store(['features' => ['sync' => true], 'syncSeeded' => true]);
        $this->handler($sync, $store, null, null, $notifications)(new SyncPullMessage(self::ID));

        $this->assertCount(1, $notifications->drafts);
        $this->assertEquals(SyncReviewNotificationKind::draft(3), $notifications->drafts[0]);
    }

    public function testPullThatRaisesNoReviewItemsRecordsNoNotification(): void
    {
        $sync = $this->createMock(SyncInterface::class);
        $sync->method('pull')->willReturn([]);

        $notifications = new RecordingNotificationCenter();
        $this->addReviewItems(2);
        $store = $this->store(['features' => ['sync' => true], 'syncSeeded' => true]);
        $this->handler($sync, $store, null, null, $notifications)(new SyncPullMessage(self::ID));

        $this->assertSame([], $notifications->drafts);
    }

    public function testPullThatClosesAnItemWhileRaisingOneStillRecordsTheNotification(): void
    {
        $this->addReviewItems(1);
        $sync = $this->createMock(SyncInterface::class);
        $sync->method('pull')->willReturnCallback(function (): array {
            $this->entityManager->createQuery('UPDATE '.SyncReviewItem::class.' item SET item.resolvedAt = :now')->setParameter('now', new \DateTimeImmutable())->execute();
            $this->addReviewItems(1);

            return [];
        });

        $notifications = new RecordingNotificationCenter();
        $store = $this->store(['features' => ['sync' => true], 'syncSeeded' => true]);
        $this->handler($sync, $store, null, null, $notifications)(new SyncPullMessage(self::ID));

        $this->assertEquals([SyncReviewNotificationKind::draft(1)], $notifications->drafts);
    }

    private function addReviewItems(int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $this->entityManager->persist(new SyncReviewItem(SyncReviewItemKind::PotentialDuplicate, ['anime_ids' => [1, 2]]));
        }
        $this->entityManager->flush();
    }

    private function handler(SyncInterface $sync, PluginsConfigStore $store, ?LoggerInterface $logger = null, ?JobLockService $locks = null, ?RecordingNotificationCenter $notifications = null): SyncPullMessageHandler
    {
        $registry = new SyncRegistry([self::ID => $sync], $store);

        return new SyncPullMessageHandler(
            $registry,
            new SyncPullGate($store, new MockClock(new \DateTimeImmutable(self::NOW))),
            new ExternalIdBackfillService($this->entityManager, new AnimeRepository($this->entityManager), $this->jobLockService(), new NullLogger()),
            new SourceRemovalService(new SyncTombstoneRepository($this->entityManager), new AnimeRepository($this->entityManager), new NullLogger()),
            $this->newPullSyncService($registry),
            $locks ?? $this->jobLockService(),
            new SyncReviewService(new SyncReviewItemRepository($this->entityManager)),
            $notifications ?? new RecordingNotificationCenter(),
            $logger ?? new NullLogger(),
        );
    }

    private function jobLockService(): JobLockService
    {
        $livenessChecker = $this->createStub(ProcessLivenessChecker::class);
        $livenessChecker->method('getStartedAt')->willReturn(new \DateTimeImmutable('@0'));

        return new JobLockService(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
            $livenessChecker,
            new MockClock(new \DateTimeImmutable('@1000')),
            30,
            3,
        );
    }
}
