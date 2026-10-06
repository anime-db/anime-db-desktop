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

use App\Repository\DownloadRepository;
use App\Repository\StorageRepository;
use App\Repository\SyncReviewItemRepository;
use App\Repository\SyncTombstoneRepository;
use App\Service\AnimeDeleteService;
use App\Service\Download\DownloadFolderJail;
use App\Service\Download\DownloadIncomingChecker;
use App\Service\JobLock\JobLockService;
use App\Service\JobLock\ProcessLivenessChecker;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\SyncRegistry;
use App\Service\Qbittorrent\QbittorrentClient;
use App\Service\Sync\SourceRemovalPlanner;
use App\Service\Sync\SyncReviewService;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Builds a real {@see AnimeDeleteService} (a final class) over the test case's in-memory
 * {@see EntityManager}. The job lock store and the qBittorrent client are in-memory too: the
 * requests the client makes land in $requests as "METHOD url body" strings.
 *
 * @property EntityManager $entityManager
 */
trait BuildsAnimeDeleteService
{
    /** @var list<string> */
    private array $qbittorrentRequests = [];

    private function newJobLockService(): JobLockService
    {
        // The owner counts as alive, so a held lock is never taken over.
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

    /** @param list<string> $activeSyncPluginIds plugins with sync switched on */
    private function newSyncRegistryWithActive(array $activeSyncPluginIds): SyncRegistry
    {
        $syncs = [];
        $settings = [];
        foreach ($activeSyncPluginIds as $id) {
            $syncs[$id] = $this->createStub(\AnimeDb\PluginContracts\Sync\SyncInterface::class);
            $settings[$id] = ['features' => ['sync' => true]];
        }

        $path = sys_get_temp_dir().'/anime-delete-test-'.uniqid().'.json';
        file_put_contents($path, (string) json_encode($settings));

        return new SyncRegistry($syncs, new PluginsConfigStore($path));
    }

    /**
     * @param array<string, \AnimeDb\PluginContracts\Sync\SyncInterface> $syncs         plugin id => plugin
     * @param list<string>                                               $activeSyncIds ids with sync switched on
     */
    private function newSyncRegistryOf(array $syncs, array $activeSyncIds): SyncRegistry
    {
        $settings = [];
        foreach ($activeSyncIds as $id) {
            $settings[$id] = ['features' => ['sync' => true]];
        }

        $path = sys_get_temp_dir().'/anime-delete-test-'.uniqid().'.json';
        file_put_contents($path, (string) json_encode($settings));

        return new SyncRegistry($syncs, new PluginsConfigStore($path));
    }

    /** @param list<array<string, mixed>> $torrents what `torrents/info` returns */
    private function newQbittorrentClient(bool $failing = false, array $torrents = []): QbittorrentClient
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($failing, $torrents): MockResponse {
            $this->qbittorrentRequests[] = $method.' '.$url.' '.(string) ($options['body'] ?? '');
            $body = str_contains($url, '/torrents/info') ? (string) json_encode($torrents) : '';

            return new MockResponse($body, ['http_code' => $failing ? 500 : 200]);
        });

        return new QbittorrentClient($httpClient, 'http://qb.test');
    }

    private function newAnimeDeleteService(
        string $mediaDir,
        ?SyncRegistry $syncRegistry = null,
        ?JobLockService $jobLockService = null,
        ?QbittorrentClient $qbittorrent = null,
        ?LoggerInterface $logger = null,
        ?MessageBusInterface $bus = null,
    ): AnimeDeleteService {
        $syncRegistry ??= $this->newSyncRegistryWithActive([]);

        return new AnimeDeleteService(
            $this->entityManager,
            new DownloadRepository($this->entityManager),
            $syncRegistry,
            $jobLockService ?? $this->newJobLockService(),
            new SyncTombstoneRepository($this->entityManager),
            new SourceRemovalPlanner($syncRegistry),
            $bus ?? $this->createStub(MessageBusInterface::class),
            new SyncReviewService(new SyncReviewItemRepository($this->entityManager)),
            $qbittorrent ?? $this->newQbittorrentClient(),
            new DownloadIncomingChecker(new DownloadFolderJail(), new StorageRepository($this->entityManager)),
            $logger ?? new NullLogger(),
            $mediaDir,
        );
    }
}
