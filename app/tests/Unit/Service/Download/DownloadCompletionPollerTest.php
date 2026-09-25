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

namespace App\Tests\Unit\Service\Download;

use AnimeDb\PluginContracts\Catalog\AnimeFilesChangedEvent;
use AnimeDb\PluginContracts\Catalog\FilesChangeReason;
use AnimeDb\PluginContracts\Download\DownloadCompletedEvent;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Download;
use App\Entity\Enum\DownloadStatus;
use App\Entity\Enum\WatchStatus;
use App\Entity\TvAnime;
use App\Repository\DownloadRepository;
use App\Repository\StorageRepository;
use App\Service\AppConfigStore;
use App\Service\AppSettingsProvider;
use App\Service\Download\AnimeDownloadLinker;
use App\Service\Download\DownloadCompletionPoller;
use App\Service\Download\DownloadFolderJail;
use App\Service\Download\FreeSpaceChecker;
use App\Service\Download\FreeSpaceProvider;
use App\Service\Download\NativeFreeSpaceProvider;
use App\Service\Qbittorrent\QbittorrentClient;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class DownloadCompletionPollerTest extends TestCase
{
    private const string BASE_URL = 'http://127.0.0.1:18080';
    private const string ROOT = 'C:\\Users\\bob\\Downloads';
    private const string HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private EntityManager $entityManager;
    private DownloadRepository $downloads;
    private string $configPath;

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

        $this->downloads = new DownloadRepository($this->entityManager);

        $this->configPath = sys_get_temp_dir().'/anime-download-poller-test-'.uniqid().'.json';
        file_put_contents($this->configPath, json_encode(['downloadsRoot' => self::ROOT]));
    }

    protected function tearDown(): void
    {
        foreach ([$this->configPath, $this->configPath.'.tmp', $this->configPath.'.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function persistAnime(): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle('Test')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    /**
     * A completed download dispatches both AnimeFilesChangedEvent and DownloadCompletedEvent
     * (issue #703/#684) — capturing every dispatch() call in order, rather than asserting on a
     * single expected event class, is what lets one test assert on both.
     *
     * @param list<AnimeFilesChangedEvent|DownloadCompletedEvent> $dispatched
     */
    private function dispatcherCapturingEvents(int $times, array &$dispatched): EventDispatcherInterface
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->exactly($times))
            ->method('dispatch')
            ->willReturnCallback(function (AnimeFilesChangedEvent|DownloadCompletedEvent $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            });

        return $eventDispatcher;
    }

    /**
     * @param array<int, array<string, mixed>> $torrentsInfoResponse
     */
    private function makePoller(
        array $torrentsInfoResponse,
        EventDispatcherInterface $eventDispatcher,
        ?FreeSpaceProvider $freeSpaceProvider = null,
    ): DownloadCompletionPoller {
        $httpClient = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            json_encode($torrentsInfoResponse, \JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        ));

        $jail = new DownloadFolderJail(new AppSettingsProvider(new AppConfigStore($this->configPath)));
        $linker = new AnimeDownloadLinker(new StorageRepository($this->entityManager), $this->entityManager, $jail);

        return new DownloadCompletionPoller(
            new QbittorrentClient($httpClient, self::BASE_URL),
            $this->downloads,
            $linker,
            $eventDispatcher,
            $this->entityManager,
            // The real NativeFreeSpaceProvider reports "unknown" (free-open) for self::ROOT on
            // this Linux test runner — only tests about the free-space check itself override this.
            new FreeSpaceChecker($jail, $freeSpaceProvider ?? new NativeFreeSpaceProvider()),
            new NullLogger(),
        );
    }

    public function testPollLinksFolderAndDispatchesEventForAFinishedTorrent(): void
    {
        $anime = $this->persistAnime();
        $this->downloads->save(new Download(self::HASH, $anime));

        /** @var list<AnimeFilesChangedEvent|DownloadCompletedEvent> $dispatched */
        $dispatched = [];
        $eventDispatcher = $this->dispatcherCapturingEvents(2, $dispatched);

        $poller = $this->makePoller([[
            'hash' => self::HASH,
            'progress' => 1,
            'state' => 'uploading',
            'content_path' => self::ROOT.'\\finished-release',
        ]], $eventDispatcher);

        $poller->poll();

        $stored = $this->downloads->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertNotNull($stored);
        $this->assertTrue($stored->isCompleted());
        $this->assertNotNull($anime->getStorage());
        $this->assertSame(self::ROOT, $anime->getStorage()->getPath());
        $this->assertSame('finished-release', $anime->getStoragePath());

        $this->assertCount(2, $dispatched);
        $filesChanged = $dispatched[0];
        if (!$filesChanged instanceof AnimeFilesChangedEvent) {
            $this->fail('Expected the first dispatched event to be an AnimeFilesChangedEvent.');
        }
        $this->assertSame($anime->id, $filesChanged->anime->value);
        $this->assertSame(FilesChangeReason::DownloadFinished, $filesChanged->reason);

        $downloadCompleted = $dispatched[1];
        if (!$downloadCompleted instanceof DownloadCompletedEvent) {
            $this->fail('Expected the second dispatched event to be a DownloadCompletedEvent.');
        }
        $this->assertSame($anime->id, $downloadCompleted->anime->value);
        $this->assertSame(self::HASH, $downloadCompleted->task->value);
    }

    public function testPollDoesNotDispatchTwiceAcrossTwoRuns(): void
    {
        $anime = $this->persistAnime();
        $this->downloads->save(new Download(self::HASH, $anime));

        $dispatched = [];
        // Two events (AnimeFilesChangedEvent + DownloadCompletedEvent) on the first poll(), none
        // on the second — a completed pair must not dispatch again.
        $eventDispatcher = $this->dispatcherCapturingEvents(2, $dispatched);

        $poller = $this->makePoller([[
            'hash' => self::HASH,
            'progress' => 1,
            'state' => 'uploading',
            'content_path' => self::ROOT.'\\finished-release',
        ]], $eventDispatcher);

        $poller->poll();
        $poller->poll();
    }

    /**
     * @return array<string, array{0: string, 1: float, 2: string}>
     */
    public static function incompleteTorrentProvider(): array
    {
        return [
            'progress not yet 1' => ['not-done-progress', 0.99, 'downloading'],
            'still moving' => ['not-done-moving', 1.0, 'moving'],
            'still checking resume data' => ['not-done-checking', 1.0, 'checkingResumeData'],
            'errored out' => ['not-done-error', 1.0, 'error'],
            'missing files' => ['not-done-missing', 1.0, 'missingFiles'],
        ];
    }

    #[DataProvider('incompleteTorrentProvider')]
    public function testPollDoesNotCompleteATorrentThatIsNotActuallyDone(string $case, float $progress, string $state): void
    {
        $anime = $this->persistAnime();
        $this->downloads->save(new Download(self::HASH, $anime));

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        $poller = $this->makePoller([[
            'hash' => self::HASH,
            'progress' => $progress,
            'state' => $state,
            'content_path' => self::ROOT.'\\'.$case,
        ]], $eventDispatcher);

        $poller->poll();

        $stored = $this->downloads->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertNotNull($stored);
        $this->assertFalse($stored->isCompleted());
    }

    public function testAContentPathOutsideTheJailDoesNotWedgeOtherPendingDownloads(): void
    {
        $wedgedHash = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        $wedged = $this->persistAnime();
        $ok = $this->persistAnime();
        $this->downloads->save(new Download($wedgedHash, $wedged));
        $this->downloads->save(new Download(self::HASH, $ok));

        /** @var list<AnimeFilesChangedEvent|DownloadCompletedEvent> $dispatched */
        $dispatched = [];
        // Only the OK pair completes and dispatches (AnimeFilesChangedEvent + DownloadCompletedEvent);
        // the wedged pair's DownloadPathOutsideJailException must not dispatch anything for it.
        $eventDispatcher = $this->dispatcherCapturingEvents(2, $dispatched);

        // A real qBittorrent WebUI filters /api/v2/torrents/info by the "hashes" query param sent
        // per infoHash — this mock has to do the same instead of returning a fixed body for every
        // request, otherwise a poll() that queries two distinct pending infoHashes could not be
        // told apart here.
        $torrentsByHash = [
            $wedgedHash => [
                'hash' => $wedgedHash,
                'progress' => 1,
                'state' => 'uploading',
                'content_path' => 'D:\\elsewhere\\moved-away',
            ],
            self::HASH => [
                'hash' => self::HASH,
                'progress' => 1,
                'state' => 'uploading',
                'content_path' => self::ROOT.'\\finished-release',
            ],
        ];
        $httpClient = new MockHttpClient(function (string $method, string $url) use ($torrentsByHash): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $requestedHash = $query['hashes'] ?? null;
            $torrent = \is_string($requestedHash) ? $torrentsByHash[$requestedHash] ?? null : null;

            return new MockResponse(
                json_encode($torrent === null ? [] : [$torrent], \JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            );
        });

        $jail = new DownloadFolderJail(new AppSettingsProvider(new AppConfigStore($this->configPath)));
        $linker = new AnimeDownloadLinker(new StorageRepository($this->entityManager), $this->entityManager, $jail);
        $poller = new DownloadCompletionPoller(
            new QbittorrentClient($httpClient, self::BASE_URL),
            $this->downloads,
            $linker,
            $eventDispatcher,
            $this->entityManager,
            new FreeSpaceChecker($jail, new NativeFreeSpaceProvider()),
            new NullLogger(),
        );

        $poller->poll();

        $wedgedRow = $this->downloads->findByInfoHashAndAnime($wedgedHash, (int) $wedged->id);
        $this->assertNotNull($wedgedRow);
        $this->assertFalse($wedgedRow->isCompleted());

        $okRow = $this->downloads->findByInfoHashAndAnime(self::HASH, (int) $ok->id);
        $this->assertNotNull($okRow);
        $this->assertTrue($okRow->isCompleted());

        $this->assertCount(2, $dispatched);
        foreach ($dispatched as $event) {
            $this->assertSame($ok->id, $event->anime->value);
        }

        // The wedged pair keeps being reported as pending and does not permanently jam the poller.
        $poller->poll();
        $stillWedged = $this->downloads->findByInfoHashAndAnime($wedgedHash, (int) $wedged->id);
        $this->assertNotNull($stillWedged);
        $this->assertFalse($stillWedged->isCompleted());
    }

    public function testPollPausesAndMarksFailedWhenAMagnetsKnownSizeDoesNotFitFreeSpace(): void
    {
        $anime = $this->persistAnime();
        $this->downloads->save(new Download(self::HASH, $anime));

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        $freeSpaceProvider = $this->createStub(FreeSpaceProvider::class);
        $freeSpaceProvider->method('getFreeBytes')->willReturn(100_000_000);

        $pauseCalls = 0;
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$pauseCalls): MockResponse {
            if ($method === 'POST' && str_contains($url, '/api/v2/torrents/pause')) {
                ++$pauseCalls;
            }

            return new MockResponse(
                json_encode([[
                    // Not yet Completed — a magnet whose metadata just arrived, still downloading.
                    'hash' => self::HASH,
                    'progress' => 0.4,
                    'state' => 'metaDL',
                    'size' => 500_000_000,
                ]], \JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            );
        });

        $jail = new DownloadFolderJail(new AppSettingsProvider(new AppConfigStore($this->configPath)));
        $linker = new AnimeDownloadLinker(new StorageRepository($this->entityManager), $this->entityManager, $jail);
        $poller = new DownloadCompletionPoller(
            new QbittorrentClient($httpClient, self::BASE_URL),
            $this->downloads,
            $linker,
            $eventDispatcher,
            $this->entityManager,
            new FreeSpaceChecker($jail, $freeSpaceProvider),
            new NullLogger(),
        );

        $poller->poll();

        $this->assertSame(1, $pauseCalls);
        $stored = $this->downloads->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertNotNull($stored);
        $this->assertTrue($stored->isFailed());
        $this->assertSame(DownloadStatus::Failed, $stored->getStatus());

        // Once Failed, the row drops out of findDistinctPendingInfoHashes() — a second poll must
        // not pause (or log) it again.
        $poller->poll();
        $this->assertSame(1, $pauseCalls);
    }

    public function testPollDoesNotPauseWhenAMagnetsKnownSizeFitsFreeSpace(): void
    {
        $anime = $this->persistAnime();
        $this->downloads->save(new Download(self::HASH, $anime));

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        $freeSpaceProvider = $this->createStub(FreeSpaceProvider::class);
        $freeSpaceProvider->method('getFreeBytes')->willReturn(2_000_000_000);

        $poller = $this->makePoller([[
            'hash' => self::HASH,
            'progress' => 0.4,
            'state' => 'metaDL',
            'size' => 500_000_000,
        ]], $eventDispatcher, $freeSpaceProvider);

        $poller->poll();

        $stored = $this->downloads->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertNotNull($stored);
        $this->assertSame(DownloadStatus::Pending, $stored->getStatus());
    }

    public function testPollDoesNotFalselyFailAHealthyMagnetAsFreeSpaceShrinksWhileItDownloads(): void
    {
        $anime = $this->persistAnime();
        $this->downloads->save(new Download(self::HASH, $anime));

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        // Free space shrinks by roughly what this same torrent has already written to disk: 100 GB
        // free before anything is written, down to 58 GB free once ~42 GB (70%) of a 60 GB torrent
        // has landed. Comparing against the full 60 GB size (the pre-fix behaviour) would demand
        // ~61.2 GB free on the second poll and wrongly fail a torrent that fits comfortably.
        $freeBytesByCall = [100_000_000_000, 58_000_000_000];
        $callIndex = 0;
        $freeSpaceProvider = $this->createStub(FreeSpaceProvider::class);
        $freeSpaceProvider->method('getFreeBytes')->willReturnCallback(
            static function () use (&$callIndex, $freeBytesByCall): int {
                return $freeBytesByCall[$callIndex++];
            },
        );

        $pollIndex = 0;
        $torrentsByPoll = [
            // Metadata just arrived: nothing written yet, amount_left equals the full size.
            [['hash' => self::HASH, 'progress' => 0.0, 'state' => 'metaDL', 'size' => 60_000_000_000, 'amount_left' => 60_000_000_000]],
            // 70% written: amount_left has shrunk in step with free space.
            [['hash' => self::HASH, 'progress' => 0.7, 'state' => 'downloading', 'size' => 60_000_000_000, 'amount_left' => 18_000_000_000]],
        ];
        $httpClient = new MockHttpClient(function () use (&$pollIndex, $torrentsByPoll): MockResponse {
            $response = new MockResponse(
                json_encode($torrentsByPoll[$pollIndex], \JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            );
            ++$pollIndex;

            return $response;
        });

        $jail = new DownloadFolderJail(new AppSettingsProvider(new AppConfigStore($this->configPath)));
        $linker = new AnimeDownloadLinker(new StorageRepository($this->entityManager), $this->entityManager, $jail);
        $poller = new DownloadCompletionPoller(
            new QbittorrentClient($httpClient, self::BASE_URL),
            $this->downloads,
            $linker,
            $eventDispatcher,
            $this->entityManager,
            new FreeSpaceChecker($jail, $freeSpaceProvider),
            new NullLogger(),
        );

        $poller->poll();
        $poller->poll();

        $stored = $this->downloads->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertNotNull($stored);
        $this->assertSame(DownloadStatus::Pending, $stored->getStatus());
    }

    public function testPollMarksEveryPendingRowFailedForASeasonPackSharingOneInfoHash(): void
    {
        $animeOne = $this->persistAnime();
        $animeTwo = $this->persistAnime();
        $this->downloads->save(new Download(self::HASH, $animeOne));
        $this->downloads->save(new Download(self::HASH, $animeTwo));

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        $freeSpaceProvider = $this->createStub(FreeSpaceProvider::class);
        $freeSpaceProvider->method('getFreeBytes')->willReturn(100_000_000);

        $poller = $this->makePoller([[
            'hash' => self::HASH,
            'progress' => 0.4,
            'state' => 'metaDL',
            'size' => 500_000_000,
        ]], $eventDispatcher, $freeSpaceProvider);

        $poller->poll();

        $rowOne = $this->downloads->findByInfoHashAndAnime(self::HASH, (int) $animeOne->id);
        $rowTwo = $this->downloads->findByInfoHashAndAnime(self::HASH, (int) $animeTwo->id);
        $this->assertNotNull($rowOne);
        $this->assertNotNull($rowTwo);
        $this->assertTrue($rowOne->isFailed());
        $this->assertTrue($rowTwo->isFailed());
    }
}
