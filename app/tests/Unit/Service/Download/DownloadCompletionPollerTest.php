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
use App\Entity\Anime;
use App\Entity\Download;
use App\Entity\Enum\DownloadStatus;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\WatchStatus;
use App\Entity\Storage;
use App\Entity\TvAnime;
use App\Repository\AnimeRepository;
use App\Repository\DownloadRepository;
use App\Service\Download\AnimeDownloadLinker;
use App\Service\Download\DownloadCompletionPoller;
use App\Service\Download\DownloadFolderJail;
use App\Service\Download\DownloadIncomingRelocator;
use App\Service\Download\DownloadStorageFilesystem;
use App\Service\Download\FreeSpaceChecker;
use App\Service\Download\FreeSpaceProvider;
use App\Service\Download\NativeFreeSpaceProvider;
use App\Service\Qbittorrent\QbittorrentClient;
use App\Service\Storage\StorageMarkerService;
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
    private const string HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private EntityManager $entityManager;
    private DownloadRepository $downloads;
    private StorageMarkerService $markerService;
    private Storage $storage;
    private string $root;

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
        $this->markerService = new StorageMarkerService($this->entityManager);

        // A real temp directory, not a bare Windows-style literal like the pre-#852 fixture: the
        // marker check DownloadCompletionPoller now runs before every move/link (issue #852) does
        // real desktop.ini file I/O via StorageMarkerService, which needs a path that actually
        // exists on this Linux CI runner.
        $this->root = sys_get_temp_dir().'/animedb-poller-test-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0o777, true);

        $this->storage = new Storage('AnimeDB', $this->root, StorageType::Folder);
        $this->entityManager->persist($this->storage);
        $this->entityManager->flush();
        $this->markerService->reconcile($this->storage);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entryPath = $path.'/'.$entry;
            is_dir($entryPath) ? $this->removeDirectory($entryPath) : unlink($entryPath);
        }

        rmdir($path);
    }

    /**
     * A {@see DownloadIncomingRelocator} wired with a real {@see StorageMarkerService} (sharing
     * this test's own, so a storage reconciled in setUp() or a test is recognized) and, unless
     * overridden, a filesystem double that reports no conflicts — the same role {@see
     * QbittorrentDownloadServiceTest}'s own fake filesystem plays for enqueueTo().
     */
    private function makeRelocator(QbittorrentClient $client, ?DownloadStorageFilesystem $filesystem = null): DownloadIncomingRelocator
    {
        return new DownloadIncomingRelocator(
            $client,
            new AnimeRepository($this->entityManager),
            $this->markerService,
            $filesystem ?? new StubDownloadStorageFilesystem(),
            $this->entityManager,
            new NullLogger(),
        );
    }

    private function persistAnime(): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle('Test')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    private function newDownload(string $infoHash, TvAnime $anime): Download
    {
        $download = new Download($infoHash, $anime);
        $download->assignTargetStorage($this->storage);

        return $download;
    }

    private function saveDownload(string $infoHash, TvAnime $anime): Download
    {
        $download = $this->newDownload($infoHash, $anime);
        $this->downloads->save($download);

        return $download;
    }

    /** `<$this->root>\.anime-db\incoming\<$infoHash>\<$name>` — see DownloadFolderJail::resolveIncomingSavePathForInfoHash(). */
    private function incomingContentPath(string $infoHash, string $name): string
    {
        return $this->root.'\\.anime-db\\incoming\\'.$infoHash.'\\'.$name;
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

        $jail = new DownloadFolderJail();
        $linker = new AnimeDownloadLinker(new AnimeRepository($this->entityManager), $this->entityManager, $jail);
        $client = new QbittorrentClient($httpClient, self::BASE_URL);

        return new DownloadCompletionPoller(
            $client,
            $this->downloads,
            $linker,
            $jail,
            $this->makeRelocator($client),
            $eventDispatcher,
            $this->entityManager,
            // The real NativeFreeSpaceProvider reports "unknown" (free-open) for $this->root on
            // this Linux test runner — only tests about the free-space check itself override this.
            new FreeSpaceChecker($freeSpaceProvider ?? new NativeFreeSpaceProvider()),
            new NullLogger(),
        );
    }

    public function testPollLinksFolderAndDispatchesEventForAFinishedTorrent(): void
    {
        $anime = $this->persistAnime();
        $this->saveDownload(self::HASH, $anime);

        /** @var list<AnimeFilesChangedEvent|DownloadCompletedEvent> $dispatched */
        $dispatched = [];
        $eventDispatcher = $this->dispatcherCapturingEvents(2, $dispatched);

        $poller = $this->makePoller([[
            'hash' => self::HASH,
            'infohash_v1' => self::HASH,
            'progress' => 1,
            'state' => 'uploading',
            'content_path' => $this->root.'\\finished-release',
        ]], $eventDispatcher);

        $poller->poll();

        $stored = $this->downloads->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertNotNull($stored);
        $this->assertTrue($stored->isCompleted());
        $this->assertNotNull($anime->getStorage());
        $this->assertSame($this->root, $anime->getStorage()->getPath());
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
        $this->saveDownload(self::HASH, $anime);

        $dispatched = [];
        // Two events (AnimeFilesChangedEvent + DownloadCompletedEvent) on the first poll(), none
        // on the second — a completed pair must not dispatch again.
        $eventDispatcher = $this->dispatcherCapturingEvents(2, $dispatched);

        $poller = $this->makePoller([[
            'hash' => self::HASH,
            'infohash_v1' => self::HASH,
            'progress' => 1,
            'state' => 'uploading',
            'content_path' => $this->root.'\\finished-release',
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
        $this->saveDownload(self::HASH, $anime);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        $poller = $this->makePoller([[
            'hash' => self::HASH,
            'infohash_v1' => self::HASH,
            'progress' => $progress,
            'state' => $state,
            'content_path' => $this->root.'\\'.$case,
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
        $this->saveDownload($wedgedHash, $wedged);
        $this->saveDownload(self::HASH, $ok);

        /** @var list<AnimeFilesChangedEvent|DownloadCompletedEvent> $dispatched */
        $dispatched = [];
        // Only the OK pair completes and dispatches (AnimeFilesChangedEvent + DownloadCompletedEvent);
        // the wedged pair's DownloadPathOutsideJailException must not dispatch anything for it.
        $eventDispatcher = $this->dispatcherCapturingEvents(2, $dispatched);

        // One /api/v2/torrents/info?tag= request per pass now returns every one of this app's
        // torrents at once (issue #843) — the mock must not filter by a "hashes" query param
        // (qBittorrent itself cannot find a hybrid torrent that way, see class docblock), so both
        // fixtures are always returned together and matched in-process by "infohash_v1".
        $httpClient = new MockHttpClient(fn (): MockResponse => new MockResponse(
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
                    'content_path' => $this->root.'\\finished-release',
                ],
            ], \JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        ));

        $jail = new DownloadFolderJail();
        $linker = new AnimeDownloadLinker(new AnimeRepository($this->entityManager), $this->entityManager, $jail);
        $client = new QbittorrentClient($httpClient, self::BASE_URL);
        $poller = new DownloadCompletionPoller(
            $client,
            $this->downloads,
            $linker,
            $jail,
            $this->makeRelocator($client),
            $eventDispatcher,
            $this->entityManager,
            new FreeSpaceChecker(new NativeFreeSpaceProvider()),
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
        $this->saveDownload(self::HASH, $first);
        $this->saveDownload($secondHash, $second);
        $this->saveDownload($thirdHash, $third);

        /** @var list<AnimeFilesChangedEvent|DownloadCompletedEvent> $dispatched */
        $dispatched = [];
        // First and third complete (two events each); the conflicting second dispatches nothing.
        $eventDispatcher = $this->dispatcherCapturingEvents(4, $dispatched);

        // Two different torrents whose content_path resolves to the same relative path. One
        // /api/v2/torrents/info?tag= request per pass returns all three at once (issue #843); the
        // mock must not filter by a "hashes" query param, matching instead happens in-process by
        // "infohash_v1".
        $httpClient = new MockHttpClient(fn (): MockResponse => new MockResponse(
            json_encode([
                ['hash' => self::HASH, 'infohash_v1' => self::HASH, 'progress' => 1, 'state' => 'uploading', 'content_path' => $this->root.'\\season-pack'],
                ['hash' => $secondHash, 'infohash_v1' => $secondHash, 'progress' => 1, 'state' => 'uploading', 'content_path' => $this->root.'\\season-pack'],
                ['hash' => $thirdHash, 'infohash_v1' => $thirdHash, 'progress' => 1, 'state' => 'uploading', 'content_path' => $this->root.'\\other-release'],
            ], \JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        ));

        /** @var list<array{message: string, context: array<mixed>}> $logged */
        $logged = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(static function (string|\Stringable $message, array $context = []) use (&$logged): void {
            $logged[] = ['message' => (string) $message, 'context' => $context];
        });

        $jail = new DownloadFolderJail();
        $linker = new AnimeDownloadLinker(new AnimeRepository($this->entityManager), $this->entityManager, $jail);
        $client = new QbittorrentClient($httpClient, self::BASE_URL);
        $poller = new DownloadCompletionPoller(
            $client,
            $this->downloads,
            $linker,
            $jail,
            $this->makeRelocator($client),
            $eventDispatcher,
            $this->entityManager,
            new FreeSpaceChecker(new NativeFreeSpaceProvider()),
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
        $this->assertSame('storage_conflict', $secondRow->getFailureReason());
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
        $this->assertSame($this->root.'\\season-pack', $logged[0]['context']['contentPath']);
        $this->assertSame($first->id, $logged[0]['context']['occupyingAnimeId']);
    }

    /**
     * target_storage_id is ON DELETE SET NULL (see Download entity): a Pending download whose
     * Storage was deleted while still in flight reaches completeDownload() with a null target
     * storage, not a conflicting one. Unlike the jail/path-conflict cases above, there is nothing
     * to retry — the storage is gone for good — so this must end in Failed, not loop forever.
     */
    public function testPollFailsADownloadWhoseTargetStorageWasDeletedWhileInFlight(): void
    {
        $anime = $this->persistAnime();
        // Deliberately not assignTargetStorage(): simulates ON DELETE SET NULL firing on a
        // still-Pending row after its Storage was removed.
        $download = new Download(self::HASH, $anime);
        $this->downloads->save($download);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        /** @var list<array{message: string, context: array<mixed>}> $logged */
        $logged = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(static function (string|\Stringable $message, array $context = []) use (&$logged): void {
            $logged[] = ['message' => (string) $message, 'context' => $context];
        });

        $httpClient = new MockHttpClient(fn (): MockResponse => new MockResponse(
            json_encode([
                ['hash' => self::HASH, 'infohash_v1' => self::HASH, 'progress' => 1, 'state' => 'uploading', 'content_path' => $this->root.'\\finished-release'],
            ], \JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        ));

        $jail = new DownloadFolderJail();
        $linker = new AnimeDownloadLinker(new AnimeRepository($this->entityManager), $this->entityManager, $jail);
        $client = new QbittorrentClient($httpClient, self::BASE_URL);
        $poller = new DownloadCompletionPoller(
            $client,
            $this->downloads,
            $linker,
            $jail,
            $this->makeRelocator($client),
            $eventDispatcher,
            $this->entityManager,
            new FreeSpaceChecker(new NativeFreeSpaceProvider()),
            $logger,
        );

        $poller->poll();

        $stored = $this->downloads->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertNotNull($stored);
        $this->assertSame(DownloadStatus::Failed, $stored->getStatus());
        $this->assertNull($anime->getStorage());
        $this->assertNull($anime->getStoragePath());

        $this->assertCount(1, $logged);
        $this->assertSame(self::HASH, $logged[0]['context']['infoHash']);
    }

    public function testPollStopsAndMarksFailedWhenAMagnetsKnownSizeDoesNotFitFreeSpace(): void
    {
        // Deliberately different from self::HASH (this app's v1 infoHash): qBittorrent's own
        // torrent id for a hybrid torrent is its truncated v2 hash, not the v1 hash — stop() must
        // be addressed with THIS value, never self::HASH (issue #843).
        $qbittorrentHash = 'dddddddddddddddddddddddddddddddddddddddd';

        $anime = $this->persistAnime();
        $this->saveDownload(self::HASH, $anime);

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

        $jail = new DownloadFolderJail();
        $linker = new AnimeDownloadLinker(new AnimeRepository($this->entityManager), $this->entityManager, $jail);
        $client = new QbittorrentClient($httpClient, self::BASE_URL);
        $poller = new DownloadCompletionPoller(
            $client,
            $this->downloads,
            $linker,
            $jail,
            $this->makeRelocator($client),
            $eventDispatcher,
            $this->entityManager,
            new FreeSpaceChecker($freeSpaceProvider),
            new NullLogger(),
        );

        $poller->poll();

        $this->assertCount(1, $stopCalls);
        $this->assertSame('hashes='.$qbittorrentHash, $stopCalls[0]);
        $stored = $this->downloads->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertNotNull($stored);
        $this->assertTrue($stored->isFailed());
        $this->assertSame(DownloadStatus::Failed, $stored->getStatus());
        $this->assertSame('disk_space', $stored->getFailureReason());

        // Once Failed, the row drops out of findDistinctPendingInfoHashes() — a second poll must
        // not stop (or log) it again.
        $poller->poll();
        $this->assertCount(1, $stopCalls);
    }

    public function testPollDoesNotPauseWhenAMagnetsKnownSizeFitsFreeSpace(): void
    {
        $anime = $this->persistAnime();
        $this->saveDownload(self::HASH, $anime);

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
        $this->saveDownload(self::HASH, $anime);

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

        $jail = new DownloadFolderJail();
        $linker = new AnimeDownloadLinker(new AnimeRepository($this->entityManager), $this->entityManager, $jail);
        $client = new QbittorrentClient($httpClient, self::BASE_URL);
        $poller = new DownloadCompletionPoller(
            $client,
            $this->downloads,
            $linker,
            $jail,
            $this->makeRelocator($client),
            $eventDispatcher,
            $this->entityManager,
            new FreeSpaceChecker($freeSpaceProvider),
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
        $this->saveDownload(self::HASH, $animeOne);
        $this->saveDownload($otherHash, $animeTwo);

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

        $httpClient = new MockHttpClient(fn (): MockResponse => new MockResponse(
            json_encode([
                ['hash' => self::HASH, 'infohash_v1' => self::HASH, 'progress' => 1, 'state' => 'uploading', 'content_path' => $this->root.'\\finished-release'],
                ['hash' => $otherHash, 'infohash_v1' => $otherHash, 'progress' => 1, 'state' => 'uploading', 'content_path' => $this->root.'\\finished-release'],
            ], \JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        ));

        $jail = new DownloadFolderJail();
        $linker = new AnimeDownloadLinker(new AnimeRepository($this->entityManager), $this->entityManager, $jail);
        $client = new QbittorrentClient($httpClient, self::BASE_URL);
        $poller = new DownloadCompletionPoller(
            $client,
            $downloads,
            $linker,
            $jail,
            $this->makeRelocator($client),
            $eventDispatcher,
            $this->entityManager,
            new FreeSpaceChecker(new NativeFreeSpaceProvider()),
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
        $this->saveDownload(self::HASH, $animeOne);
        $this->saveDownload($otherHash, $animeTwo);
        $failedHash = $this->downloads->findDistinctPendingInfoHashes()[0];
        $failedAnimeId = $failedHash === self::HASH ? $animeOne->id : $animeTwo->id;

        /** @var list<AnimeFilesChangedEvent|DownloadCompletedEvent> $dispatched */
        $dispatched = [];
        // Only the second hash completes.
        $eventDispatcher = $this->dispatcherCapturingEvents(2, $dispatched);

        $httpClient = new MockHttpClient(fn (): MockResponse => new MockResponse(
            json_encode([
                ['hash' => self::HASH, 'infohash_v1' => self::HASH, 'progress' => 1, 'state' => 'uploading', 'content_path' => $this->root.'\\finished-release'],
                ['hash' => $otherHash, 'infohash_v1' => $otherHash, 'progress' => 1, 'state' => 'uploading', 'content_path' => $this->root.'\\finished-release'],
            ], \JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        ));

        // findByStorageAndPath() fails (as a DBAL error would) for the first link() only, i.e.
        // after markCompleted() but before link()'s own flush(); the EntityManager stays open.
        $animes = new class($this->entityManager) extends AnimeRepository {
            private bool $failed = false;

            public function findByStorageAndPath(Storage $storage, string $storagePath): ?Anime
            {
                if (!$this->failed) {
                    $this->failed = true;

                    throw new \RuntimeException('database is locked');
                }

                return parent::findByStorageAndPath($storage, $storagePath);
            }
        };

        $jail = new DownloadFolderJail();
        $linker = new AnimeDownloadLinker($animes, $this->entityManager, $jail);
        $client = new QbittorrentClient($httpClient, self::BASE_URL);
        $poller = new DownloadCompletionPoller(
            $client,
            $this->downloads,
            $linker,
            $jail,
            $this->makeRelocator($client),
            $eventDispatcher,
            $this->entityManager,
            new FreeSpaceChecker(new NativeFreeSpaceProvider()),
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
        $this->saveDownload(self::HASH, $this->persistAnime());
        $this->saveDownload($otherHash, $this->persistAnime());

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

        $httpClient = new MockHttpClient(fn (): MockResponse => new MockResponse(
            json_encode([
                ['hash' => self::HASH, 'infohash_v1' => self::HASH, 'progress' => 1, 'state' => 'uploading', 'content_path' => $this->root.'\\finished-release'],
                ['hash' => $otherHash, 'infohash_v1' => $otherHash, 'progress' => 1, 'state' => 'uploading', 'content_path' => $this->root.'\\finished-release'],
            ], \JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        ));

        $jail = new DownloadFolderJail();
        $linker = new AnimeDownloadLinker(new AnimeRepository($this->entityManager), $this->entityManager, $jail);
        $client = new QbittorrentClient($httpClient, self::BASE_URL);
        $poller = new DownloadCompletionPoller(
            $client,
            $downloads,
            $linker,
            $jail,
            $this->makeRelocator($client),
            $eventDispatcher,
            $entityManager,
            new FreeSpaceChecker(new NativeFreeSpaceProvider()),
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
        $this->saveDownload(self::HASH, $anime);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        $animes = new class($this->entityManager) extends AnimeRepository {
            public function findByStorageAndPath(Storage $storage, string $storagePath): ?Anime
            {
                throw new \RuntimeException('database is locked');
            }
        };

        $jail = new DownloadFolderJail();
        $linker = new AnimeDownloadLinker($animes, $this->entityManager, $jail);
        $client = new QbittorrentClient(new MockHttpClient(fn (): MockResponse => new MockResponse(
            json_encode([['hash' => self::HASH, 'infohash_v1' => self::HASH, 'progress' => 1, 'state' => 'uploading', 'content_path' => $this->root.'\\some-release']], \JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        )), self::BASE_URL);
        $poller = new DownloadCompletionPoller(
            $client,
            $this->downloads,
            $linker,
            $jail,
            $this->makeRelocator($client),
            $eventDispatcher,
            $this->entityManager,
            new FreeSpaceChecker(new NativeFreeSpaceProvider()),
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
        $download = $this->newDownload(self::HASH, $anime);
        $this->downloads->save($download);
        $downloadId = $download->id;

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        $connection = $this->entityManager->getConnection();
        $animes = new class($this->entityManager, $connection, (int) $downloadId) extends AnimeRepository {
            public function __construct(
                EntityManagerInterface $entityManager,
                private readonly Connection $connection,
                private readonly int $downloadId,
            ) {
                parent::__construct($entityManager);
            }

            public function findByStorageAndPath(Storage $storage, string $storagePath): ?Anime
            {
                // Simulates a concurrent "app:downloads:unlink" deleting the row, between this
                // poll() loading it and link()'s own flush() further down the call stack.
                $this->connection->executeStatement('DELETE FROM downloads WHERE id = ?', [$this->downloadId]);

                return parent::findByStorageAndPath($storage, $storagePath);
            }
        };

        $jail = new DownloadFolderJail();
        $linker = new AnimeDownloadLinker($animes, $this->entityManager, $jail);
        $client = new QbittorrentClient(new MockHttpClient(fn (): MockResponse => new MockResponse(
            json_encode([['hash' => self::HASH, 'infohash_v1' => self::HASH, 'progress' => 1, 'state' => 'uploading', 'content_path' => $this->root.'\\some-release']], \JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        )), self::BASE_URL);
        $poller = new DownloadCompletionPoller(
            $client,
            $this->downloads,
            $linker,
            $jail,
            $this->makeRelocator($client),
            $eventDispatcher,
            $this->entityManager,
            new FreeSpaceChecker(new NativeFreeSpaceProvider()),
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
        $this->saveDownload(self::HASH, $anime);

        /** @var list<AnimeFilesChangedEvent|DownloadCompletedEvent> $dispatched */
        $dispatched = [];
        $eventDispatcher = $this->dispatcherCapturingEvents(2, $dispatched);

        $poller = $this->makePoller([[
            'hash' => $truncatedV2Hash,
            'infohash_v1' => self::HASH,
            'progress' => 1,
            'state' => 'uploading',
            'content_path' => $this->root.'\\hybrid-release',
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
        $this->saveDownload(self::HASH, $anime);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        $poller = $this->makePoller([[
            'hash' => 'ffffffffffffffffffffffffffffffffffffffff',
            'infohash_v1' => '',
            'progress' => 1,
            'state' => 'uploading',
            'content_path' => $this->root.'\\someone-elses-release',
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

        $jail = new DownloadFolderJail();
        $linker = new AnimeDownloadLinker(new AnimeRepository($this->entityManager), $this->entityManager, $jail);
        $client = new QbittorrentClient($httpClient, self::BASE_URL);
        $poller = new DownloadCompletionPoller(
            $client,
            $this->downloads,
            $linker,
            $jail,
            $this->makeRelocator($client),
            $this->createMock(EventDispatcherInterface::class),
            $this->entityManager,
            new FreeSpaceChecker(new NativeFreeSpaceProvider()),
            new NullLogger(),
        );

        $poller->poll();
    }

    public function testPollMakesExactlyOneTorrentsInfoRequestTaggedAnimedbPerPassWhenThereArePendingDownloads(): void
    {
        $otherHash = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        $this->saveDownload(self::HASH, $this->persistAnime());
        $this->saveDownload($otherHash, $this->persistAnime());

        /** @var list<array{0: string, 1: string}> $requests */
        $requests = [];
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = [$method, $url];

            return new MockResponse(json_encode([], \JSON_THROW_ON_ERROR), [
                'response_headers' => ['content-type' => 'application/json'],
            ]);
        });

        $jail = new DownloadFolderJail();
        $linker = new AnimeDownloadLinker(new AnimeRepository($this->entityManager), $this->entityManager, $jail);
        $client = new QbittorrentClient($httpClient, self::BASE_URL);
        $poller = new DownloadCompletionPoller(
            $client,
            $this->downloads,
            $linker,
            $jail,
            $this->makeRelocator($client),
            $this->createMock(EventDispatcherInterface::class),
            $this->entityManager,
            new FreeSpaceChecker(new NativeFreeSpaceProvider()),
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
        $this->saveDownload(self::HASH, $anime);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->anything(), $this->callback(static fn (array $context): bool => ($context['infoHash'] ?? null) === self::HASH));

        $httpClient = new MockHttpClient(static fn (): MockResponse => new MockResponse(json_encode([], \JSON_THROW_ON_ERROR), [
            'response_headers' => ['content-type' => 'application/json'],
        ]));

        $jail = new DownloadFolderJail();
        $linker = new AnimeDownloadLinker(new AnimeRepository($this->entityManager), $this->entityManager, $jail);
        $client = new QbittorrentClient($httpClient, self::BASE_URL);
        $poller = new DownloadCompletionPoller(
            $client,
            $this->downloads,
            $linker,
            $jail,
            $this->makeRelocator($client),
            $eventDispatcher,
            $this->entityManager,
            new FreeSpaceChecker(new NativeFreeSpaceProvider()),
            $logger,
        );

        $poller->poll();
        $poller->poll();
        $poller->poll();

        $stored = $this->downloads->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertNotNull($stored);
        $this->assertSame(DownloadStatus::Pending, $stored->getStatus());
    }

    /**
     * Issue #852: a multi-file torrent still under the storage's hidden incoming directory, with
     * its target free both in the filesystem and the database, is moved via `torrents/setLocation`
     * addressed to the storage ROOT — not deep-linked by the file scanner yet, so the row stays
     * Pending (completion only happens once a later poll sees content_path already at the root).
     */
    public function testPollMovesAFinishedMultiFileTorrentOutOfIncomingWhenTheTargetIsFree(): void
    {
        $anime = $this->persistAnime();
        $this->saveDownload(self::HASH, $anime);
        $qbHash = 'dddddddddddddddddddddddddddddddddddddddd';
        // The dot in the name also proves a multi-file target is never extension-stripped (that
        // only applies to a single file, see the next test).
        $contentPath = $this->incomingContentPath(self::HASH, 'Release.Name');

        $setLocationCalls = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$setLocationCalls, $qbHash, $contentPath): MockResponse {
            if ($method === 'POST' && str_contains($url, '/api/v2/torrents/setLocation')) {
                $setLocationCalls[] = $options['body'];

                return new MockResponse('');
            }

            return new MockResponse(json_encode([[
                'hash' => $qbHash,
                'infohash_v1' => self::HASH,
                'progress' => 1,
                'state' => 'uploading',
                'content_path' => $contentPath,
            ]], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        });

        $jail = new DownloadFolderJail();
        $linker = new AnimeDownloadLinker(new AnimeRepository($this->entityManager), $this->entityManager, $jail);
        $client = new QbittorrentClient($httpClient, self::BASE_URL);
        $poller = new DownloadCompletionPoller(
            $client,
            $this->downloads,
            $linker,
            $jail,
            $this->makeRelocator($client),
            $this->createMock(EventDispatcherInterface::class),
            $this->entityManager,
            new FreeSpaceChecker(new NativeFreeSpaceProvider()),
            new NullLogger(),
        );

        $poller->poll();

        $this->assertCount(1, $setLocationCalls);
        parse_str($setLocationCalls[0], $parsed);
        $this->assertSame($qbHash, $parsed['hashes']);
        $this->assertSame($this->root, $parsed['location']);

        $stored = $this->downloads->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertNotNull($stored);
        $this->assertSame(DownloadStatus::Pending, $stored->getStatus());
        $this->assertSame(1, $stored->getMoveAttempts());
    }

    /**
     * A single-file torrent must not land bare in the storage root (an unrecognized extension
     * like ".mka"/".iso" would be filtered out by the storage scanner, see the issue) — its
     * `torrents/setLocation` target is a dedicated folder, named after the file without its
     * extension, and {@see StubDownloadStorageFilesystem::isFile()} is what tells the relocator
     * content_path is a file at all rather than a multi-file torrent's own folder.
     */
    public function testPollMovesAFinishedSingleFileTorrentIntoItsOwnFolder(): void
    {
        $anime = $this->persistAnime();
        $this->saveDownload(self::HASH, $anime);
        $qbHash = 'dddddddddddddddddddddddddddddddddddddddd';
        $contentPath = $this->incomingContentPath(self::HASH, 'Release.Name.mkv');
        $expectedFolder = $this->root.'\\Release.Name';

        $setLocationCalls = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$setLocationCalls, $qbHash, $contentPath): MockResponse {
            if ($method === 'POST' && str_contains($url, '/api/v2/torrents/setLocation')) {
                $setLocationCalls[] = $options['body'];

                return new MockResponse('');
            }

            return new MockResponse(json_encode([[
                'hash' => $qbHash,
                'infohash_v1' => self::HASH,
                'progress' => 1,
                'state' => 'uploading',
                'content_path' => $contentPath,
            ]], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        });

        $jail = new DownloadFolderJail();
        $linker = new AnimeDownloadLinker(new AnimeRepository($this->entityManager), $this->entityManager, $jail);
        $client = new QbittorrentClient($httpClient, self::BASE_URL);
        // The relocator sees content_path only AFTER the jail has resolved/normalized it (always
        // "\"-separated, see DownloadFolderJail::normalize()) — not this test's own raw $contentPath
        // literal, which mixes "/" (from sys_get_temp_dir()) and "\\" (the incoming layout).
        $filesystem = new StubDownloadStorageFilesystem(filePaths: [$jail->assertWithinRoot($this->root, $contentPath)]);
        $poller = new DownloadCompletionPoller(
            $client,
            $this->downloads,
            $linker,
            $jail,
            $this->makeRelocator($client, $filesystem),
            $this->createMock(EventDispatcherInterface::class),
            $this->entityManager,
            new FreeSpaceChecker(new NativeFreeSpaceProvider()),
            new NullLogger(),
        );

        $poller->poll();

        $this->assertCount(1, $setLocationCalls);
        parse_str($setLocationCalls[0], $parsed);
        $this->assertSame($expectedFolder, $parsed['location']);
    }

    /**
     * `torrents/setLocation` on an already-occupied name silently MERGES the two folders instead
     * of failing (the issue's "Проблема") — the relocator must therefore refuse to even attempt
     * it once the target already exists on disk, checked through the filesystem port rather than
     * real I/O (issue #851/#852: a Windows-style target path does not exist on this Linux runner).
     */
    public function testPollFailsTheMoveWhenTheTargetAlreadyExistsOnDisk(): void
    {
        $anime = $this->persistAnime();
        $this->saveDownload(self::HASH, $anime);
        $contentPath = $this->incomingContentPath(self::HASH, 'Release.Name');
        $targetPath = $this->root.'\\Release.Name';

        $setLocationCalls = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$setLocationCalls, $contentPath): MockResponse {
            if ($method === 'POST' && str_contains($url, '/api/v2/torrents/setLocation')) {
                $setLocationCalls[] = $options['body'];
            }

            return new MockResponse(json_encode([[
                'hash' => self::HASH,
                'infohash_v1' => self::HASH,
                'progress' => 1,
                'state' => 'uploading',
                'content_path' => $contentPath,
            ]], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        });

        $jail = new DownloadFolderJail();
        $linker = new AnimeDownloadLinker(new AnimeRepository($this->entityManager), $this->entityManager, $jail);
        $client = new QbittorrentClient($httpClient, self::BASE_URL);
        $filesystem = new StubDownloadStorageFilesystem(existingPaths: [$targetPath]);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');
        $poller = new DownloadCompletionPoller(
            $client,
            $this->downloads,
            $linker,
            $jail,
            $this->makeRelocator($client, $filesystem),
            $eventDispatcher,
            $this->entityManager,
            new FreeSpaceChecker(new NativeFreeSpaceProvider()),
            new NullLogger(),
        );

        $poller->poll();

        $this->assertCount(0, $setLocationCalls);
        $stored = $this->downloads->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertNotNull($stored);
        $this->assertSame(DownloadStatus::Failed, $stored->getStatus());
        $this->assertSame('name_conflict', $stored->getFailureReason());
    }

    /**
     * The filesystem conflict check alone is not enough: a card can already point at a name even
     * when nothing currently sits there on disk (files deleted by hand, or just not scanned yet).
     */
    public function testPollFailsTheMoveWhenTheTargetIsAlreadyLinkedToAnotherCatalogEntry(): void
    {
        $anime = $this->persistAnime();
        $this->saveDownload(self::HASH, $anime);
        $contentPath = $this->incomingContentPath(self::HASH, 'Release.Name');

        $occupant = $this->persistAnime();
        $occupant->setStorage($this->storage)->setStoragePath('Release.Name');
        $this->entityManager->flush();

        $setLocationCalls = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$setLocationCalls, $contentPath): MockResponse {
            if ($method === 'POST' && str_contains($url, '/api/v2/torrents/setLocation')) {
                $setLocationCalls[] = $options['body'];
            }

            return new MockResponse(json_encode([[
                'hash' => self::HASH,
                'infohash_v1' => self::HASH,
                'progress' => 1,
                'state' => 'uploading',
                'content_path' => $contentPath,
            ]], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        });

        $jail = new DownloadFolderJail();
        $linker = new AnimeDownloadLinker(new AnimeRepository($this->entityManager), $this->entityManager, $jail);
        $client = new QbittorrentClient($httpClient, self::BASE_URL);
        $poller = new DownloadCompletionPoller(
            $client,
            $this->downloads,
            $linker,
            $jail,
            $this->makeRelocator($client),
            $this->createMock(EventDispatcherInterface::class),
            $this->entityManager,
            new FreeSpaceChecker(new NativeFreeSpaceProvider()),
            new NullLogger(),
        );

        $poller->poll();

        $this->assertCount(0, $setLocationCalls);
        $stored = $this->downloads->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertNotNull($stored);
        $this->assertSame(DownloadStatus::Failed, $stored->getStatus());
        $this->assertSame('name_conflict', $stored->getFailureReason());
    }

    /**
     * Proves the move target's name comes from basename(content_path) — libtorrent-sanitized —
     * and never from the torrent's own "name" field: a card already occupies the content_path
     * name, not the (deliberately different) torrent name, so a conflict here is only possible if
     * the relocator actually derived the name from content_path.
     */
    public function testPollDerivesTheMoveTargetNameFromContentPathNotTheTorrentName(): void
    {
        $anime = $this->persistAnime();
        $this->saveDownload(self::HASH, $anime);
        $contentPath = $this->incomingContentPath(self::HASH, 'Actual.Folder.Name');

        $occupant = $this->persistAnime();
        $occupant->setStorage($this->storage)->setStoragePath('Actual.Folder.Name');
        $this->entityManager->flush();

        $httpClient = new MockHttpClient(fn (): MockResponse => new MockResponse(json_encode([[
            'hash' => self::HASH,
            'infohash_v1' => self::HASH,
            'name' => 'Completely Different Torrent Name',
            'progress' => 1,
            'state' => 'uploading',
            'content_path' => $contentPath,
        ]], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]));

        $jail = new DownloadFolderJail();
        $linker = new AnimeDownloadLinker(new AnimeRepository($this->entityManager), $this->entityManager, $jail);
        $client = new QbittorrentClient($httpClient, self::BASE_URL);
        $poller = new DownloadCompletionPoller(
            $client,
            $this->downloads,
            $linker,
            $jail,
            $this->makeRelocator($client),
            $this->createMock(EventDispatcherInterface::class),
            $this->entityManager,
            new FreeSpaceChecker(new NativeFreeSpaceProvider()),
            new NullLogger(),
        );

        $poller->poll();

        $stored = $this->downloads->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertNotNull($stored);
        $this->assertSame(DownloadStatus::Failed, $stored->getStatus());
        $this->assertSame('name_conflict', $stored->getFailureReason());
    }

    /**
     * A move that never succeeds (e.g. a locked file on the qBittorrent side, invisible to this
     * app) must not retry forever: the 3rd attempt is the last one — a 4th poll finding
     * content_path still under incoming fails the row instead of sending another
     * `torrents/setLocation`.
     */
    public function testPollFailsAfterTheThirdUnsuccessfulMoveAttempt(): void
    {
        $anime = $this->persistAnime();
        $this->saveDownload(self::HASH, $anime);
        $qbHash = 'dddddddddddddddddddddddddddddddddddddddd';
        $contentPath = $this->incomingContentPath(self::HASH, 'Release.Name');

        $setLocationCalls = 0;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$setLocationCalls, $qbHash, $contentPath): MockResponse {
            if ($method === 'POST' && str_contains($url, '/api/v2/torrents/setLocation')) {
                ++$setLocationCalls;

                return new MockResponse('');
            }

            return new MockResponse(json_encode([[
                'hash' => $qbHash,
                'infohash_v1' => self::HASH,
                'progress' => 1,
                'state' => 'uploading',
                'content_path' => $contentPath,
            ]], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        });

        $jail = new DownloadFolderJail();
        $linker = new AnimeDownloadLinker(new AnimeRepository($this->entityManager), $this->entityManager, $jail);
        $client = new QbittorrentClient($httpClient, self::BASE_URL);
        $poller = new DownloadCompletionPoller(
            $client,
            $this->downloads,
            $linker,
            $jail,
            $this->makeRelocator($client),
            $this->createMock(EventDispatcherInterface::class),
            $this->entityManager,
            new FreeSpaceChecker(new NativeFreeSpaceProvider()),
            new NullLogger(),
        );

        $poller->poll();
        $poller->poll();
        $poller->poll();

        $this->assertSame(3, $setLocationCalls);
        $afterThree = $this->downloads->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertNotNull($afterThree);
        $this->assertSame(DownloadStatus::Pending, $afterThree->getStatus());
        $this->assertSame(3, $afterThree->getMoveAttempts());

        $poller->poll();

        $this->assertSame(3, $setLocationCalls, 'A 4th move must not be attempted once the limit is reached.');
        $stored = $this->downloads->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertNotNull($stored);
        $this->assertSame(DownloadStatus::Failed, $stored->getStatus());
        $this->assertSame('move_failed', $stored->getFailureReason());
    }

    /**
     * A storage's desktop.ini marker no longer naming it (relocated, or the path simply reused)
     * must block a move out of incoming entirely — neither a `torrents/setLocation` call nor a
     * spent move attempt, since this poller cannot confirm which storage it would be writing into.
     */
    public function testPollSkipsTheMoveWhenTheStorageMarkerDoesNotMatch(): void
    {
        $anime = $this->persistAnime();
        $this->saveDownload(self::HASH, $anime);
        $contentPath = $this->incomingContentPath(self::HASH, 'Release.Name');

        file_put_contents($this->root.'/desktop.ini', "[AnimeDB]\nid=999999\n");

        $setLocationCalls = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$setLocationCalls, $contentPath): MockResponse {
            if ($method === 'POST' && str_contains($url, '/api/v2/torrents/setLocation')) {
                $setLocationCalls[] = $options['body'];
            }

            return new MockResponse(json_encode([[
                'hash' => self::HASH,
                'infohash_v1' => self::HASH,
                'progress' => 1,
                'state' => 'uploading',
                'content_path' => $contentPath,
            ]], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        });

        $jail = new DownloadFolderJail();
        $linker = new AnimeDownloadLinker(new AnimeRepository($this->entityManager), $this->entityManager, $jail);
        $client = new QbittorrentClient($httpClient, self::BASE_URL);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');
        $poller = new DownloadCompletionPoller(
            $client,
            $this->downloads,
            $linker,
            $jail,
            $this->makeRelocator($client),
            $eventDispatcher,
            $this->entityManager,
            new FreeSpaceChecker(new NativeFreeSpaceProvider()),
            new NullLogger(),
        );

        $poller->poll();

        $this->assertCount(0, $setLocationCalls);
        $stored = $this->downloads->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertNotNull($stored);
        $this->assertSame(DownloadStatus::Pending, $stored->getStatus());
        $this->assertSame(0, $stored->getMoveAttempts());
    }

    /**
     * Same marker guard, for a download already moved to the storage root (issue #852's "перед
     * переносом и перед линковкой") — a mismatched marker must block linking it too, not just a
     * pending move.
     */
    public function testPollSkipsLinkingWhenTheStorageMarkerDoesNotMatch(): void
    {
        $anime = $this->persistAnime();
        $this->saveDownload(self::HASH, $anime);
        $contentPath = $this->root.'\\finished-release';

        file_put_contents($this->root.'/desktop.ini', "[AnimeDB]\nid=999999\n");

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        $poller = $this->makePoller([[
            'hash' => self::HASH,
            'infohash_v1' => self::HASH,
            'progress' => 1,
            'state' => 'uploading',
            'content_path' => $contentPath,
        ]], $eventDispatcher);

        $poller->poll();

        $stored = $this->downloads->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertNotNull($stored);
        $this->assertFalse($stored->isCompleted());
        $this->assertSame(DownloadStatus::Pending, $stored->getStatus());
    }

    /**
     * A single-file torrent already moved to its own folder at the storage root (content_path is
     * now the file INSIDE that folder, not the folder itself) must still link the FOLDER — the
     * same final shape a multi-file torrent's content_path already has.
     */
    public function testPollLinksTheParentFolderForASingleFileTorrentAlreadyAtTheRoot(): void
    {
        $anime = $this->persistAnime();
        $this->saveDownload(self::HASH, $anime);
        $contentPath = $this->root.'\\Release.Name\\Release.Name.mkv';

        /** @var list<AnimeFilesChangedEvent|DownloadCompletedEvent> $dispatched */
        $dispatched = [];
        $eventDispatcher = $this->dispatcherCapturingEvents(2, $dispatched);

        $poller = $this->makePoller([[
            'hash' => self::HASH,
            'infohash_v1' => self::HASH,
            'progress' => 1,
            'state' => 'uploading',
            'content_path' => $contentPath,
        ]], $eventDispatcher);

        $poller->poll();

        $stored = $this->downloads->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertNotNull($stored);
        $this->assertTrue($stored->isCompleted());
        $this->assertSame('Release.Name', $anime->getStoragePath());
    }

    /**
     * A top-level entry starting with "." other than ".anime-db" (e.g. some unrelated hidden
     * directory) was never put there by this poller and must never be linked from.
     */
    public function testPollDoesNotLinkAHiddenTopLevelEntryOtherThanIncoming(): void
    {
        $anime = $this->persistAnime();
        $this->saveDownload(self::HASH, $anime);
        $contentPath = $this->root.'\\.some-other-hidden-dir\\file.mkv';

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        $poller = $this->makePoller([[
            'hash' => self::HASH,
            'infohash_v1' => self::HASH,
            'progress' => 1,
            'state' => 'uploading',
            'content_path' => $contentPath,
        ]], $eventDispatcher);

        $poller->poll();

        $stored = $this->downloads->findByInfoHashAndAnime(self::HASH, (int) $anime->id);
        $this->assertNotNull($stored);
        $this->assertFalse($stored->isCompleted());
        $this->assertSame(DownloadStatus::Pending, $stored->getStatus());
    }
}

/**
 * Test double for the filesystem port {@see DownloadIncomingRelocator} uses to check whether a
 * move's target already exists and whether a torrent's content_path is a file or a directory
 * (issue #852) — a real implementation would need a real Windows volume (see
 * NativeDownloadStorageFilesystem's docblock), so tests fake it instead, same role {@see
 * \App\Tests\Unit\Service\Download\QbittorrentDownloadServiceTest}'s own fake filesystem plays for
 * enqueueTo(). Reports no conflicts and no single-file torrents unless told otherwise.
 */
final class StubDownloadStorageFilesystem implements DownloadStorageFilesystem
{
    /**
     * @param list<string> $existingPaths paths {@see self::pathExists()} must report as present
     * @param list<string> $filePaths     paths {@see self::isFile()} must report as a regular file
     */
    public function __construct(
        private readonly array $existingPaths = [],
        private readonly array $filePaths = [],
    ) {
    }

    public function pathExists(string $path): bool
    {
        return \in_array($path, $this->existingPaths, true);
    }

    public function isFile(string $path): bool
    {
        return \in_array($path, $this->filePaths, true);
    }

    public function ensureDirectoryExists(string $path): void
    {
    }

    public function ensureHiddenDirectoryExists(string $path): void
    {
    }
}
