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

namespace App\Service\JobLock;

use App\Service\JobLock\Exception\ProcessLivenessCheckException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Guards a logical background job (identified by an arbitrary $jobKey, e.g.
 * "scan:storage:{id}") against running twice at once — e.g. two independent scans of the
 * same storage racing to add the same anime. Backed by the `job_locks` table in the
 * dedicated `queue` connection (data/queue.db, see issue #97).
 *
 * The table is created lazily (CREATE TABLE IF NOT EXISTS) rather than through a Doctrine
 * migration: doctrine/doctrine-migrations-bundle only tracks a single connection for the
 * whole project (the existing catalog migrations already claim the "default" one), so it
 * cannot target "queue" without either moving the catalog migrations off "default" or wiring
 * per-migration connection overrides through service migrations — both far more invasive
 * than this table needs. The messenger_messages table on the same connection already follows
 * an explicit, non-migration setup (`messenger:setup-transports`, auto_setup=0); this follows
 * the same precedent.
 */
final class JobLockService
{
    private bool $schemaEnsured = false;

    public function __construct(
        #[Target('queue.connection')]
        private readonly Connection $connection,
        private readonly ProcessLivenessChecker $livenessChecker,
        private readonly ClockInterface $clock,
        private readonly int $heartbeatIntervalSeconds = 30,
        private readonly int $staleAfterMissedHeartbeats = 3,
    ) {
    }

    /**
     * Attempts to take the lock for $jobKey. Returns true if the caller now owns it (either
     * because it was free, or because the previous owner is dead/stalled and got taken over),
     * false if another live process already holds it.
     */
    public function acquire(string $jobKey): bool
    {
        $this->ensureSchemaExists();

        if ($this->tryInsertLock($jobKey)) {
            return true;
        }

        $lock = $this->connection->fetchAssociative(
            'SELECT pid, heartbeat_at FROM job_locks WHERE job_key = :jobKey',
            ['jobKey' => $jobKey],
        );

        if ($lock === false) {
            // Released between the failed insert above and this read — safe to retry once.
            return $this->tryInsertLock($jobKey);
        }

        $ownerPid = (int) $lock['pid'];
        $heartbeatAt = (int) $lock['heartbeat_at'];

        if ($this->isSameProcess($ownerPid, $heartbeatAt) && !$this->isHeartbeatStale($heartbeatAt)) {
            return false;
        }

        return $this->tryTakeOverLock($jobKey, $heartbeatAt);
    }

    /**
     * Refreshes heartbeat_at for a lock this process currently owns. No-op if it doesn't
     * (e.g. already released, or taken over by another process).
     */
    public function heartbeat(string $jobKey): void
    {
        $this->ensureSchemaExists();

        $this->connection->executeStatement(
            'UPDATE job_locks SET heartbeat_at = :now WHERE job_key = :jobKey AND pid = :pid',
            ['now' => $this->now(), 'jobKey' => $jobKey, 'pid' => $this->currentPid()],
        );
    }

    /**
     * Releases the lock for $jobKey if this process currently owns it. No-op if it doesn't
     * (e.g. already released, or taken over by another process) — prevents a stale owner from
     * deleting a lock a different process has since legitimately taken over.
     */
    public function release(string $jobKey): void
    {
        $this->ensureSchemaExists();

        $this->connection->executeStatement(
            'DELETE FROM job_locks WHERE job_key = :jobKey AND pid = :pid',
            ['jobKey' => $jobKey, 'pid' => $this->currentPid()],
        );
    }

    /**
     * Whether any job in the table still counts as actively running. A row whose heartbeat has
     * gone stale doesn't count — the same staleness threshold acquire() uses to decide a lock is
     * abandoned and free to take over, so a crashed job that never reached release() doesn't
     * keep the caller (e.g. the tray "busy" indicator) stuck forever.
     */
    public function hasActiveLocks(): bool
    {
        $this->ensureSchemaExists();

        $heartbeats = $this->connection->fetchFirstColumn('SELECT heartbeat_at FROM job_locks');

        foreach ($heartbeats as $heartbeatAt) {
            if (!$this->isHeartbeatStale((int) $heartbeatAt)) {
                return true;
            }
        }

        return false;
    }

    private function tryInsertLock(string $jobKey): bool
    {
        $now = $this->now();

        try {
            $this->connection->insert('job_locks', [
                'job_key' => $jobKey,
                'pid' => $this->currentPid(),
                'heartbeat_at' => $now,
                'started_at' => $now,
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /**
     * Takes over a lock whose owner is dead or stalled. The WHERE clause re-checks
     * heartbeat_at against the value read just before the takeover decision, so a concurrent
     * takeover attempt (or a heartbeat from a not-actually-dead owner) makes at most one of
     * the racing callers succeed.
     */
    private function tryTakeOverLock(string $jobKey, int $expectedHeartbeatAt): bool
    {
        $now = $this->now();

        $affected = $this->connection->executeStatement(
            'UPDATE job_locks SET pid = :pid, heartbeat_at = :now, started_at = :now
             WHERE job_key = :jobKey AND heartbeat_at = :expectedHeartbeatAt',
            [
                'pid' => $this->currentPid(),
                'now' => $now,
                'jobKey' => $jobKey,
                'expectedHeartbeatAt' => $expectedHeartbeatAt,
            ],
        );

        return (int) $affected === 1;
    }

    /**
     * Windows reuses PID numbers, so a process currently running under $ownerPid is only
     * guaranteed to be the lock's original owner if it started no later than the last recorded
     * heartbeat — a process cannot send a heartbeat before it exists. If it started later (or
     * doesn't exist at all), the OS has handed this PID to an unrelated process since the
     * owner's last heartbeat, and the owner must be treated as dead regardless of staleness.
     */
    private function isSameProcess(int $ownerPid, int $heartbeatAt): bool
    {
        try {
            $startedAt = $this->livenessChecker->getStartedAt($ownerPid);
        } catch (ProcessLivenessCheckException) {
            // The check itself failed — we can't tell PID reuse from a live owner here, so fall
            // back to trusting the heartbeat staleness check alone, as before this guard existed.
            return true;
        }

        return $startedAt !== null && $startedAt->getTimestamp() <= $heartbeatAt;
    }

    private function isHeartbeatStale(int $heartbeatAt): bool
    {
        return $this->now() - $heartbeatAt > $this->heartbeatIntervalSeconds * $this->staleAfterMissedHeartbeats;
    }

    private function ensureSchemaExists(): void
    {
        if ($this->schemaEnsured) {
            return;
        }

        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS job_locks (
            job_key VARCHAR(255) PRIMARY KEY NOT NULL,
            pid INTEGER NOT NULL,
            heartbeat_at INTEGER NOT NULL,
            started_at INTEGER NOT NULL
        )');

        $this->schemaEnsured = true;
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    private function currentPid(): int
    {
        $pid = getmypid();

        return $pid !== false ? $pid : 0;
    }
}
