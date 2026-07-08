<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\JobLock;

use App\Service\JobLock\JobLockService;
use App\Service\JobLock\ProcessLivenessChecker;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class JobLockServiceTest extends TestCase
{
    private const JOB_KEY = 'scan:storage:1';
    private const HEARTBEAT_INTERVAL_SECONDS = 30;
    private const STALE_AFTER_MISSED_HEARTBEATS = 3;

    private Connection $connection;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->clock = new MockClock(new \DateTimeImmutable('@1000'));
    }

    public function testAcquiresLockWhenNoneExists(): void
    {
        $livenessChecker = $this->createMock(ProcessLivenessChecker::class);
        $livenessChecker->expects($this->never())->method('isRunning');

        $service = $this->createService($livenessChecker);

        $this->assertTrue($service->acquire(self::JOB_KEY));

        $lock = $this->fetchLock();
        $this->assertNotFalse($lock);
        $this->assertSame(getmypid(), $lock['pid']);
        $this->assertSame(1000, $lock['heartbeat_at']);
        $this->assertSame(1000, $lock['started_at']);
    }

    public function testDoesNotAcquireLockWhenOwnerIsAliveAndHeartbeatIsFresh(): void
    {
        $this->insertLock(pid: 424242, heartbeatAt: 1000, startedAt: 1000);

        $livenessChecker = $this->createStub(ProcessLivenessChecker::class);
        $livenessChecker->method('isRunning')->willReturn(true);

        $service = $this->createService($livenessChecker);
        $this->clock->modify('+10 seconds');

        $this->assertFalse($service->acquire(self::JOB_KEY));

        $lock = $this->fetchLock();
        $this->assertNotFalse($lock);
        $this->assertSame(424242, $lock['pid']);
    }

    public function testTakesOverLockWhenOwnerPidIsDead(): void
    {
        $this->insertLock(pid: 424242, heartbeatAt: 1000, startedAt: 1000);

        $livenessChecker = $this->createStub(ProcessLivenessChecker::class);
        $livenessChecker->method('isRunning')->willReturn(false);

        $service = $this->createService($livenessChecker);
        $this->clock->modify('+5 seconds');

        $this->assertTrue($service->acquire(self::JOB_KEY));

        $lock = $this->fetchLock();
        $this->assertNotFalse($lock);
        $this->assertSame(getmypid(), $lock['pid']);
        $this->assertSame(1005, $lock['heartbeat_at']);
        $this->assertSame(1005, $lock['started_at']);
    }

    public function testTakesOverLockWhenHeartbeatIsStaleEvenIfOwnerPidIsAlive(): void
    {
        $this->insertLock(pid: 424242, heartbeatAt: 1000, startedAt: 1000);

        $livenessChecker = $this->createStub(ProcessLivenessChecker::class);
        $livenessChecker->method('isRunning')->willReturn(true);

        $service = $this->createService($livenessChecker);
        // One second past the stale threshold (interval * missed heartbeats).
        $this->clock->modify('+'.(self::HEARTBEAT_INTERVAL_SECONDS * self::STALE_AFTER_MISSED_HEARTBEATS + 1).' seconds');

        $this->assertTrue($service->acquire(self::JOB_KEY));

        $lock = $this->fetchLock();
        $this->assertNotFalse($lock);
        $this->assertSame(getmypid(), $lock['pid']);
    }

    public function testDoesNotTakeOverLockWhenHeartbeatIsNotYetStale(): void
    {
        $this->insertLock(pid: 424242, heartbeatAt: 1000, startedAt: 1000);

        $livenessChecker = $this->createStub(ProcessLivenessChecker::class);
        $livenessChecker->method('isRunning')->willReturn(true);

        $service = $this->createService($livenessChecker);
        $this->clock->modify('+'.(self::HEARTBEAT_INTERVAL_SECONDS * self::STALE_AFTER_MISSED_HEARTBEATS).' seconds');

        $this->assertFalse($service->acquire(self::JOB_KEY));
    }

    public function testHeartbeatRefreshesTimestampForOwningPid(): void
    {
        $service = $this->createService($this->createStub(ProcessLivenessChecker::class));
        $service->acquire(self::JOB_KEY);

        $this->clock->modify('+15 seconds');
        $service->heartbeat(self::JOB_KEY);

        $lock = $this->fetchLock();
        $this->assertNotFalse($lock);
        $this->assertSame(1015, $lock['heartbeat_at']);
        $this->assertSame(1000, $lock['started_at']);
    }

    public function testHeartbeatIsNoopForALockOwnedByAnotherPid(): void
    {
        $this->insertLock(pid: 424242, heartbeatAt: 1000, startedAt: 1000);

        $service = $this->createService($this->createStub(ProcessLivenessChecker::class));
        $this->clock->modify('+15 seconds');
        $service->heartbeat(self::JOB_KEY);

        $lock = $this->fetchLock();
        $this->assertNotFalse($lock);
        $this->assertSame(1000, $lock['heartbeat_at']);
    }

    public function testReleaseDeletesTheLock(): void
    {
        $service = $this->createService($this->createStub(ProcessLivenessChecker::class));
        $service->acquire(self::JOB_KEY);

        $service->release(self::JOB_KEY);

        $this->assertFalse($this->fetchLock());
    }

    public function testReleaseIsNoopWhenLockDoesNotExist(): void
    {
        $service = $this->createService($this->createStub(ProcessLivenessChecker::class));

        $service->release(self::JOB_KEY);

        $this->assertFalse($this->fetchLock());
    }

    public function testReleaseDoesNotDeleteALockOwnedByAnotherPid(): void
    {
        $this->insertLock(pid: 424242, heartbeatAt: 1000, startedAt: 1000);

        $service = $this->createService($this->createStub(ProcessLivenessChecker::class));
        $service->release(self::JOB_KEY);

        $lock = $this->fetchLock();
        $this->assertNotFalse($lock);
        $this->assertSame(424242, $lock['pid']);
    }

    public function testLocksForDifferentJobKeysDoNotInterfere(): void
    {
        $livenessChecker = $this->createStub(ProcessLivenessChecker::class);
        $livenessChecker->method('isRunning')->willReturn(true);

        $service = $this->createService($livenessChecker);

        $this->assertTrue($service->acquire('scan:storage:1'));
        $this->assertTrue($service->acquire('scan:storage:2'));
    }

    private function createService(ProcessLivenessChecker $livenessChecker): JobLockService
    {
        return new JobLockService(
            $this->connection,
            $livenessChecker,
            $this->clock,
            self::HEARTBEAT_INTERVAL_SECONDS,
            self::STALE_AFTER_MISSED_HEARTBEATS,
        );
    }

    private function insertLock(int $pid, int $heartbeatAt, int $startedAt): void
    {
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS job_locks (
            job_key VARCHAR(255) PRIMARY KEY NOT NULL,
            pid INTEGER NOT NULL,
            heartbeat_at INTEGER NOT NULL,
            started_at INTEGER NOT NULL
        )');

        $this->connection->insert('job_locks', [
            'job_key' => self::JOB_KEY,
            'pid' => $pid,
            'heartbeat_at' => $heartbeatAt,
            'started_at' => $startedAt,
        ]);
    }

    /** @return array{job_key: string, pid: int, heartbeat_at: int, started_at: int}|false */
    private function fetchLock(): array|false
    {
        $lock = $this->connection->fetchAssociative(
            'SELECT job_key, pid, heartbeat_at, started_at FROM job_locks WHERE job_key = :jobKey',
            ['jobKey' => self::JOB_KEY],
        );

        if ($lock === false) {
            return false;
        }

        return [
            'job_key' => (string) $lock['job_key'],
            'pid' => (int) $lock['pid'],
            'heartbeat_at' => (int) $lock['heartbeat_at'],
            'started_at' => (int) $lock['started_at'],
        ];
    }
}
