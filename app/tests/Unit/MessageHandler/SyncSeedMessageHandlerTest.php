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

use AnimeDb\PluginContracts\OAuth\ReauthRequiredException;
use AnimeDb\PluginContracts\Sync\SyncInterface;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\ValueObject\PluginId;
use App\Message\SyncSeedMessage;
use App\MessageHandler\SyncSeedMessageHandler;
use App\Repository\AnimeRepository;
use App\Repository\AnimeSyncStateRepository;
use App\Repository\PendingSyncPushRepository;
use App\Repository\StudioRepository;
use App\Repository\SyncReviewItemRepository;
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
        $handler = new SyncSeedMessageHandler($syncRegistry, $this->newPullSyncService($syncRegistry), $pluginsConfigStore, new NullLogger());

        $handler(new SyncSeedMessage('animedb-shikimori'));
    }

    public function testInvokeSkipsAPluginThatIsNoLongerActiveByTheTimeTheMessageIsProcessed(): void
    {
        [$syncRegistry, $pluginsConfigStore] = $this->newSyncRegistry([]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info');

        $handler = new SyncSeedMessageHandler($syncRegistry, $this->newPullSyncService($syncRegistry), $pluginsConfigStore, $logger);

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
        $handler = new SyncSeedMessageHandler($syncRegistry, $this->newPullSyncService($syncRegistry), $pluginsConfigStore, new NullLogger());

        $handler(new SyncSeedMessage('animedb-shikimori'));

        $settings = $pluginsConfigStore->getPluginSettings(new PluginId('animedb-shikimori'));
        $this->assertFalse($settings['syncSeeded'] ?? null);
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
