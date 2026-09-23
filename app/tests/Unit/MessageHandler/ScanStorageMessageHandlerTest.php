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

use AnimeDb\PluginContracts\Catalog\AnimeFilesChangedEvent;
use AnimeDb\PluginContracts\Catalog\FilesChangeReason;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\WatchStatus;
use App\Entity\Storage;
use App\Entity\TvAnime;
use App\Message\ScanStorageMessage;
use App\MessageHandler\ScanStorageMessageHandler;
use App\Repository\AnimeRepository;
use App\Repository\StudioRepository;
use App\Service\JobLock\JobLockService;
use App\Service\JobLock\ProcessLivenessChecker;
use App\Service\Plugin\Filler\BulkFillerService;
use App\Service\Plugin\Filler\PluginAnimeDataMerger;
use App\Service\Plugin\Filler\PluginMediaDownloaderInterface;
use App\Service\Plugin\FillerRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Storage\FilenameCleaner;
use App\Service\Storage\OrphanAnimeMatcher;
use App\Service\Storage\ScanStorageService;
use App\Service\Storage\Search\SearchByPluginChain;
use App\Service\Storage\StorageMarkerService;
use App\Service\WsPublisher;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Exercises ScanStorageMessageHandler end to end: real EntityManager/SQLite connection for the
 * catalog (same setup as ScanStorageServiceTest) plus a real JobLockService/WsPublisher backed
 * by an in-memory "queue" connection (same setup as JobLockServiceTest) — the job_locks
 * re-entrancy guard and the ws_events payloads only mean something when exercised against real
 * storage, not mocks of a `final` ScanStorageService.
 */
final class ScanStorageMessageHandlerTest extends TestCase
{
    private const HEARTBEAT_INTERVAL_SECONDS = 30;
    private const STALE_AFTER_MISSED_HEARTBEATS = 3;

    private EntityManager $entityManager;
    private Connection $queueConnection;
    private MockClock $clock;

    /** @var list<string> */
    private array $dirsToClean = [];

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

