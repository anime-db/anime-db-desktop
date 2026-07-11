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

namespace App\MessageHandler;

use App\Entity\Storage;
use App\Message\ScanStorageMessage;
use App\Service\JobLock\JobLockService;
use App\Service\Storage\Scan\ScanCandidate;
use App\Service\Storage\Scan\ScanResultItem;
use App\Service\Storage\ScanStorageService;
use App\Service\Storage\StorageMarkerService;
use App\Service\WsPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Wraps ScanStorageService::scan() (Таск 3 часть 5) into a real background job (часть 6):
 * guards it with a job_locks entry so two workers can't scan the same storage at once, reports
 * progress and outcome over the ws_events queue, publishes backend.status busy/idle for the
 * tray icon (issue #152) — idle only once no other storage scan still holds a lock, since locks
 * are per-storage rather than global — and turns any failure of its own into an
 * UnrecoverableMessageHandlingException — this project's queue has no failure_transport (see
 * messenger.yaml), so a message Symfony would otherwise retry forever on a permanent error
 * (e.g. a storage row that no longer exists) has to opt out of retries explicitly instead
 * (same convention as every other queue handler, issue #97). Also the only caller that can ever
 * supply scan()'s $atPath (issue #162): when the storage's own path is unreadable (drive letter
 * reassigned, external drive reconnected elsewhere), it searches for the storage's desktop.ini
 * marker under every other drive root before giving up.
 */
#[AsMessageHandler]
final class ScanStorageMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly JobLockService $jobLockService,
        private readonly ScanStorageService $scanStorageService,
        private readonly StorageMarkerService $storageMarkerService,
        private readonly WsPublisher $wsPublisher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ScanStorageMessage $message): void
    {
        $jobKey = \sprintf('scan:storage:%d', $message->storageId);
        $lockAcquired = false;

        try {
            $storage = $this->entityManager->find(Storage::class, $message->storageId);
            if ($storage === null) {
                throw new \RuntimeException(\sprintf('Storage #%d not found.', $message->storageId));
            }

            if (!$this->jobLockService->acquire($jobKey)) {
                $this->logger->info('Skipping storage scan: a scan of this storage is already running.', [
                    'storage_id' => $message->storageId,
                    'job_key' => $jobKey,
                ]);

                return;
            }
            $lockAcquired = true;
            $this->wsPublisher->publish('backend.status', ['state' => 'busy']);

            $atPath = is_readable($storage->getPath())
                ? null
                : $this->storageMarkerService->findByMarker($storage->id ?? throw new \LogicException('Storage must be persisted before it can be scanned'));

            $result = $this->scanStorageService->scan(
                $storage,
                function (int $processed, int $total) use ($message, $jobKey): void {
                    $this->jobLockService->heartbeat($jobKey);
                    $this->wsPublisher->publish('scan.progress', [
                        'storage_id' => $message->storageId,
                        'processed' => $processed,
                        'total' => $total,
                        'percent' => $total > 0 ? (int) round($processed / $total * 100) : 100,
                    ]);
                },
                $atPath,
            );

            if ($result->conflicted) {
                $this->wsPublisher->publish('scan.failed', [
                    'storage_id' => $message->storageId,
                    'reason' => 'marker_conflict',
                    'message' => 'The storage desktop.ini marker is owned by another storage.',
                ]);

                return;
            }

            $this->wsPublisher->publish('scan.done', [
                'storage_id' => $message->storageId,
                'items' => array_map($this->serializeItem(...), $result->items),
            ]);
        } catch (\Throwable $exception) {
            $this->wsPublisher->publish('scan.failed', [
                'storage_id' => $message->storageId,
                'reason' => 'exception',
                'message' => $exception->getMessage(),
            ]);

            throw new UnrecoverableMessageHandlingException(\sprintf('Storage scan failed for storage #%d.', $message->storageId), previous: $exception);
        } finally {
            if ($lockAcquired) {
                $this->jobLockService->release($jobKey);

                // Scans of different storages hold independent per-storage locks, so this one
                // finishing doesn't necessarily mean the backend is idle overall.
                if (!$this->jobLockService->hasActiveLocks()) {
                    $this->wsPublisher->publish('backend.status', ['state' => 'idle']);
                }
            }
        }
    }

    /** @return array{type: string, storage_path: string, cleaned_name: ?string, anime: ?array{id: ?int, title: string}, candidates: list<array{anime_id: ?int, title: string}>} */
    private function serializeItem(ScanResultItem $item): array
    {
        return [
            'type' => $item->type->name,
            'storage_path' => $item->storagePath,
            'cleaned_name' => $item->cleanedName,
            'anime' => $item->anime !== null ? [
                'id' => $item->anime->id,
                'title' => $item->anime->getTitle(),
            ] : null,
            'candidates' => array_map($this->serializeCandidate(...), $item->candidates),
        ];
    }

    /** @return array{anime_id: ?int, title: string} */
    private function serializeCandidate(ScanCandidate $candidate): array
    {
        if ($candidate->orphan !== null) {
            return ['anime_id' => $candidate->orphan->id, 'title' => $candidate->orphan->getTitle()];
        }

        $plugin = $candidate->plugin ?? throw new \LogicException('ScanCandidate must carry either an orphan or a plugin match');

        return ['anime_id' => null, 'title' => $plugin->name];
    }
}
