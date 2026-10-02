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
use App\Entity\Storage;
use App\Entity\TvAnime;
use App\Repository\AnimeRepository;
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
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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
        $linker = new AnimeDownloadLinker(new StorageRepository($this->entityManager), new AnimeRepository($this->entityManager), $this->entityManager, $jail);

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
            'infohash_v1' => self::HASH,
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
            'infohash_v1' => self::HASH,
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
            'infohash_v1' => self::HASH,
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

        // One /api/v2/torrents/info?tag= request per pass now returns every one of this app's
        // torrents at once (issue #843) — the mock must not filter by a "hashes" query param
        // (qBittorrent itself cannot find a hybrid torrent that way, see class docblock), so both
        // fixtures are always returned together and matched in-process by "infohash_v1".
        $httpClient = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            json_encode([
                [
                    'hash' => $wedgedHash,
                    'infohash_v1' => $wedgedHash,
                    'progress' => 1,
                    'state' => 'uploading',
                    'content_path' => 'D:\\elsewhere\\moved-away',
                ],
                [
                    'hash' => self::HASH,
                    'infohash_v1' => self::HASH,
                    'progress' => 1,
                    'state' => 'uploading',
                    'content_path' => self::ROOT.'\\finished-release',
                ],
            ], \JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        ));

        $jail = new DownloadFolderJail(new AppSettingsProvider(new AppConfigStore($this->configPath)));
        $linker = new AnimeDownloadLinker(new StorageRepository($this->entityManager), new AnimeRepository($this->entityManager), $this->entityManager, $jail);
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

    public function testPollFailsTheSecondDownloadWhenItsStoragePathIsAlreadyTaken(): void
    {
        $secondHash = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        $thirdHash = 'cccccccccccccccccccccccccccccccccccccccc';
        $first = $this->persistAnime();
        $second = $this->persistAnime();
        $third = $this->persistAnime();
        $this->downloads->save(new Download(self::HASH, $first));
        $this->downloads->save(new Download($secondHash, $second));
        $this->downloads->save(new Download($thirdHash, $third));

        /** @var list<AnimeFilesChangedEvent|DownloadCompletedEvent> $dispatched */
        $dispatched = [];
        // First and third complete (two events each); the conflicting second dispatches nothing.
        $eventDispatcher = $this->dispatcherCapturingEvents(4, $dispatched);

        // Two different torrents whose content_path resolves to the same relative path. One
        // /api/v2/torrents/info?tag= request per pass returns all three at once (issue #843); the
        // mock must not filter by a "hashes" query param, matching instead happens in-process by
        // "infohash_v1".
        $httpClient = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            json_encode([
                ['hash' => self::HASH, 'infohash_v1' => self::HASH, 'progress' => 1, 'state' => 'uploading', 'content_path' => self::ROOT.'\\season-pack'],
                ['hash' => $secondHash, 'infohash_v1' => $secondHash, 'progress' => 1, 'state' => 'uploading', 'content_path' => self::ROOT.'\\season-pack'],
                ['hash' => $thirdHash, 'infohash_v1' => $thirdHash, 'progress' => 1, 'state' => 'uploading', 'content_path' => self::ROOT.'\\other-release'],
            ], \JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        ));

        /** @var list<array{message: string, context: array<mixed>}> $logged */
        $logged = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(static function (string|\Stringable $message, array $context = []) use (&$logged): void {
            $logged[] = ['message' => (string) $message, 'context' => $context];
        });

        $jail = new DownloadFolderJail(new AppSettingsProvider(new AppConfigStore($this->configPath)));
        $linker = new AnimeDownloadLinker(new StorageRepository($this->entityManager), new AnimeRepository($this->entityManager), $this->entityManager, $jail);
        $poller = new DownloadCompletionPoller(
            new QbittorrentClient($httpClient, self::BASE_URL),
            $this->downloads,
            $linker,
            $eventDispatcher,
            $this->entityManager,
            new FreeSpaceChecker($jail, new NativeFreeSpaceProvider()),
            $logger,
        );

        $poller->poll();

        $firstRow = $this->downloads->findByInfoHashAndAnime(self::HASH, (int) $first->id);
        $this->assertNotNull($firstRow);
        $this->assertTrue($firstRow->isCompleted());
        $this->assertSame('season-pack', $first->getStoragePath());

        $secondRow = $this->downloads->findByInfoHashAndAnime($secondHash, (int) $second->id);
        $this->assertNotNull($secondRow);
        $this->assertSame(DownloadStatus::Failed, $secondRow->getStatus());
        $this->assertNull($second->getStoragePath());

        // The pass reached the end: the third download after the conflict is still processed.
        $thirdRow = $this->downloads->findByInfoHashAndAnime($thirdHash, (int) $third->id);
        $this->assertNotNull($thirdRow);
        $this->assertTrue($thirdRow->isCompleted());
        $this->assertSame('other-release', $third->getStoragePath());

        $this->assertCount(4, $dispatched);
        $this->assertSame([$first->id, $first->id, $third->id, $third->id], array_map(static fn (object $event): int => $event->anime->value, $dispatched));

        $this->assertCount(1, $logged);
        $this->assertStringContainsString(\sprintf('anime #%d', $first->id), $logged[0]['message']);
        $this->assertStringContainsString('app:downloads:unlink', $logged[0]['message']);
        $this->assertSame($secondHash, $logged[0]['context']['infoHash']);
        $this->assertSame(self::ROOT.'\\season-pack', $logged[0]['context']['contentPath']);
        $this->assertSame($first->id, $logged[0]['context']['occupyingAnimeId']);
    }

    public function testPollStopsAndMarksFailedWhenAMagnetsKnownSizeDoesNotFitFreeSpace(): void
    {
        // Deliberately different from self::HASH (this app's v1 infoHash): qBittorrent's own
        // torrent id for a hybrid torrent is its truncated v2 hash, not the v1 hash — stop() must
        // be addressed with THIS value, never self::HASH (issue #843).
        $qbittorrentHash = 'dddddddddddddddddddddddddddddddddddddddd';

        $anime = $this->persistAnime();
        $this->downloads->save(new Download(self::HASH, $anime));

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        $freeSpaceProvider = $this->createStub(FreeSpaceProvider::class);
        $freeSpaceProvider->method('getFreeBytes')->willReturn(100_000_000);

        $stopCalls = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$stopCalls, $qbittorrentHash): MockResponse {
            if ($method === 'POST' && str_contains($url, '/api/v2/torrents/stop')) {
                $stopCalls[] = $options['body'];
            }

            return new MockResponse(
                json_encode([[
                    // Not yet Completed — a magnet whose metadata just arrived, still downloading.
                    'hash' => $qbittorrentHash,
                    'infohash_v1' => self::HASH,
                    'progress' => 0.4,
                    'state' => 'metaDL',
                    'size' => 500_000_000,
                ]], \JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            );
        });

        $jail = new DownloadFolderJail(new AppSettingsProvider(new AppConfigStore($this->configPath)));
        $linker = new AnimeDownloadLinker(new StorageRepository($this->entityManager), new AnimeRepository($this->entityManager), $this->entityManager, $jail);
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

        $this->assertCount(1, $stopCalls);
        $this->assertSame('hashes='.$qbittorrentHash, $stopCalls[0]);
        $stored = $this->downloads->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertNotNull($stored);
        $this->assertTrue($stored->isFailed());
        $this->assertSame(DownloadStatus::Failed, $stored->getStatus());

        // Once Failed, the row drops out of findDistinctPendingInfoHashes() — a second poll must
        // not stop (or log) it again.
        $poller->poll();
        $this->assertCount(1, $stopCalls);
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
            'infohash_v1' => self::HASH,
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
            [['hash' => self::HASH, 'infohash_v1' => self::HASH, 'progress' => 0.0, 'state' => 'metaDL', 'size' => 60_000_000_000, 'amount_left' => 60_000_000_000]],
            // 70% written: amount_left has shrunk in step with free space.
            [['hash' => self::HASH, 'infohash_v1' => self::HASH, 'progress' => 0.7, 'state' => 'downloading', 'size' => 60_000_000_000, 'amount_left' => 18_000_000_000]],
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
        $linker = new AnimeDownloadLinker(new StorageRepository($this->entityManager), new AnimeRepository($this->entityManager), $this->entityManager, $jail);
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

    /**
     * A DownloadRepository whose findPendingByInfoHash() throws for exactly one infoHash — the
     * in-process failure poll() must isolate (class docblock) now that fetching torrents from
     * qBittorrent is a single request per pass rather than one per pending infoHash; a transport
     * failure on that shared request can no longer be isolated per-hash, so these tests simulate
     * the isolation point that still exists: a per-hash DB error while completing a download.
     */
    private function makeDownloadsFailingOnceForOneInfoHash(string $failingInfoHash): DownloadRepository
    {
        return new class($this->entityManager, $failingInfoHash) extends DownloadRepository {
            private bool $failed = false;

            public function __construct(EntityManagerInterface $entityManager, private readonly string $failingInfoHash)
            {
                parent::__construct($entityManager);
            }

            public function findPendingByInfoHash(string $infoHash): array
            {
                if ($infoHash === $this->failingInfoHash && !$this->failed) {
                    $this->failed = true;

                    throw new \RuntimeException('database is locked');
                }

                return parent::findPendingByInfoHash($infoHash);
            }
        };
    }

    public function testAnExceptionOnOneInfoHashDoesNotStopTheRestOfThePass(): void
    {
        $otherHash = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        $animeOne = $this->persistAnime();
        $animeTwo = $this->persistAnime();
        $this->downloads->save(new Download(self::HASH, $animeOne));
        $this->downloads->save(new Download($otherHash, $animeTwo));

        /** @var list<AnimeFilesChangedEvent|DownloadCompletedEvent> $dispatched */
        $dispatched = [];
        $eventDispatcher = $this->dispatcherCapturingEvents(2, $dispatched);

        // Whichever pending infoHash is listed first fails to complete; the other must still
        // complete and dispatch normally.
        $failedHash = $this->downloads->findDistinctPendingInfoHashes()[0];
        $downloads = $this->makeDownloadsFailingOnceForOneInfoHash($failedHash);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                $this->anything(),
                $this->callback(static fn (array $context): bool => ($context['infoHash'] ?? null) === $failedHash
                    && ($context['exceptionClass'] ?? null) === \RuntimeException::class),
            );

        $httpClient = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            json_encode([
                ['hash' => self::HASH, 'infohash_v1' => self::HASH, 'progress' => 1, 'state' => 'uploading', 'content_path' => self::ROOT.'\\finished-release'],
                ['hash' => $otherHash, 'infohash_v1' => $otherHash, 'progress' => 1, 'state' => 'uploading', 'content_path' => self::ROOT.'\\finished-release'],
            ], \JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        ));

        $jail = new DownloadFolderJail(new AppSettingsProvider(new AppConfigStore($this->configPath)));
        $linker = new AnimeDownloadLinker(new StorageRepository($this->entityManager), new AnimeRepository($this->entityManager), $this->entityManager, $jail);
        $poller = new DownloadCompletionPoller(
            new QbittorrentClient($httpClient, self::BASE_URL),
            $downloads,
            $linker,
            $eventDispatcher,
            $this->entityManager,
            new FreeSpaceChecker($jail, new NativeFreeSpaceProvider()),
            $logger,
        );

        $poller->poll();

        $this->assertCount(2, $dispatched);
    }

    public function testALinkFailureBeforeFlushDoesNotLeakACompletedRowIntoTheNextHash(): void
    {
        $otherHash = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        $animeOne = $this->persistAnime();
        $animeTwo = $this->persistAnime();
        $this->downloads->save(new Download(self::HASH, $animeOne));
        $this->downloads->save(new Download($otherHash, $animeTwo));
        $failedHash = $this->downloads->findDistinctPendingInfoHashes()[0];
        $failedAnimeId = $failedHash === self::HASH ? $animeOne->id : $animeTwo->id;

        /** @var list<AnimeFilesChangedEvent|DownloadCompletedEvent> $dispatched */
        $dispatched = [];
        // Only the second hash completes.
        $eventDispatcher = $this->dispatcherCapturingEvents(2, $dispatched);

        $httpClient = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            json_encode([
                ['hash' => self::HASH, 'infohash_v1' => self::HASH, 'progress' => 1, 'state' => 'uploading', 'content_path' => self::ROOT.'\\finished-release'],
                ['hash' => $otherHash, 'infohash_v1' => $otherHash, 'progress' => 1, 'state' => 'uploading', 'content_path' => self::ROOT.'\\finished-release'],
            ], \JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        ));

        // findOneByPath() fails (as a DBAL error would) for the first link() only, i.e. after
        // markCompleted() but before link()'s own flush(); the EntityManager stays open.
        $storages = new class($this->entityManager) extends StorageRepository {
            private bool $failed = false;

            public function findOneByPath(string $path): ?Storage
            {
                if (!$this->failed) {
                    $this->failed = true;

                    throw new \RuntimeException('database is locked');
                }

                return parent::findOneByPath($path);
            }
        };

        $jail = new DownloadFolderJail(new AppSettingsProvider(new AppConfigStore($this->configPath)));
        $linker = new AnimeDownloadLinker($storages, new AnimeRepository($this->entityManager), $this->entityManager, $jail);
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

        $this->entityManager->clear();
        $failedRow = $this->downloads->findByInfoHashAndAnime($failedHash, (int) $failedAnimeId);
        $this->assertNotNull($failedRow);
        $this->assertFalse($failedRow->isCompleted());
        $this->assertContains($failedHash, $this->downloads->findDistinctPendingInfoHashes());
        $this->assertCount(2, $dispatched);
    }

    public function testPollStopsWithoutThrowingWhenTheEntityManagerIsClosedAfterAFailure(): void
    {
        $otherHash = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        $this->downloads->save(new Download(self::HASH, $this->persistAnime()));
        $this->downloads->save(new Download($otherHash, $this->persistAnime()));

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(false);

        $messages = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function (string|\Stringable $message, array $context = []) use (&$messages): void {
            $messages[] = [(string) $message, $context];
        });

        // Whichever pending infoHash is listed first fails every time it is asked to complete.
        $failedHash = $this->downloads->findDistinctPendingInfoHashes()[0];
        $downloads = new class($this->entityManager, $failedHash) extends DownloadRepository {
            /** @var list<string> */
            public array $calls = [];

            public function __construct(EntityManagerInterface $entityManager, private readonly string $failingInfoHash)
            {
                parent::__construct($entityManager);
            }

            public function findPendingByInfoHash(string $infoHash): array
            {
                $this->calls[] = $infoHash;
                if ($infoHash === $this->failingInfoHash) {
                    throw new \RuntimeException('database is locked');
                }

                return parent::findPendingByInfoHash($infoHash);
            }
        };

        $httpClient = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            json_encode([
                ['hash' => self::HASH, 'infohash_v1' => self::HASH, 'progress' => 1, 'state' => 'uploading', 'content_path' => self::ROOT.'\\finished-release'],
                ['hash' => $otherHash, 'infohash_v1' => $otherHash, 'progress' => 1, 'state' => 'uploading', 'content_path' => self::ROOT.'\\finished-release'],
            ], \JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        ));

        $jail = new DownloadFolderJail(new AppSettingsProvider(new AppConfigStore($this->configPath)));
        $linker = new AnimeDownloadLinker(new StorageRepository($this->entityManager), new AnimeRepository($this->entityManager), $this->entityManager, $jail);
        $poller = new DownloadCompletionPoller(
            new QbittorrentClient($httpClient, self::BASE_URL),
            $downloads,
            $linker,
            $eventDispatcher,
            $entityManager,
            new FreeSpaceChecker($jail, new NativeFreeSpaceProvider()),
            $logger,
        );

        $poller->poll();

        $this->assertCount(1, $downloads->calls, 'The second infoHash must not be polled on a closed EntityManager.');
        $this->assertCount(2, $messages);
        $this->assertSame($failedHash, $messages[0][1]['infoHash']);
        $this->assertSame(\RuntimeException::class, $messages[0][1]['exceptionClass']);
        $this->assertStringContainsString('closed', $messages[1][0]);
        $this->assertSame(1, $messages[1][1]['remaining']);
    }

    /**
     * Issue #837: markCompleted() and the (storage, path) snapshot written onto the Download row
     * (see Download::recordLinkedStorage()) must land in the very same flush() as the anime's
     * pointer — otherwise a crash between them would leave a Completed row with no snapshot,
     * which DownloadFolderPointer::releaseIfOwnedBy() can never tell apart from one that legitimately
     * owns no pointer. Simulated the same way testALinkFailureBeforeFlushDoesNotLeakACompletedRowIntoTheNextHash
     * does: a DBAL-like error inside link(), after markCompleted() but strictly before link()'s own
     * flush() ever runs.
     */
    public function testAFailureBeforeTheCompletionFlushLeavesNoPartialSnapshotOrPointer(): void
    {
        $anime = $this->persistAnime();
        $this->downloads->save(new Download(self::HASH, $anime));

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        $storages = new class($this->entityManager) extends StorageRepository {
            public function findOneByPath(string $path): ?Storage
            {
                throw new \RuntimeException('database is locked');
            }
        };

        $jail = new DownloadFolderJail(new AppSettingsProvider(new AppConfigStore($this->configPath)));
        $linker = new AnimeDownloadLinker($storages, new AnimeRepository($this->entityManager), $this->entityManager, $jail);
        $poller = new DownloadCompletionPoller(
            new QbittorrentClient(new MockHttpClient(static fn (): MockResponse => new MockResponse(
                json_encode([['hash' => self::HASH, 'infohash_v1' => self::HASH, 'progress' => 1, 'state' => 'uploading', 'content_path' => self::ROOT.'\\some-release']], \JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            )), self::BASE_URL),
            $this->downloads,
            $linker,
            $eventDispatcher,
            $this->entityManager,
            new FreeSpaceChecker($jail, new NativeFreeSpaceProvider()),
            new NullLogger(),
        );

        $poller->poll();

        $this->entityManager->clear();
        $row = $this->downloads->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertNotNull($row);
        $this->assertFalse($row->isCompleted());
        $this->assertNull($row->getLinkedStorage());
        $this->assertNull($row->getLinkedStoragePath());
        $reloadedAnime = $this->entityManager->find(TvAnime::class, $anime->id);
        $this->assertNotNull($reloadedAnime);
        $this->assertNull($reloadedAnime->getStorage());
        $this->assertNull($reloadedAnime->getStoragePath());
    }

    /**
     * Issue #837, "forward order" race: app:downloads:unlink deletes the Download row between
     * DownloadCompletionPoller loading it (findPendingByInfoHash()) and link()'s own flush(). The
     * row's #[ORM\Version] column makes that UPDATE affect zero rows, so Doctrine raises
     * OptimisticLockException; the anime's pointer is never set, and poll() itself does not
     * propagate the exception to its caller (PollDownloadsMessageHandler) — the same per-infoHash
     * isolation the class docblock already documents for any other \Throwable from link().
     */
    public function testTheRowBeingDeletedBetweenLoadAndFlushDoesNotSetThePointerOrThrow(): void
    {
        $anime = $this->persistAnime();
        $download = new Download(self::HASH, $anime);
        $this->downloads->save($download);
        $downloadId = $download->id;

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        $connection = $this->entityManager->getConnection();
        $storages = new class($this->entityManager, $connection, (int) $downloadId) extends StorageRepository {
            public function __construct(
                EntityManagerInterface $entityManager,
                private readonly Connection $connection,
                private readonly int $downloadId,
            ) {
                parent::__construct($entityManager);
            }

            public function findOneByPath(string $path): ?Storage
            {
                // Simulates a concurrent "app:downloads:unlink" deleting the row, between this
                // poll() loading it and link()'s own flush() further down the call stack.
                $this->connection->executeStatement('DELETE FROM downloads WHERE id = ?', [$this->downloadId]);

                return parent::findOneByPath($path);
            }
        };

        $jail = new DownloadFolderJail(new AppSettingsProvider(new AppConfigStore($this->configPath)));
        $linker = new AnimeDownloadLinker($storages, new AnimeRepository($this->entityManager), $this->entityManager, $jail);
        $poller = new DownloadCompletionPoller(
            new QbittorrentClient(new MockHttpClient(static fn (): MockResponse => new MockResponse(
                json_encode([['hash' => self::HASH, 'infohash_v1' => self::HASH, 'progress' => 1, 'state' => 'uploading', 'content_path' => self::ROOT.'\\some-release']], \JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            )), self::BASE_URL),
            $this->downloads,
            $linker,
            $eventDispatcher,
            $this->entityManager,
            new FreeSpaceChecker($jail, new NativeFreeSpaceProvider()),
            new NullLogger(),
        );

        // Must not throw: poll() isolates this failure the same way it does any other.
        $poller->poll();

        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM downloads WHERE id = ?', [$downloadId]));

        // The failed flush() closes $this->entityManager (a standing Doctrine behaviour this
        // class's docblock already documents for any \Throwable from link()) — a fresh
        // EntityManager over the same connection and configuration reads the committed state back.
        $freshEntityManager = new EntityManager($connection, $this->entityManager->getConfiguration());
        $reloadedAnime = $freshEntityManager->find(TvAnime::class, $anime->id);
        $this->assertNotNull($reloadedAnime);
        $this->assertNull($reloadedAnime->getStorage());
        $this->assertNull($reloadedAnime->getStoragePath());
    }

    /**
     * qBittorrent 5.x (libtorrent 2) identifies a hybrid v1+v2 torrent by its truncated v2 hash,
     * not this app's v1 infoHash (issue #843) — "hash" here is deliberately NOT self::HASH, while
     * "infohash_v1" is, to prove matching happens on "infohash_v1" rather than on "hash".
     */
    public function testPollFindsAndCompletesAHybridTorrentWhoseQbittorrentHashDiffersFromInfoHashV1(): void
    {
        $truncatedV2Hash = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
        $anime = $this->persistAnime();
        $this->downloads->save(new Download(self::HASH, $anime));

        /** @var list<AnimeFilesChangedEvent|DownloadCompletedEvent> $dispatched */
        $dispatched = [];
        $eventDispatcher = $this->dispatcherCapturingEvents(2, $dispatched);

        $poller = $this->makePoller([[
            'hash' => $truncatedV2Hash,
            'infohash_v1' => self::HASH,
            'progress' => 1,
            'state' => 'uploading',
            'content_path' => self::ROOT.'\\hybrid-release',
        ]], $eventDispatcher);

        $poller->poll();

        $stored = $this->downloads->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertNotNull($stored);
        $this->assertTrue($stored->isCompleted());
        $this->assertCount(2, $dispatched);
    }

    /**
     * A torrent with an empty "infohash_v1" (not one of ours, or qBittorrent reporting a stray
     * entry without it) must not match any pending row — it is simply skipped (issue #843).
     */
    public function testPollDoesNotMatchAForeignTorrentWithAnEmptyInfoHashV1(): void
    {
        $anime = $this->persistAnime();
        $this->downloads->save(new Download(self::HASH, $anime));

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        $poller = $this->makePoller([[
            'hash' => 'ffffffffffffffffffffffffffffffffffffffff',
            'infohash_v1' => '',
            'progress' => 1,
            'state' => 'uploading',
            'content_path' => self::ROOT.'\\someone-elses-release',
        ]], $eventDispatcher);

        $poller->poll();

        $stored = $this->downloads->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertNotNull($stored);
        $this->assertFalse($stored->isCompleted());
        $this->assertSame(DownloadStatus::Pending, $stored->getStatus());
    }

    public function testPollMakesNoRequestWhenThereAreNoPendingDownloads(): void
    {
        $this->expectNotToPerformAssertions();

        $httpClient = new MockHttpClient(static function (): never {
            throw new \LogicException('No HTTP request was expected: there are no pending downloads.');
        });

        $jail = new DownloadFolderJail(new AppSettingsProvider(new AppConfigStore($this->configPath)));
        $linker = new AnimeDownloadLinker(new StorageRepository($this->entityManager), new AnimeRepository($this->entityManager), $this->entityManager, $jail);
        $poller = new DownloadCompletionPoller(
            new QbittorrentClient($httpClient, self::BASE_URL),
            $this->downloads,
            $linker,
            $this->createMock(EventDispatcherInterface::class),
            $this->entityManager,
            new FreeSpaceChecker($jail, new NativeFreeSpaceProvider()),
            new NullLogger(),
        );

        $poller->poll();
    }

    public function testPollMakesExactlyOneTorrentsInfoRequestTaggedAnimedbPerPassWhenThereArePendingDownloads(): void
    {
        $otherHash = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        $this->downloads->save(new Download(self::HASH, $this->persistAnime()));
        $this->downloads->save(new Download($otherHash, $this->persistAnime()));

        /** @var list<array{0: string, 1: string}> $requests */
        $requests = [];
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = [$method, $url];

            return new MockResponse(json_encode([], \JSON_THROW_ON_ERROR), [
                'response_headers' => ['content-type' => 'application/json'],
            ]);
        });

        $jail = new DownloadFolderJail(new AppSettingsProvider(new AppConfigStore($this->configPath)));
        $linker = new AnimeDownloadLinker(new StorageRepository($this->entityManager), new AnimeRepository($this->entityManager), $this->entityManager, $jail);
        $poller = new DownloadCompletionPoller(
            new QbittorrentClient($httpClient, self::BASE_URL),
            $this->downloads,
            $linker,
            $this->createMock(EventDispatcherInterface::class),
            $this->entityManager,
            new FreeSpaceChecker($jail, new NativeFreeSpaceProvider()),
            new NullLogger(),
        );

        $poller->poll();

        $this->assertCount(1, $requests, 'Exactly one torrents/info request must be made per poll() pass, regardless of how many infoHashes are pending.');
        [$method, $url] = $requests[0];
        $this->assertSame('GET', $method);
        $this->assertStringContainsString('/api/v2/torrents/info', $url);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        $this->assertSame(['tag' => QbittorrentClient::TAG], $query);
    }

    /**
     * A torrent missing from qBittorrent's response (removed by hand in the WebUI, or its tag was
     * removed) must not change the row's status, and must log its warning only once across
     * several poll() passes — not on every 5-minute tick (issue #843).
     */
    public function testPollLeavesStatusUnchangedAndWarnsOnceForATorrentMissingAcrossSeveralPasses(): void
    {
        $anime = $this->persistAnime();
        $this->downloads->save(new Download(self::HASH, $anime));

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->anything(), $this->callback(static fn (array $context): bool => ($context['infoHash'] ?? null) === self::HASH));

        $httpClient = new MockHttpClient(static fn (): MockResponse => new MockResponse(json_encode([], \JSON_THROW_ON_ERROR), [
            'response_headers' => ['content-type' => 'application/json'],
        ]));

        $jail = new DownloadFolderJail(new AppSettingsProvider(new AppConfigStore($this->configPath)));
        $linker = new AnimeDownloadLinker(new StorageRepository($this->entityManager), new AnimeRepository($this->entityManager), $this->entityManager, $jail);
        $poller = new DownloadCompletionPoller(
            new QbittorrentClient($httpClient, self::BASE_URL),
            $this->downloads,
            $linker,
            $eventDispatcher,
            $this->entityManager,
            new FreeSpaceChecker($jail, new NativeFreeSpaceProvider()),
            $logger,
        );

        $poller->poll();
        $poller->poll();
        $poller->poll();

        $stored = $this->downloads->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertNotNull($stored);
        $this->assertSame(DownloadStatus::Pending, $stored->getStatus());
    }
}