        $this->queueConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->clock = new MockClock(new \DateTimeImmutable('@1000'));
    }

    protected function tearDown(): void
    {
        foreach ($this->dirsToClean as $dir) {
            $this->removeDir($dir);
        }
    }

    public function testDoesNotScanAgainWhileAnActiveProcessHoldsTheLock(): void
    {
        $dir = $this->makeStorageDir();
        $this->touchFile($dir.'/Trigun.mkv');

        $storage = new Storage('Main folder', $dir, StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();
        $storageId = $this->requireId($storage);

        $this->insertLock(\sprintf('scan:storage:%d', $storageId), pid: 424242, heartbeatAt: 1000);

        $livenessChecker = $this->createStub(ProcessLivenessChecker::class);
        $livenessChecker->method('getStartedAt')->willReturn(new \DateTimeImmutable('@1000'));

        $wsPublisher = $this->newWsPublisher();
        $handler = $this->newHandler($livenessChecker, $wsPublisher);

        $handler(new ScanStorageMessage($storageId));

        // No progress/done/failed event — the scan was skipped entirely, not just its output discarded.
        $this->assertSame([], $wsPublisher->since(0));
        $this->assertNull($storage->getDateUpdate());

        // The other process's lock is untouched — this call never took it over.
        $lock = $this->queueConnection->fetchAssociative(
            'SELECT pid FROM job_locks WHERE job_key = :jobKey',
            ['jobKey' => \sprintf('scan:storage:%d', $storageId)],
        );
        $this->assertNotFalse($lock);
        $this->assertSame(424242, (int) $lock['pid']);
    }

    public function testSuccessfulScanPublishesProgressThenDoneAndReleasesTheLock(): void
    {
        $dir = $this->makeStorageDir();
        $this->touchFile($dir.'/Trigun.mkv');

        $storage = new Storage('Main folder', $dir, StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();
        $storageId = $this->requireId($storage);

        $wsPublisher = $this->newWsPublisher();
        $handler = $this->newHandler($this->createStub(ProcessLivenessChecker::class), $wsPublisher);

        $handler(new ScanStorageMessage($storageId));

        $events = $wsPublisher->since(0);
        $this->assertCount(4, $events);

        $busy = $events[0];
        $this->assertSame('backend.status', $busy['event']);
        $this->assertSame(['state' => 'busy'], $busy['data']);

        $progress = $events[1];
        $this->assertSame('scan.progress', $progress['event']);
        $this->assertSame([
            'storage_id' => $storageId,
            'processed' => 1,
            'total' => 1,
            'percent' => 100,
        ], $progress['data']);

        $done = $events[2];
        $this->assertSame('scan.done', $done['event']);
        $this->assertSame($storageId, $done['data']['storage_id']);
        $this->assertCount(1, $done['data']['items']);
        $this->assertSame('NeedsManualEntry', $done['data']['items'][0]['type']);
        $this->assertSame('Trigun.mkv', $done['data']['items'][0]['storage_path']);

        $idle = $events[3];
        $this->assertSame('backend.status', $idle['event']);
        $this->assertSame(['state' => 'idle'], $idle['data']);

        $this->assertNotNull($storage->getDateUpdate());
        $this->assertJobLockReleased($storageId);
    }

    public function testDispatchesAnimeFilesChangedEventWithFilesAddedReasonForEachUpdatedItem(): void
    {
        $dir = $this->makeStorageDir();
        $filePath = $dir.'/Trigun.mkv';
        $this->touchFile($filePath, time() - 100);

        $storage = new Storage('Main folder', $dir, StorageType::Folder);
        $this->entityManager->persist($storage);

        $anime = new TvAnime();
        $anime->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $anime->setStorage($storage)->setStoragePath('Trigun.mkv');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $storageId = $this->requireId($storage);
        $animeId = $anime->id ?? throw new \LogicException('Anime must have an id once flushed.');

        // Past Anime::$dateUpdate (set at construction, above), so the scan reports it Updated.
        touch($filePath, time() + 100);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (AnimeFilesChangedEvent $event) use ($animeId): bool {
                $this->assertSame($animeId, $event->anime->value);
                $this->assertSame(FilesChangeReason::FilesAdded, $event->reason);

                return true;
            }));

        $wsPublisher = $this->newWsPublisher();
        $handler = $this->newHandler(
            $this->createStub(ProcessLivenessChecker::class),
            $wsPublisher,
            eventDispatcher: $eventDispatcher,
        );

        $handler(new ScanStorageMessage($storageId));

        $events = $wsPublisher->since(0);
        $done = $events[2];
        $this->assertSame('scan.done', $done['event']);
        $this->assertSame('Updated', $done['data']['items'][0]['type']);
    }

    public function testDispatchesAnimeFilesChangedEventWithPathChangedReasonForEachAutoLinkedItem(): void
    {
        $dir = $this->makeStorageDir();
        $this->touchFile($dir.'/Trigun.mkv');

        $storage = new Storage('Main folder', $dir, StorageType::Folder);
        $this->entityManager->persist($storage);

        // An orphan Anime (no storage linked yet) whose title matches the file — scan()'s 0/1/>1
        // rule finds exactly one candidate and auto-links it via linkToChosenCandidate(), the same
        // call StorageScanConfirmController makes for a manual choice.
        $orphan = new TvAnime();
        $orphan->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($orphan);
        $this->entityManager->flush();
        $storageId = $this->requireId($storage);
        $orphanId = $orphan->id ?? throw new \LogicException('Anime must have an id once flushed.');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (AnimeFilesChangedEvent $event) use ($orphanId): bool {
                $this->assertSame($orphanId, $event->anime->value);
                $this->assertSame(FilesChangeReason::PathChanged, $event->reason);

                return true;
            }));

        $wsPublisher = $this->newWsPublisher();
        $handler = $this->newHandler(
            $this->createStub(ProcessLivenessChecker::class),
            $wsPublisher,
            eventDispatcher: $eventDispatcher,
        );

        $handler(new ScanStorageMessage($storageId));

        $events = $wsPublisher->since(0);
        $done = $events[2];
        $this->assertSame('scan.done', $done['event']);
        $this->assertSame('AutoLinked', $done['data']['items'][0]['type']);
    }

    public function testScanRelocatesStorageToThePathFoundByMarkerWhenTheCurrentPathIsUnreadable(): void
    {
        $relocatedDir = $this->makeStorageDir();
        $this->touchFile($relocatedDir.'/Trigun.mkv');

        // A drive-root path never created on disk, so is_readable() reports it unreadable —
        // the drive-letter-reassigned / drive-disconnected case (issue #162). Deliberately a
        // drive root ("Z:\", not "Z:\Anime") so the marker search degenerates to checking each
        // candidate root directly — see StorageMarkerServiceTest for the subfolder-tail case.
        $staleDir = 'Z:\\';

        $storage = new Storage('Main folder', $staleDir, StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();
        $storageId = $this->requireId($storage);

        file_put_contents($relocatedDir.'/desktop.ini', "[AnimeDB]\nid={$storageId}\n");

        $wsPublisher = $this->newWsPublisher();
        $handler = $this->newHandler($this->createStub(ProcessLivenessChecker::class), $wsPublisher, [$relocatedDir]);

        $handler(new ScanStorageMessage($storageId));

        $events = $wsPublisher->since(0);
        $this->assertCount(4, $events);

        $done = $events[2];
        $this->assertSame('scan.done', $done['event']);
        $this->assertCount(1, $done['data']['items']);
        $this->assertSame('Trigun.mkv', $done['data']['items'][0]['storage_path']);

        $this->assertSame($relocatedDir, $storage->getPath());
        $this->assertNotNull($storage->getDateUpdate());
    }

    public function testBackendStatusStaysBusyWhileAnotherStorageScanIsStillHoldingItsLock(): void
    {
        $dir = $this->makeStorageDir();
        $this->touchFile($dir.'/Trigun.mkv');

        $storage = new Storage('Main folder', $dir, StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();
        $storageId = $this->requireId($storage);

        // Another storage's scan is still running (its own, independent job_locks row).
        $this->insertLock('scan:storage:other', pid: 424242, heartbeatAt: 1000);

        $livenessChecker = $this->createStub(ProcessLivenessChecker::class);
        $livenessChecker->method('getStartedAt')->willReturn(new \DateTimeImmutable('@1000'));

        $wsPublisher = $this->newWsPublisher();
        $handler = $this->newHandler($livenessChecker, $wsPublisher);

        $handler(new ScanStorageMessage($storageId));

        $events = $wsPublisher->since(0);
        $statusEvents = array_values(array_filter($events, static fn (array $event): bool => $event['event'] === 'backend.status'));

        $this->assertCount(1, $statusEvents);
        $this->assertSame(['state' => 'busy'], $statusEvents[0]['data']);
    }

    public function testMarkerConflictPublishesScanFailedWithoutThrowing(): void
    {
        $dir = $this->makeStorageDir();
        $this->touchFile($dir.'/New.mkv');

        $owner = new Storage('Owner', $this->makeStorageDir(), StorageType::Folder);
        $this->entityManager->persist($owner);
        $this->entityManager->flush();

        file_put_contents($dir.'/desktop.ini', "[AnimeDB]\nid={$owner->id}\n");

        $storage = new Storage('Target', $dir, StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();
        $storageId = $this->requireId($storage);

        $wsPublisher = $this->newWsPublisher();
        $handler = $this->newHandler($this->createStub(ProcessLivenessChecker::class), $wsPublisher);

        $handler(new ScanStorageMessage($storageId));

        $events = $wsPublisher->since(0);
        $this->assertCount(3, $events);

        $busy = $events[0];
        $this->assertSame('backend.status', $busy['event']);
        $this->assertSame(['state' => 'busy'], $busy['data']);

        $failed = $events[1];
        $this->assertSame('scan.failed', $failed['event']);
        $this->assertSame($storageId, $failed['data']['storage_id']);
        $this->assertSame('marker_conflict', $failed['data']['reason']);

        $idle = $events[2];
        $this->assertSame('backend.status', $idle['event']);
        $this->assertSame(['state' => 'idle'], $idle['data']);

        $this->assertJobLockReleased($storageId);
    }

    public function testUnknownStorageIsWrappedIntoAnUnrecoverableExceptionAndPublishesScanFailed(): void
    {
        $wsPublisher = $this->newWsPublisher();
        $handler = $this->newHandler($this->createStub(ProcessLivenessChecker::class), $wsPublisher);

        try {
            $handler(new ScanStorageMessage(999));
            $this->fail('Expected UnrecoverableMessageHandlingException.');
        } catch (UnrecoverableMessageHandlingException) {
            // expected
        }

        $events = $wsPublisher->since(0);
        $this->assertCount(1, $events);

        $failed = $events[0];
        $this->assertSame('scan.failed', $failed['event']);
        $this->assertSame(999, $failed['data']['storage_id']);
        $this->assertSame('exception', $failed['data']['reason']);

        // The entity lookup fails before JobLockService ever touches the connection, so the
        // table may not exist yet — that in itself proves no lock was taken.
        $this->queueConnection->executeStatement('CREATE TABLE IF NOT EXISTS job_locks (
            job_key VARCHAR(255) PRIMARY KEY NOT NULL,
            pid INTEGER NOT NULL,
            heartbeat_at INTEGER NOT NULL,
            started_at INTEGER NOT NULL
        )');
        $lockCount = $this->queueConnection->fetchOne('SELECT COUNT(*) FROM job_locks');
        $this->assertSame(0, (int) $lockCount);
    }

    private function requireId(Storage $storage): int
    {
        return $storage->id ?? throw new \LogicException('Storage must be persisted before use in this test.');
    }

    /** @param ?iterable<string> $driveRoots */
    private function newHandler(
        ProcessLivenessChecker $livenessChecker,
        WsPublisher $wsPublisher,
        ?iterable $driveRoots = null,
        ?EventDispatcherInterface $eventDispatcher = null,
    ): ScanStorageMessageHandler {
        $animeRepository = new AnimeRepository($this->entityManager);
        $storageMarkerService = new StorageMarkerService($this->entityManager, $driveRoots);

        $scanStorageService = new ScanStorageService(
            $storageMarkerService,
            new FilenameCleaner(),
            new OrphanAnimeMatcher($animeRepository),
            new SearchByPluginChain([], new PluginsConfigStore('')),
            $animeRepository,
            $this->entityManager,
            new BulkFillerService(
                new FillerRegistry([], new PluginsConfigStore('')),
                new PluginAnimeDataMerger(
                    new StudioRepository($this->entityManager),
                    $this->entityManager,
                    $this->createStub(PluginMediaDownloaderInterface::class),
                ),
                $this->entityManager,
                new NullLogger(),
                $this->createMock(MessageBusInterface::class),
            ),
            new NullLogger(),
        );

        $jobLockService = new JobLockService(
            $this->queueConnection,
            $livenessChecker,
            $this->clock,
            self::HEARTBEAT_INTERVAL_SECONDS,
            self::STALE_AFTER_MISSED_HEARTBEATS,
        );

        return new ScanStorageMessageHandler(
            $this->entityManager,
            $jobLockService,
            $scanStorageService,
            $storageMarkerService,
            $wsPublisher,
            new NullLogger(),
            $eventDispatcher ?? $this->createStub(EventDispatcherInterface::class),
        );
    }

    private function newWsPublisher(): WsPublisher
    {
        return new WsPublisher($this->queueConnection);
    }

    private function assertJobLockReleased(int $storageId): void
    {
        $lock = $this->queueConnection->fetchAssociative(
            'SELECT pid FROM job_locks WHERE job_key = :jobKey',
            ['jobKey' => \sprintf('scan:storage:%d', $storageId)],
        );
        $this->assertFalse($lock);
    }

    private function insertLock(string $jobKey, int $pid, int $heartbeatAt): void
    {
        $this->queueConnection->executeStatement('CREATE TABLE IF NOT EXISTS job_locks (
            job_key VARCHAR(255) PRIMARY KEY NOT NULL,
            pid INTEGER NOT NULL,
            heartbeat_at INTEGER NOT NULL,
            started_at INTEGER NOT NULL
        )');

        $this->queueConnection->insert('job_locks', [
            'job_key' => $jobKey,
            'pid' => $pid,
            'heartbeat_at' => $heartbeatAt,
            'started_at' => $heartbeatAt,
        ]);
    }

    private function makeStorageDir(): string
    {
        $dir = sys_get_temp_dir().'/scan-storage-handler-test-'.uniqid();
        mkdir($dir, recursive: true);
        $this->dirsToClean[] = $dir;

        return $dir;
    }

    private function touchFile(string $path, ?int $mtime = null): void
    {
        file_put_contents($path, 'x');
        touch($path, $mtime ?? time());
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }

        rmdir($dir);
    }
}
