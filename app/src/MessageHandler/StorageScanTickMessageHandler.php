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

namespace App\MessageHandler;

use App\Entity\Storage;
use App\Message\ScanStorageMessage;
use App\Message\StorageScanTickMessage;
use App\Repository\StorageRepository;
use App\Service\JobLock\JobLockService;
use App\Service\Storage\Scan\ScanRun;
use App\Service\Storage\Scan\ScanRunJournal;
use App\Service\Storage\Scan\ScanRunStatus;
use App\Service\Storage\StorageRootInspector;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Runs on every {@see \App\Scheduler\StorageScanSchedule} tick (issue #1117) and queues a regular
 * {@see ScanStorageMessage} for at most one storage that is due. It scans nothing itself, so the
 * job lock, the scan journal, notifications and progress stay those of the ordinary scan.
 *
 * Candidates are picked without touching the filesystem: a scannable storage whose scan job lock
 * is not held and which carries no unreachable marker. They are walked from the oldest latest run
 * (storages without runs first); only then the filesystem is read, one storage at a time and up
 * to the first due one — `is_readable()` on a dead network share can hang this very process.
 *
 * A storage is due when:
 * - it has no runs;
 * - its latest run is not Done and is older than {@see self::FAILED_RETRY_SECONDS};
 * - its latest run is Done and the root mtime is newer than {@see Storage::getFileModified()}, or
 *   that is null, or the run is older than {@see self::MAX_DONE_AGE_SECONDS} (new episodes inside
 *   linked folders do not change the root mtime, and FAT/exFAT never update it).
 */
#[AsMessageHandler]
final class StorageScanTickMessageHandler
{
    public const int FAILED_RETRY_SECONDS = 24 * 60 * 60;

    public const int MAX_DONE_AGE_SECONDS = 7 * 24 * 60 * 60;

    public const int UNREACHABLE_TTL_SECONDS = 6 * 60 * 60;

    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly ScanRunJournal $journal,
        private readonly JobLockService $jobLock,
        private readonly StorageRootInspector $rootInspector,
        private readonly MessageBusInterface $messageBus,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function __invoke(StorageScanTickMessage $message): void
    {
        /** @var list<array{storage: Storage, id: int, run: ?ScanRun}> $candidates */
        $candidates = [];
        foreach ($this->storageRepository->findAllScannable() as $storage) {
            $id = $storage->id;
            if ($id === null || !$storage->isScannable()) {
                continue;
            }
            if ($this->jobLock->isLocked(ScanStorageMessage::jobKey($id))) {
                continue;
            }
            if ($this->cache->getItem(self::unreachableKey($id))->isHit()) {
                continue;
            }

            $candidates[] = ['storage' => $storage, 'id' => $id, 'run' => $this->journal->findLatest($id)];
        }

        usort($candidates, static fn (array $a, array $b): int => ($a['run']?->startedAt->getTimestamp() ?? \PHP_INT_MIN) <=> ($b['run']?->startedAt->getTimestamp() ?? \PHP_INT_MIN));

        foreach ($candidates as $candidate) {
            if ($this->isDue($candidate['storage'], $candidate['id'], $candidate['run'])) {
                $this->messageBus->dispatch(new ScanStorageMessage($candidate['id']));

                return;
            }
        }
    }

    /** PSR-6 forbids `{}()/\@:` in keys, so the key is built from a prefix and the integer id only. */
    public static function unreachableKey(int $storageId): string
    {
        return \sprintf('storage_scan_unreachable_%d', $storageId);
    }

    private function isDue(Storage $storage, int $id, ?ScanRun $run): bool
    {
        if ($run === null) {
            return true;
        }

        $age = time() - $run->startedAt->getTimestamp();

        if ($run->status !== ScanRunStatus::Done) {
            return $age > self::FAILED_RETRY_SECONDS;
        }

        $fileModified = $storage->getFileModified();
        if ($fileModified === null || $age > self::MAX_DONE_AGE_SECONDS) {
            return true;
        }

        $mtime = $this->rootInspector->modifiedAt($storage->requirePath());
        if ($mtime === null) {
            $item = $this->cache->getItem(self::unreachableKey($id));
            $item->set(true)->expiresAfter(self::UNREACHABLE_TTL_SECONDS);
            $this->cache->save($item);

            return false;
        }

        return $mtime > $fileModified->getTimestamp();
    }
}
