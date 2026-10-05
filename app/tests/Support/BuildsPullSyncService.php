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

namespace App\Tests\Support;

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
use Doctrine\ORM\EntityManager;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Builds a real {@see PullSyncService} (a final class) over the test case's in-memory
 * {@see EntityManager}, for handler tests that drive it through a mocked SyncInterface.
 *
 * @property EntityManager $entityManager
 */
trait BuildsPullSyncService
{
    protected function newPullSyncService(SyncRegistry $syncRegistry): PullSyncService
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
