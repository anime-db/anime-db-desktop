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

namespace App\Service\Storage\Scan;

use App\Message\ScanStorageMessage;
use App\Service\JobLock\JobLockService;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * The storage scan journal (issue #998): the last {@see self::KEEP_RUNS} runs of every storage.
 *
 * Written through DBAL and not through the scan's EntityManager: that one is closed by Doctrine
 * after any failed flush, and a journal on it would turn a finished scan into `scan.failed` and
 * could not record the failure either.
 *
 * Stored items are the `scan.done` items with a format version ("v") on each; Updated items are
 * not stored — they mean "all is well" and are thousands on a big storage, only their count is
 * kept. `interrupted` is never stored: a Running row without a live job lock reads as Interrupted.
 */
final class ScanRunJournal
{
    public const int KEEP_RUNS = 10;

    public const int ITEM_FORMAT_VERSION = 1;

    public function __construct(
        private readonly Connection $connection,
        private readonly JobLockService $jobLock,
        private readonly ClockInterface $clock,
    ) {
    }

    /** Opens a Running row and drops the runs of the storage beyond the last {@see self::KEEP_RUNS}. */
    public function start(int $storageId): int
    {
        $this->connection->insert('scan_run', [
            'storage_id' => $storageId,
            'started_at' => $this->clock->now()->getTimestamp(),
            'status' => ScanRunStatus::Running->value,
            'counts' => '{}',
            'items' => '[]',
        ]);
        $runId = (int) $this->connection->lastInsertId();

        $this->connection->executeStatement(
            'DELETE FROM scan_run WHERE storage_id = :storageId AND id NOT IN (
                SELECT id FROM scan_run WHERE storage_id = :storageId ORDER BY id DESC LIMIT '.self::KEEP_RUNS.'
            )',
            ['storageId' => $storageId],
        );

        return $runId;
    }

    /** @param list<array<string, mixed>> $items the serialized `scan.done` items */
    public function done(int $runId, array $items): void
    {
        $counts = [];
        $stored = [];
        foreach ($items as $item) {
            $type = \is_string($item['type'] ?? null) ? $item['type'] : '';
            $counts[$type] = ($counts[$type] ?? 0) + 1;

            if ($type !== ScanItemType::Updated->name) {
                $stored[] = ['v' => self::ITEM_FORMAT_VERSION] + $item;
            }
        }

        $this->finish($runId, ScanRunStatus::Done, null, $counts, $stored);
    }

    public function fail(int $runId, ScanRunStatus $status, string $message): void
    {
        if (!\in_array($status, [ScanRunStatus::Failed, ScanRunStatus::MarkerConflict], true)) {
            throw new \InvalidArgumentException('Only a failed or marker_conflict outcome can be recorded as a failure.');
        }

        $this->finish($runId, $status, $message, [], []);
    }

    /** The newest run of the storage, with its items. */
    public function findLatest(int $storageId): ?ScanRun
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM scan_run WHERE storage_id = :storageId ORDER BY id DESC LIMIT 1',
            ['storageId' => $storageId],
        );

        return $row === false ? null : $this->hydrate($row, isLatest: true, withItems: true);
    }

    /** The run with its items, or null when it does not exist or belongs to another storage. */
    public function find(int $storageId, int $runId): ?ScanRun
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM scan_run WHERE storage_id = :storageId AND id = :runId',
            ['storageId' => $storageId, 'runId' => $runId],
        );
        if ($row === false) {
            return null;
        }

        return $this->hydrate($row, isLatest: $this->isLatest($storageId, $runId), withItems: true);
    }

    public function isLatest(int $storageId, int $runId): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT MAX(id) FROM scan_run WHERE storage_id = :storageId',
            ['storageId' => $storageId],
        ) === $runId;
    }

    /**
     * Newest first, without items.
     *
     * @return list<ScanRun>
     */
    public function findRecent(int $storageId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, storage_id, started_at, finished_at, status, error_message, counts FROM scan_run
             WHERE storage_id = :storageId ORDER BY id DESC',
            ['storageId' => $storageId],
        );

        $runs = [];
        foreach ($rows as $index => $row) {
            $runs[] = $this->hydrate($row, isLatest: $index === 0, withItems: false);
        }

        return $runs;
    }

    /**
     * @param array<string, int>         $counts
     * @param list<array<string, mixed>> $items
     */
    private function finish(int $runId, ScanRunStatus $status, ?string $message, array $counts, array $items): void
    {
        $this->connection->update('scan_run', [
            'finished_at' => $this->clock->now()->getTimestamp(),
            'status' => $status->value,
            'error_message' => $message,
            'counts' => json_encode($counts, \JSON_THROW_ON_ERROR | \JSON_FORCE_OBJECT),
            'items' => json_encode($items, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE),
        ], ['id' => $runId]);
    }

    /**
     * A Running row is live only if it is the newest of its storage (a later run could only start
     * after this one lost its lock) and the storage's scan lock is held.
     *
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row, bool $isLatest, bool $withItems): ScanRun
    {
        $storageId = (int) $row['storage_id'];
        $status = ScanRunStatus::from((string) $row['status']);
        if ($status === ScanRunStatus::Running && !($isLatest && $this->jobLock->isLocked(ScanStorageMessage::jobKey($storageId)))) {
            $status = ScanRunStatus::Interrupted;
        }

        $counts = [];
        foreach ($this->decode($row['counts'] ?? null) as $type => $count) {
            if (\is_int($count)) {
                $counts[(string) $type] = $count;
            }
        }

        $items = [];
        if ($withItems) {
            foreach ($this->decode($row['items'] ?? null) as $item) {
                if (\is_array($item)) {
                    $items[] = $item;
                }
            }
        }

        return new ScanRun(
            (int) $row['id'],
            $storageId,
            $this->at((int) $row['started_at']),
            $row['finished_at'] === null ? null : $this->at((int) $row['finished_at']),
            $status,
            $row['error_message'] === null ? null : (string) $row['error_message'],
            $counts,
            $items,
        );
    }

    /** @return array<mixed> */
    private function decode(mixed $json): array
    {
        $data = \is_string($json) ? json_decode($json, true) : null;

        return \is_array($data) ? $data : [];
    }

    private function at(int $timestamp): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@'.$timestamp))->setTimezone($this->clock->now()->getTimezone());
    }
}
