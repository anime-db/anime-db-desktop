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

namespace App\Tests\Unit\Service\Storage\Scan;

use App\Service\JobLock\JobLockService;
use App\Service\JobLock\ProcessLivenessChecker;
use App\Service\Storage\Scan\ScanRunJournal;
use App\Service\Storage\Scan\ScanRunStatus;
use App\Tests\Support\RunsMigrations;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ScanRunJournalTest extends TestCase
{
    use RunsMigrations;

    private Connection $connection;
    private JobLockService $jobLock;
    private MockClock $clock;
    private ScanRunJournal $journal;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->buildSchemaByRunningMigrations($this->connection);
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');
        $this->connection->insert('storage', ['name' => 'One', 'type' => 'folder', 'path' => '/one']);
        $this->connection->insert('storage', ['name' => 'Two', 'type' => 'folder', 'path' => '/two']);

        $this->clock = new MockClock(new \DateTimeImmutable('@1000'));
        $this->jobLock = new JobLockService(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
            $this->createStub(ProcessLivenessChecker::class),
            $this->clock,
        );
        $this->journal = new ScanRunJournal($this->connection, $this->jobLock, $this->clock);
    }

    public function testKeepsOnlyTheLastTenRunsOfAStorageWhenAnEleventhIsStarted(): void
    {
        $other = $this->journal->start(2);
        $ids = [];
        for ($i = 0; $i < 11; ++$i) {
            $ids[] = $this->journal->start(1);
        }

        $kept = array_map(static fn ($run): int => $run->id, $this->journal->findRecent(1));

        $this->assertCount(ScanRunJournal::KEEP_RUNS, $kept);
        $this->assertSame(array_reverse(\array_slice($ids, 1)), $kept);
        $this->assertNotContains($ids[0], $kept, 'the oldest run is the one dropped');
        $this->assertCount(1, $this->journal->findRecent(2), 'another storage keeps its runs');
        $this->assertSame($other, $this->journal->findRecent(2)[0]->id);
    }

    public function testDoneStoresNoUpdatedItemsCountsThemAndVersionsEveryItem(): void
    {
        $runId = $this->journal->start(1);
        $this->journal->done($runId, [
            ['type' => 'Updated', 'storage_path' => 'A'],
            ['type' => 'Updated', 'storage_path' => 'B'],
            ['type' => 'NeedsManualEntry', 'storage_path' => 'C'],
            ['type' => 'FilesMissing', 'storage_path' => 'D'],
        ]);

        $run = $this->journal->find(1, $runId);

        $this->assertNotNull($run);
        $this->assertSame(ScanRunStatus::Done, $run->status);
        $this->assertNotNull($run->finishedAt);
        $this->assertSame(['Updated' => 2, 'NeedsManualEntry' => 1, 'FilesMissing' => 1], $run->counts);
        $this->assertSame(
            [['v' => 1, 'type' => 'NeedsManualEntry', 'storage_path' => 'C'], ['v' => 1, 'type' => 'FilesMissing', 'storage_path' => 'D']],
            $run->items,
        );
    }

    public function testFailRecordsTheOutcomeAndTheMessage(): void
    {
        $failed = $this->journal->start(1);
        $this->journal->fail($failed, ScanRunStatus::Failed, 'boom');
        $conflict = $this->journal->start(1);
        $this->journal->fail($conflict, ScanRunStatus::MarkerConflict, 'owned');

        $this->assertSame(ScanRunStatus::Failed, $this->journal->find(1, $failed)?->status);
        $this->assertSame('boom', $this->journal->find(1, $failed)->errorMessage);
        $this->assertSame(ScanRunStatus::MarkerConflict, $this->journal->find(1, $conflict)?->status);
    }

    public function testInterruptedIsNeverAcceptedAsAStoredOutcome(): void
    {
        $runId = $this->journal->start(1);

        $this->expectException(\InvalidArgumentException::class);
        $this->journal->fail($runId, ScanRunStatus::Interrupted, 'x');
    }

    public function testARunningRowWithoutALiveLockReadsAsInterrupted(): void
    {
        $runId = $this->journal->start(1);

        $this->assertSame(ScanRunStatus::Interrupted, $this->journal->find(1, $runId)?->status);
        $this->assertSame('running', $this->connection->fetchOne('SELECT status FROM scan_run WHERE id = ?', [$runId]), 'interrupted is not stored');
    }

    public function testARunningRowWithALiveLockReadsAsRunning(): void
    {
        $this->jobLock->acquire('scan:storage:1');
        $runId = $this->journal->start(1);

        $this->assertSame(ScanRunStatus::Running, $this->journal->find(1, $runId)?->status);
        $this->assertSame(ScanRunStatus::Running, $this->journal->findLatest(1)?->status);
        $this->assertSame(ScanRunStatus::Running, $this->journal->findRecent(1)[0]->status);
    }

    public function testAnOlderRunningRowIsInterruptedEvenWhileANewerRunHoldsTheLock(): void
    {
        $crashed = $this->journal->start(1);
        $this->jobLock->acquire('scan:storage:1');
        $current = $this->journal->start(1);

        $this->assertSame(ScanRunStatus::Interrupted, $this->journal->find(1, $crashed)?->status);
        $this->assertSame(ScanRunStatus::Running, $this->journal->find(1, $current)?->status);
    }

    public function testARunIsNotVisibleThroughAnotherStorage(): void
    {
        $runId = $this->journal->start(1);

        $this->assertNull($this->journal->find(2, $runId));
    }

    public function testDeletingTheStorageDeletesItsRuns(): void
    {
        $this->journal->start(1);
        $kept = $this->journal->start(2);

        $this->connection->delete('storage', ['id' => 1]);

        $this->assertSame([], $this->journal->findRecent(1));
        $this->assertSame([$kept], array_map(static fn ($run): int => $run->id, $this->journal->findRecent(2)));
    }

    public function testItemsWithMissingKeysAndBrokenJsonDoNotBreakReading(): void
    {
        $runId = $this->journal->start(1);
        $this->connection->update('scan_run', ['status' => 'done', 'items' => '[{"v":1},"junk"]', 'counts' => 'not json'], ['id' => $runId]);

        $run = $this->journal->find(1, $runId);

        $this->assertSame([['v' => 1]], $run?->items);
        $this->assertSame([], $run->counts);
    }

    public function testAStreakOfFailuresDoesNotEvictTheLastDoneRun(): void
    {
        $done = $this->journal->start(1);
        $this->journal->done($done, []);
        for ($i = 0; $i < ScanRunJournal::KEEP_RUNS + 2; ++$i) {
            $this->journal->fail($this->journal->start(1), ScanRunStatus::Failed, 'disk is gone');
        }

        $this->assertSame($done, $this->journal->findLatestDone(1)?->id);
        $this->assertTrue($this->journal->isLatestDone(1, $done));
    }
}
