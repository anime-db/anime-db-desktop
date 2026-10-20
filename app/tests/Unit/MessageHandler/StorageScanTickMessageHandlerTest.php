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

use App\Entity\Enum\StorageType;
use App\Entity\Storage;
use App\Message\ScanStorageMessage;
use App\Message\StorageScanTickMessage;
use App\MessageHandler\StorageScanTickMessageHandler;
use App\Repository\StorageRepository;
use App\Service\JobLock\JobLockService;
use App\Service\JobLock\ProcessLivenessChecker;
use App\Service\Storage\Scan\ScanRunJournal;
use App\Service\Storage\Scan\ScanRunStatus;
use App\Service\Storage\StorageRootInspector;
use App\Tests\Support\RunsMigrations;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class StorageScanTickMessageHandlerTest extends TestCase
{
    use RunsMigrations;

    private const int DAY = 86400;

    private ScanRunJournal $journal;
    private JobLockService $jobLock;
    private MockClock $clock;
    private ArrayAdapter $cache;

    /** @var list<object> */
    private array $dispatched = [];

    /** @var array<string, int|null> path => mtime the inspector reports */
    private array $mtimes = [];

    /** @var list<string> paths the filesystem was asked about, in order */
    private array $inspected = [];

    protected function setUp(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->runMigrationFile($connection, \dirname(__DIR__, 3).'/migrations/Version20261008000000.php');
        $this->clock = new MockClock();
        $this->jobLock = new JobLockService(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
            $this->createStub(ProcessLivenessChecker::class),
            $this->clock,
        );
        $this->journal = new ScanRunJournal($connection, $this->jobLock, $this->clock);
        $this->cache = new ArrayAdapter();
    }

    public function testAStorageWithoutRunsIsDue(): void
    {
        $storage = $this->storage(1);
        $this->mtimes[$storage->requirePath()] = 1000;

        $this->tick([$storage]);

        $this->assertSame([1], $this->dispatchedIds());
        $this->assertSame([$storage->requirePath()], $this->inspected);
    }

    public function testAStorageWithoutRunsAndAnUnreachableRootIsMarkedNotQueued(): void
    {
        $this->assertUnreachableIsMarkedNotQueued(null, null, 1);
    }

    public function testAFailedRunOlderThanADayAndAnUnreachableRootIsMarkedNotQueued(): void
    {
        $this->assertUnreachableIsMarkedNotQueued(ScanRunStatus::Failed, 1000, 1);
    }

    public function testADoneRunOlderThanAWeekAndAnUnreachableRootIsMarkedNotQueued(): void
    {
        $this->assertUnreachableIsMarkedNotQueued(ScanRunStatus::Done, 1000, 7);
    }

    public function testADoneRunWithoutFileModifiedAndAnUnreachableRootIsMarkedNotQueued(): void
    {
        $this->assertUnreachableIsMarkedNotQueued(ScanRunStatus::Done, null, 0);
    }

    private function assertUnreachableIsMarkedNotQueued(?ScanRunStatus $status, ?int $fileModified, int $ageDays): void
    {
        if ($status !== null) {
            $this->recordRun(1, $status, ageSeconds: $ageDays * self::DAY + 600);
        }
        $storage = $this->storage(1, fileModified: $fileModified);

        $this->tick([$storage]);

        $this->assertSame([], $this->dispatchedIds());
        $this->assertTrue($this->cache->getItem('storage_scan_unreachable_1')->isHit());
    }

    #[DataProvider('notDoneStatuses')]
    public function testAFailedRunIsRetriedOnlyAfterADay(ScanRunStatus $status): void
    {
        $this->recordRun(1, $status, ageSeconds: self::DAY - 600);
        $this->tick([$this->storage(1, fileModified: 1)]);
        $this->assertSame([], $this->dispatchedIds());

        $this->recordRun(2, $status, ageSeconds: self::DAY + 600);
        $due = $this->storage(2, fileModified: 1);
        $this->mtimes[$due->requirePath()] = 1;
        $this->tick([$due]);
        $this->assertSame([2], $this->dispatchedIds());
    }

    /** @return iterable<string, array{ScanRunStatus}> */
    public static function notDoneStatuses(): iterable
    {
        yield 'failed' => [ScanRunStatus::Failed];
        yield 'marker conflict' => [ScanRunStatus::MarkerConflict];
        yield 'interrupted' => [ScanRunStatus::Interrupted];
    }

    public function testADoneRunIsDueWhenTheRootIsNewerThanFileModified(): void
    {
        $this->recordRun(1, ScanRunStatus::Done, ageSeconds: 3600);
        $storage = $this->storage(1, fileModified: 1000);
        $this->mtimes[$storage->requirePath()] = 1001;

        $this->tick([$storage]);

        $this->assertSame([1], $this->dispatchedIds());
    }

    public function testADoneRunWithoutFileModifiedIsDue(): void
    {
        $this->recordRun(1, ScanRunStatus::Done, ageSeconds: 3600);
        $storage = $this->storage(1);
        $this->mtimes[$storage->requirePath()] = 1000;

        $this->tick([$storage]);

        $this->assertSame([1], $this->dispatchedIds());
    }

    public function testADoneRunOlderThanAWeekIsDue(): void
    {
        $this->recordRun(1, ScanRunStatus::Done, ageSeconds: 7 * self::DAY + 600);

        $storage = $this->storage(1, fileModified: 1000);
        $this->mtimes[$storage->requirePath()] = 1000;

        $this->tick([$storage]);

        $this->assertSame([1], $this->dispatchedIds());
    }

    public function testAFreshDoneRunWithAnUnchangedRootIsNotDue(): void
    {
        $this->recordRun(1, ScanRunStatus::Done, ageSeconds: 3600);
        $storage = $this->storage(1, fileModified: 1000);
        $this->mtimes[$storage->requirePath()] = 1000;

        $this->tick([$storage]);

        $this->assertSame([], $this->dispatchedIds());
        $this->assertSame([$storage->requirePath()], $this->inspected);
    }

    public function testAnUnreadableRootIsMarkedForSixHoursAndNotInspectedAgain(): void
    {
        $this->recordRun(1, ScanRunStatus::Done, ageSeconds: 3600);
        $storage = $this->storage(1, fileModified: 1000);
        $this->mtimes[$storage->requirePath()] = null;

        $this->tick([$storage]);

        $this->assertSame([], $this->dispatchedIds());
        $item = $this->cache->getItem('storage_scan_unreachable_1');
        $this->assertTrue($item->isHit());
        $this->assertSame(6 * 3600, $this->expiresIn($item));
        $this->assertFalse($this->cache->getItem('storage_scan_unreachable_2')->isHit());

        $this->inspected = [];
        $this->tick([$storage]);
        $this->assertSame([], $this->inspected);
        $this->assertSame([], $this->dispatchedIds());
    }

    public function testAHeldScanLockExcludesTheStorage(): void
    {
        $this->assertTrue($this->jobLock->acquire(ScanStorageMessage::jobKey(1)));

        $b = $this->storage(2);
        $this->mtimes[$b->requirePath()] = 1000;
        $this->tick([$this->storage(1), $b]);

        $this->assertSame([2], $this->dispatchedIds());
    }

    public function testOnlyOneStorageIsQueuedAndTheOldestRunGoesFirst(): void
    {
        $this->recordRun(1, ScanRunStatus::Done, ageSeconds: 3600);
        $this->recordRun(2, ScanRunStatus::Done, ageSeconds: 7200);
        $a = $this->storage(1);
        $b = $this->storage(2);
        $c = $this->storage(3);
        foreach ([$a, $b, $c] as $storage) {
            $this->mtimes[$storage->requirePath()] = 1000;
        }

        $this->tick([$a, $b]);
        $this->assertSame([2], $this->dispatchedIds());

        // A storage without runs goes before both.
        $this->dispatched = [];
        $this->tick([$a, $b, $c]);
        $this->assertSame([3], $this->dispatchedIds());
    }

    public function testTheFilesystemIsReadOnlyUpToTheFirstDueStorage(): void
    {
        $this->recordRun(1, ScanRunStatus::Done, ageSeconds: 7200);
        $this->recordRun(2, ScanRunStatus::Done, ageSeconds: 3600);
        $this->recordRun(3, ScanRunStatus::Done, ageSeconds: 1800);
        $a = $this->storage(1, fileModified: 1000);
        $b = $this->storage(2, fileModified: 1000);
        $c = $this->storage(3, fileModified: 1000);
        $this->mtimes[$a->requirePath()] = 1000;
        $this->mtimes[$b->requirePath()] = 2000;
        $this->mtimes[$c->requirePath()] = 2000;

        $this->tick([$c, $b, $a]);

        $this->assertSame([2], $this->dispatchedIds());
        $this->assertSame([$a->requirePath(), $b->requirePath()], $this->inspected);
    }

    public function testTheRootInspectorDropsTheStatCacheBeforeReadingTheMtime(): void
    {
        $dir = sys_get_temp_dir().'/storage-root-inspector-'.uniqid();
        mkdir($dir);
        try {
            $this->setMtimeBehindPhpsBack($dir, 1000000);
            $inspector = new StorageRootInspector();
            $this->assertSame(1000000, $inspector->modifiedAt($dir));
            $this->assertSame(1000000, filemtime($dir)); // primes the stat cache

            $this->setMtimeBehindPhpsBack($dir, 2000000); // an external change PHP does not see
            $this->assertSame(1000000, filemtime($dir));

            $this->assertSame(2000000, $inspector->modifiedAt($dir));
            $this->assertNull($inspector->modifiedAt($dir.'/missing'));
        } finally {
            rmdir($dir);
        }
    }

    /** Not touch()/exec(): both drop PHP's stat cache, which is exactly what this test must not do. */
    private function setMtimeBehindPhpsBack(string $path, int $mtime): void
    {
        $process = proc_open(['touch', '-d', '@'.$mtime, $path], [], $pipes);
        $this->assertIsResource($process);
        proc_close($process);
    }

    /** @param list<Storage> $storages */
    private function tick(array $storages): void
    {
        $repository = $this->createStub(StorageRepository::class);
        $repository->method('findAllScannable')->willReturn($storages);

        $inspector = new class($this->mtimes, function (string $path): void {
            $this->inspected[] = $path;
        }) extends StorageRootInspector {
            /** @param array<string, int|null> $mtimes */
            public function __construct(private readonly array $mtimes, private readonly \Closure $onInspect)
            {
            }

            public function modifiedAt(string $path): ?int
            {
                ($this->onInspect)($path);

                return $this->mtimes[$path] ?? null;
            }
        };

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        (new StorageScanTickMessageHandler($repository, $this->journal, $this->jobLock, $inspector, $bus, $this->cache))(new StorageScanTickMessage());
    }

    /** @return list<int> */
    private function dispatchedIds(): array
    {
        return array_map(static fn (object $m): int => $m instanceof ScanStorageMessage ? $m->storageId : -1, $this->dispatched);
    }

    private function recordRun(int $storageId, ScanRunStatus $status, int $ageSeconds): void
    {
        $this->clock->modify('@'.(time() - $ageSeconds));
        $runId = $this->journal->start($storageId);
        match ($status) {
            ScanRunStatus::Done => $this->journal->done($runId, []),
            ScanRunStatus::Failed, ScanRunStatus::MarkerConflict => $this->journal->fail($runId, $status, 'x'),
            default => null, // a Running row without a live job lock reads back as Interrupted
        };
    }

    private function storage(int $id, ?int $fileModified = null): Storage
    {
        $storage = new Storage('Storage '.$id, '/anime/'.$id, StorageType::Folder);
        (new \ReflectionProperty(Storage::class, 'id'))->setValue($storage, $id);
        if ($fileModified !== null) {
            $storage->markScanned(new \DateTimeImmutable('@'.$fileModified));
        }

        return $storage;
    }

    private function expiresIn(\Psr\Cache\CacheItemInterface $item): int
    {
        $this->assertInstanceOf(\Symfony\Component\Cache\CacheItem::class, $item);
        $metadata = $item->getMetadata();

        return (int) round($metadata['expiry'] - microtime(true));
    }
}
