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
use App\Repository\AnimeSyncStateRepository;
use App\Repository\PendingSyncPushRepository;
use App\Repository\StudioRepository;
use App\Repository\SyncReviewItemRepository;
use App\Service\JobLock\JobLockService;
use App\Service\JobLock\ProcessLivenessChecker;
use App\Service\Plugin\ExternalIdBackfillService;
use App\Service\Plugin\Filler\BulkFillerService;
use App\Service\Plugin\Filler\CachedFillerLookup;
use App\Service\Plugin\Filler\PluginAnimeDataMerger;
use App\Service\Plugin\Filler\PluginMediaDownloaderInterface;
use App\Service\Plugin\FillerRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\PullSyncService;
use App\Service\Plugin\SyncRegistry;
use App\Service\Search\AnimeSearchResolver;
use App\Service\Sync\CrossVendorDuplicateDetector;
use App\Service\Sync\DeletedFromSourceDetector;
use App\Service\Sync\SyncConvergenceService;
use App\Service\Sync\SyncReconciler;
use App\Service\Sync\SyncReviewService;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;

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
        $handler = new SyncSeedMessageHandler($syncRegistry, $this->newBackfillService(), $this->newPullSyncService($syncRegistry), $pluginsConfigStore, new NullLogger());

        $handler(new SyncSeedMessage('animedb-shikimori'));
    }

    public function testInvokeSkipsAPluginThatIsNoLongerActiveByTheTimeTheMessageIsProcessed(): void
    {
        [$syncRegistry, $pluginsConfigStore] = $this->newSyncRegistry([]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info');

        $handler = new SyncSeedMessageHandler($syncRegistry, $this->newBackfillService(), $this->newPullSyncService($syncRegistry), $pluginsConfigStore, $logger);

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
        $handler = new SyncSeedMessageHandler($syncRegistry, $this->newBackfillService(), $this->newPullSyncService($syncRegistry), $pluginsConfigStore, new NullLogger());

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

    private function newHandler(SyncRegistry $syncRegistry, PluginsConfigStore $pluginsConfigStore, ?LoggerInterface $backfillLogger = null): SyncSeedMessageHandler
    {
        return new SyncSeedMessageHandler(
            $syncRegistry,
            $this->newBackfillService($backfillLogger),
            $this->newPullSyncService($syncRegistry),
            $pluginsConfigStore,
            new NullLogger(),
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

    private function newPullSyncService(SyncRegistry $syncRegistry): PullSyncService
    {
        $bulkFillerService = new BulkFillerService(
            // Never consulted here — the test's SyncInterface stub yields no new items.
            new FillerRegistry([], new PluginsConfigStore(sys_get_temp_dir().'/anime-sync-seed-filler-'.uniqid().'.json')),
            new PluginAnimeDataMerger(
                new StudioRepository($this->entityManager),
                $this->entityManager,
                $this->createStub(PluginMediaDownloaderInterface::class),
                new NullLogger(),
            ),
            $this->entityManager,
            new NullLogger(),
            $this->createMock(MessageBusInterface::class),
            new AnimeRepository($this->entityManager),
            new CachedFillerLookup(new ArrayAdapter()),
        );

        $duplicateDetector = new CrossVendorDuplicateDetector(
            $this->createStub(AnimeSearchResolver::class),
            new SyncReviewService(new SyncReviewItemRepository($this->entityManager)),
            new NullLogger(),
        );

        $animeSyncStateRepository = new AnimeSyncStateRepository($this->entityManager);

        $deletionDetector = new DeletedFromSourceDetector(
            $syncRegistry,
            new SyncReviewService(new SyncReviewItemRepository($this->entityManager)),
            $animeSyncStateRepository,
        );

        $convergenceService = new SyncConvergenceService(
            new SyncReconciler(),
            $animeSyncStateRepository,
            new PendingSyncPushRepository($this->entityManager),
            $syncRegistry,
            new SyncReviewService(new SyncReviewItemRepository($this->entityManager)),
            new NullLogger(),
        );

        return new PullSyncService(
            $this->entityManager,
            new AnimeRepository($this->entityManager),
            $bulkFillerService,
            $duplicateDetector,
            $deletionDetector,
            $convergenceService,
            new NullLogger(),
        );
    }
}
