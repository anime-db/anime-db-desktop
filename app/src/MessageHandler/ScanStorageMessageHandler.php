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

use AnimeDb\PluginContracts\Catalog\AnimeFilesChangedEvent;
use AnimeDb\PluginContracts\Catalog\FilesChangeReason;
use AnimeDb\PluginContracts\Model\AnimeId;
use App\Entity\Storage;
use App\Message\ScanStorageMessage;
use App\Service\JobLock\JobLockService;
use App\Service\Storage\Scan\ScanCandidate;
use App\Service\Storage\Scan\ScanItemType;
use App\Service\Storage\Scan\ScanResultItem;
use App\Service\Storage\ScanStorageService;
use App\Service\Storage\StorageMarkerService;
use App\Service\WsPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

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
 *
 * Also dispatches {@see AnimeFilesChangedEvent} for every Updated/AutoLinked item in the scan
 * result — see {@see self::dispatchFilesAddedEvents()}.
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
        private readonly EventDispatcherInterface $eventDispatcher,
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
                : $this->storageMarkerService->findByMarker($storage);

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

            $this->dispatchFilesAddedEvents($result->items);
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

    /**
     * Dispatches {@see AnimeFilesChangedEvent} for every ScanItemType::Updated / ::AutoLinked item
     * ScanStorageService::scan() already flushed (issue #703/#684, часть 3) — scan()'s own logic is
     * not touched; this only reads its already-committed result, from this worker, after flush(),
     * exactly as the contract requires.
     *
     * ::Updated (an already-linked file's mtime moved forward) gets {@see FilesChangeReason::FilesAdded}.
     * ::AutoLinked carries the same file-to-anime link as StorageScanConfirmController's manual
     * candidate choice — matchNewEntry() calls the very same ScanStorageService::linkToChosenCandidate()
     * when exactly one candidate is found automatically — so it gets the matching
     * {@see FilesChangeReason::PathChanged}.
     *
     * Called after `scan.done` is published, not before: plugin subscribers of this event are
     * registered through Symfony's regular `_instanceof` autoconfig (see {@see \App\Service\Plugin\DependencyInjection\Compiler\TagPluginServicesPass})
     * and are not wrapped by the host the way {@see ScanStorageService::fillFromPlugin()}
     * guards a plugin call during scan() itself (issue #233) — so each dispatch is individually
     * try/caught here too. A scan that already committed successfully must not turn into
     * `scan.failed` (and the handler must not retry the whole message) just because one plugin's
     * listener threw.
     *
     * @param list<ScanResultItem> $items
     */
    private function dispatchFilesAddedEvents(array $items): void
    {
        foreach ($items as $item) {
            $reason = match ($item->type) {
                ScanItemType::Updated => FilesChangeReason::FilesAdded,
                ScanItemType::AutoLinked => FilesChangeReason::PathChanged,
                default => null,
            };

            if ($reason === null) {
                continue;
            }

            $anime = $item->anime ?? throw new \LogicException('ScanResultItem::updated()/autoLinked() must always carry an Anime.');
            $animeId = $anime->id ?? throw new \LogicException('Anime must have an id once linked to storage.');

            try {
                $this->eventDispatcher->dispatch(new AnimeFilesChangedEvent(new AnimeId($animeId), $reason));
            } catch (\Throwable $exception) {
                $this->logger->error('A plugin subscriber of AnimeFilesChangedEvent failed; the scan itself already succeeded and is not affected.', [
                    'anime_id' => $animeId,
                    'reason' => $reason->name,
                    'exception' => $exception,
                ]);
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

        return ['anime_id' => null, 'title' => $plugin->getName()];
    }
}
