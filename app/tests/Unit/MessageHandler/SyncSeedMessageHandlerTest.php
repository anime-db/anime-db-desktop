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
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Entity\ValueObject\PluginId;
use App\Message\SyncSeedMessage;
use App\MessageHandler\SyncSeedMessageHandler;
use App\Repository\AnimeRepository;
use App\Repository\SyncTombstoneRepository;
use App\Service\JobLock\JobLockService;
use App\Service\JobLock\ProcessLivenessChecker;
use App\Service\Plugin\ExternalIdBackfillService;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\PullSyncService;
use App\Service\Plugin\SyncRegistry;
use App\Service\Sync\SourceRemovalService;
use App\Service\Sync\SyncPullGate;
use App\Tests\Support\BuildsPullSyncService;
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
 * The handler itself is a thin dispatch onto {@see PullSyncService::pull()} (issue #381) — these
 * tests verify it resolves the right plugin from {@see SyncRegistry} and actually reaches that
 * plugin's {@see SyncInterface::pull()}, that a plugin no longer active by the time this async
 * message is processed is skipped rather than erroring (the same self-healing stance
 * {@see \App\MessageHandler\PushSyncMessageHandler} takes for a deleted Anime), and that a pull
 * which stopped short on {@see ReauthRequiredException} resets the `syncSeeded` flag instead of
 * leaving connect-seed permanently marked "done" for a plugin it never actually seeded (issue
 * #381 review).
 */
final class SyncSeedMessageHandlerTest extends TestCase
{
    use BuildsPullSyncService;

    private EntityManager $entityManager;

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
    }

    public function testInvokeResolvesThePluginAndCallsPullSyncServiceForIt(): void
    {
        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->once())->method('pull')->willReturn([]);

        [$syncRegistry, $pluginsConfigStore] = $this->newSyncRegistry(['animedb-shikimori' => $sync]);
        $handler = new SyncSeedMessageHandler($syncRegistry, $this->newBackfillService(), $this->newSourceRemoval(), $this->newPullSyncService($syncRegistry), $pluginsConfigStore, $this->jobLockService(), $this->pullGate($pluginsConfigStore), new NullLogger());

        $handler(new SyncSeedMessage('animedb-shikimori'));
    }

    public function testInvokeHoldsTheSeedLockDuringThePullAndReleasesItAfterwards(): void
    {
        $jobLockService = $this->jobLockService();
        $heldDuringPull = null;

        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->once())->method('pull')->willReturnCallback(static function () use ($jobLockService, &$heldDuringPull): array {
            $heldDuringPull = $jobLockService->isLocked(SyncSeedMessage::jobKey('animedb-shikimori'));

            return [];
        });

        [$syncRegistry, $pluginsConfigStore] = $this->newSyncRegistry(['animedb-shikimori' => $sync]);
        $handler = new SyncSeedMessageHandler($syncRegistry, $this->newBackfillService(), $this->newSourceRemoval(), $this->newPullSyncService($syncRegistry), $pluginsConfigStore, $jobLockService, $this->pullGate($pluginsConfigStore), new NullLogger());

        $handler(new SyncSeedMessage('animedb-shikimori'));

        $this->assertTrue($heldDuringPull);
        $this->assertFalse($jobLockService->isLocked(SyncSeedMessage::jobKey('animedb-shikimori')));
    }

    /** Issue #870: a successful seed counts as a pull, so the periodic one is not due right after it. */
    public function testInvokeRecordsTheLastPullTimeAfterASuccessfulSeed(): void
    {
        $sync = $this->createMock(SyncInterface::class);
        $sync->method('pull')->willReturn([]);

        [$syncRegistry, $pluginsConfigStore] = $this->newSyncRegistry(['animedb-shikimori' => $sync]);
        $this->newHandler($syncRegistry, $pluginsConfigStore)(new SyncSeedMessage('animedb-shikimori'));

        $settings = $pluginsConfigStore->getPluginSettings(new PluginId('animedb-shikimori'));
        $this->assertSame('2026-01-01T12:00:00+00:00', $settings['syncLastPullAt'] ?? null);
    }

    public function testInvokeSkipsWhenAnotherSeedForThePluginHoldsTheLock(): void
    {
        $jobLockService = $this->jobLockService();
        $jobLockService->acquire(SyncSeedMessage::jobKey('animedb-shikimori'));

        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->never())->method('pull');

        [$syncRegistry, $pluginsConfigStore] = $this->newSyncRegistry(['animedb-shikimori' => $sync]);
        $handler = new SyncSeedMessageHandler($syncRegistry, $this->newBackfillService(), $this->newSourceRemoval(), $this->newPullSyncService($syncRegistry), $pluginsConfigStore, $jobLockService, $this->pullGate($pluginsConfigStore), new NullLogger());

        $handler(new SyncSeedMessage('animedb-shikimori'));
    }

    public function testInvokeSkipsAPluginThatIsNoLongerActiveByTheTimeTheMessageIsProcessed(): void
    {
        [$syncRegistry, $pluginsConfigStore] = $this->newSyncRegistry([]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info');

        $handler = new SyncSeedMessageHandler($syncRegistry, $this->newBackfillService(), $this->newSourceRemoval(), $this->newPullSyncService($syncRegistry), $pluginsConfigStore, $this->jobLockService(), $this->pullGate($pluginsConfigStore), $logger);

        $handler(new SyncSeedMessage('animedb-shikimori'));
    }

    /**
     * Issue #381 review: `features.sync` alone does not prove the plugin's OAuth is actually
     * complete, so a pull that stops short on a dead/missing OAuth session must not leave
     * `syncSeeded` permanently `true` — otherwise connect-seed never gets a second chance once
     * the user actually finishes OAuth.
     */
    public function testInvokeResetsTheSeededFlagWhenThePullStopsShortOnReauthRequired(): void
    {
        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->once())->method('pull')->willThrowException(new ReauthRequiredException('Refresh token is dead.'));

        [$syncRegistry, $pluginsConfigStore] = $this->newSyncRegistry(['animedb-shikimori' => $sync], ['syncSeeded' => true]);
        $handler = new SyncSeedMessageHandler($syncRegistry, $this->newBackfillService(), $this->newSourceRemoval(), $this->newPullSyncService($syncRegistry), $pluginsConfigStore, $this->jobLockService(), $this->pullGate($pluginsConfigStore), new NullLogger());

        $handler(new SyncSeedMessage('animedb-shikimori'));

        $settings = $pluginsConfigStore->getPluginSettings(new PluginId('animedb-shikimori'));
        $this->assertFalse($settings['syncSeeded'] ?? null);
    }

    /** Issue #867 (1): a local record with a source URL and no cached id is matched, not duplicated. */
    public function testInvokeBackfillsExternalIdsSoThePullMatchesAnExistingRecordInsteadOfDuplicatingIt(): void
    {
        $anime = $this->persistAnimeWithSource('https://shikimori.one/animes/1', WatchStatus::Plan);

        $sync = $this->createMock(SyncInterface::class);
        $sync->method('resolveExternalId')->willReturn('1');
        // A pull that misses the record by external id would create a second row from this data.
        $sync->method('findById')->willReturn(new PluginAnimeData(title: 'Cowboy Bebop'));
        $sync->expects($this->once())->method('pull')->willReturn([new SyncItem('1', SyncStatus::Watching, 'Cowboy Bebop')]);

        [$syncRegistry, $pluginsConfigStore] = $this->newSyncRegistry(['animedb-shikimori' => $sync]);
        $this->newHandler($syncRegistry, $pluginsConfigStore)(new SyncSeedMessage('animedb-shikimori'));

        $this->entityManager->clear();
        $all = $this->entityManager->getRepository(Anime::class)->findAll();
        $this->assertCount(1, $all);
        $this->assertSame('1', $all[0]->getCachedExternalId(new PluginId('animedb-shikimori')));
        $this->assertSame($anime->id, $all[0]->id);
        $this->assertSame(WatchStatus::Watching, $all[0]->getWatchStatus());
    }

    /** Issue #867 (2): the ordering is enforced inside the handler, not by dispatch order. */
    public function testInvokeFinishesTheBackfillBeforeTheFirstPullCall(): void
    {
        $this->persistAnimeWithSource('https://shikimori.one/animes/1');

        $events = [];
        $sync = $this->createMock(SyncInterface::class);
        $sync->method('resolveExternalId')->willReturnCallback(static function () use (&$events): string {
            $events[] = 'resolve';

            return '1';
        });
        $sync->method('pull')->willReturnCallback(static function () use (&$events): array {
            $events[] = 'pull';

            return [];
        });

        [$syncRegistry, $pluginsConfigStore] = $this->newSyncRegistry(['animedb-shikimori' => $sync]);
        $this->newHandler($syncRegistry, $pluginsConfigStore)(new SyncSeedMessage('animedb-shikimori'));

        $this->assertSame(['resolve', 'pull'], $events);
    }

    /** Issue #867 (3): an inactive plugin gets neither the backfill nor the pull. */
    public function testInvokeRunsNeitherBackfillNorPullForAnInactivePlugin(): void
    {
        $this->persistAnimeWithSource('https://shikimori.one/animes/1');

        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->never())->method('resolveExternalId');
        $sync->expects($this->never())->method('pull');

        // Registered, but `features.sync` is off, so SyncRegistry::findByPluginId() returns null.
        $path = sys_get_temp_dir().'/anime-sync-seed-test-'.uniqid().'.json';
        file_put_contents($path, (string) json_encode(['animedb-shikimori' => ['features' => ['sync' => false]]]));
        $pluginsConfigStore = new PluginsConfigStore($path);
        $syncRegistry = new SyncRegistry(['animedb-shikimori' => $sync], $pluginsConfigStore);

        $this->newHandler($syncRegistry, $pluginsConfigStore)(new SyncSeedMessage('animedb-shikimori'));
    }

    /** Issue #867 (4): one record failing to resolve is logged and skipped; the pull still runs. */
    public function testInvokeLogsAndSkipsARecordWhoseResolveExternalIdThrowsThenStillPulls(): void
    {
        $this->persistAnimeWithSource('https://shikimori.one/animes/1');

        $sync = $this->createMock(SyncInterface::class);
        $sync->method('resolveExternalId')->willThrowException(new \RuntimeException('boom'));
        $sync->expects($this->once())->method('pull')->willReturn([]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        [$syncRegistry, $pluginsConfigStore] = $this->newSyncRegistry(['animedb-shikimori' => $sync]);
        $this->newHandler($syncRegistry, $pluginsConfigStore, $logger)(new SyncSeedMessage('animedb-shikimori'));
    }

    private function persistAnimeWithSource(string $url, WatchStatus $status = WatchStatus::Watching): Anime
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus($status)->addSource($url);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    /** Issue #918: the pending removals go first, or the backfill could link an id to another entry whose list item they would then delete. */
    public function testPendingRemovalsRunBeforeTheBackfillAndThePull(): void
    {
        $this->persistAnimeWithSource('https://shikimori.one/animes/1');
        (new SyncTombstoneRepository($this->entityManager))->record('animedb-shikimori', '99', new \DateTimeImmutable(), true);

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

        [$syncRegistry, $pluginsConfigStore] = $this->newSyncRegistry(['animedb-shikimori' => $sync]);
        $this->newHandler($syncRegistry, $pluginsConfigStore)(new SyncSeedMessage('animedb-shikimori'));

        $this->assertSame(['remove:99', 'resolve', 'pull'], $events);
        $this->assertFalse((new SyncTombstoneRepository($this->entityManager))->exists('animedb-shikimori', '99'));
    }

    private function newHandler(SyncRegistry $syncRegistry, PluginsConfigStore $pluginsConfigStore, ?LoggerInterface $backfillLogger = null): SyncSeedMessageHandler
    {
        return new SyncSeedMessageHandler(
            $syncRegistry,
            $this->newBackfillService($backfillLogger),
            $this->newSourceRemoval(),
            $this->newPullSyncService($syncRegistry),
            $pluginsConfigStore,
            $this->jobLockService(),
            $this->pullGate($pluginsConfigStore),
            new NullLogger(),
        );
    }

    private function newSourceRemoval(): SourceRemovalService
    {
        return new SourceRemovalService(new SyncTombstoneRepository($this->entityManager), new AnimeRepository($this->entityManager), new NullLogger());
    }

    private function pullGate(PluginsConfigStore $pluginsConfigStore): SyncPullGate
    {
        return new SyncPullGate($pluginsConfigStore, new MockClock(new \DateTimeImmutable('2026-01-01T12:00:00+00:00')));
    }

    private function jobLockService(): JobLockService
    {
        // The owner counts as alive, so a lock another seed holds is not taken over.
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

    private function newBackfillService(?LoggerInterface $logger = null): ExternalIdBackfillService
    {
        return new ExternalIdBackfillService(
            $this->entityManager,
            new AnimeRepository($this->entityManager),
            new JobLockService(
                DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
                $this->createStub(ProcessLivenessChecker::class),
                new MockClock(new \DateTimeImmutable('@1000')),
                30,
                3,
            ),
            $logger ?? new NullLogger(),
        );
    }

    /**
     * @param array<string, SyncInterface> $syncs
     * @param array<string, mixed>         $extraSettings merged into every listed plugin's entry,
     *                                                    e.g. a pre-existing `syncSeeded` flag
     *
     * @return array{0: SyncRegistry, 1: PluginsConfigStore}
     */
    private function newSyncRegistry(array $syncs, array $extraSettings = []): array
    {
        $settings = [];
        foreach (array_keys($syncs) as $id) {
            $settings[$id] = ['features' => ['sync' => true], ...$extraSettings];
        }

        $path = sys_get_temp_dir().'/anime-sync-seed-test-'.uniqid().'.json';
        file_put_contents($path, (string) json_encode($settings));

        $pluginsConfigStore = new PluginsConfigStore($path);

        return [new SyncRegistry($syncs, $pluginsConfigStore), $pluginsConfigStore];
    }
}
