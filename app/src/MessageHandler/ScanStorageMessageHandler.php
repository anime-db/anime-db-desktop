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
use App\Service\Notification\Kind\ScanAttentionNotificationKind;
use App\Service\Notification\Kind\ScanFailedNotificationKind;
use App\Service\Notification\NotificationCenter;
use App\Service\Plugin\PluginDisplayName;
use App\Service\Storage\Scan\ScanCandidate;
use App\Service\Storage\Scan\ScanItemType;
use App\Service\Storage\Scan\ScanResultItem;
use App\Service\Storage\Scan\ScanRunJournal;
use App\Service\Storage\Scan\ScanRunStatus;
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
 * Records every run in the scan journal ({@see ScanRunJournal}, issue #998) through DBAL, after the lock
 * is acquired and independently of the scan's own EntityManager.
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
        private readonly PluginDisplayName $pluginDisplayName,
        private readonly ScanRunJournal $journal,
        private readonly NotificationCenter $notificationCenter,
    ) {
    }

    public function __invoke(ScanStorageMessage $message): void
    {
        $jobKey = ScanStorageMessage::jobKey($message->storageId);
        $lockAcquired = false;
        $runId = null;
        $storage = null;

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
            $runId = $this->journalStart($message->storageId);
            $this->wsPublisher->publish('backend.status', ['state' => 'busy']);

            $atPath = is_readable($storage->requirePath())
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
                $this->journalFail($runId, ScanRunStatus::MarkerConflict, 'The storage desktop.ini marker is owned by another storage.');
                $this->wsPublisher->publish('scan.failed', [
                    'storage_id' => $message->storageId,
                    'reason' => 'marker_conflict',
                    'message' => 'The storage desktop.ini marker is owned by another storage.',
                ]);
                $this->notifyFailed($storage, $message->storageId, $runId, ScanFailedNotificationKind::REASON_MARKER_CONFLICT);

                return;
            }

            $items = array_map($this->serializeItem(...), $result->items);
            $this->journalDone($runId, $items);
            $this->notifyAttention($storage, $runId, $result->items);

            $this->wsPublisher->publish('scan.done', [
                'storage_id' => $message->storageId,
                'items' => $items,
            ]);

            $this->dispatchFilesAddedEvents($result->items);
        } catch (\Throwable $exception) {
            $this->journalFail($runId, ScanRunStatus::Failed, $exception->getMessage());
            $this->wsPublisher->publish('scan.failed', [
                'storage_id' => $message->storageId,
                'reason' => 'exception',
                'message' => $exception->getMessage(),
            ]);
            // A storage deleted while the message was queued is a race, not a scan failure: nothing to report.
            if ($storage !== null) {
                $this->notifyFailed($storage, $message->storageId, $runId, ScanFailedNotificationKind::REASON_EXCEPTION);
            }

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

    /*
     * The journal is a record of the scan, not a part of it: a failure to write it (full disk,
     * locked database) is logged and the scan carries on and reports its own outcome as usual. A row
     * left Running by such a failure reads as interrupted once the lock is released.
     */

    private function journalStart(int $storageId): ?int
    {
        try {
            return $this->journal->start($storageId);
        } catch (\Throwable $exception) {
            $this->logJournalFailure($exception);

            return null;
        }
    }

    /** @param list<array<string, mixed>> $items */
    private function journalDone(?int $runId, array $items): void
    {
        if ($runId === null) {
            return;
        }

        try {
            $this->journal->done($runId, $items);
        } catch (\Throwable $exception) {
            $this->logJournalFailure($exception);
        }
    }

    private function journalFail(?int $runId, ScanRunStatus $status, string $message): void
    {
        if ($runId === null) {
            return;
        }

        try {
            $this->journal->fail($runId, $status, $message);
        } catch (\Throwable $exception) {
            $this->logJournalFailure($exception);
        }
    }

    /*
     * Notifications are written after the scan's own flush and the journal, never inside the scan's
     * transaction. Neither building the draft nor recording it may change the outcome of the scan:
     * a failure here must not reach the catch (\Throwable) of __invoke() (it would publish
     * `scan.failed` for a scan that succeeded), so it is logged and swallowed.
     */

    private function notifyFailed(Storage $storage, int $storageId, ?int $runId, string $reason): void
    {
        try {
            $this->notificationCenter->record(ScanFailedNotificationKind::draft($storage->getName(), $reason, $storageId, $runId));
        } catch (\Throwable $exception) {
            $this->logNotificationFailure($exception);
        }
    }

    /**
     * `scan_attention` when the result has an item the user has to act on; Updated and AutoLinked
     * items need nothing and do not count.
     *
     * @param list<ScanResultItem> $items
     */
    private function notifyAttention(Storage $storage, ?int $runId, array $items): void
    {
        try {
            $counts = [];
            foreach ($items as $item) {
                $counts[$item->type->name] = ($counts[$item->type->name] ?? 0) + 1;
            }
            $needsConfirmation = $counts[ScanItemType::NeedsConfirmation->name] ?? 0;
            $needsManualEntry = $counts[ScanItemType::NeedsManualEntry->name] ?? 0;
            $filesMissing = $counts[ScanItemType::FilesMissing->name] ?? 0;
            $conflict = $counts[ScanItemType::Conflict->name] ?? 0;
            $error = $counts[ScanItemType::Error->name] ?? 0;
            $storageId = $storage->id ?? throw new \LogicException('A scanned storage must have an id.');
            if ($needsConfirmation + $needsManualEntry + $filesMissing + $conflict + $error === 0) {
                return;
            }

            $this->notificationCenter->record(ScanAttentionNotificationKind::draft(
                $storage->getName(),
                $needsConfirmation,
                $needsManualEntry,
                $filesMissing,
                $conflict,
                $error,
                $storageId,
                $runId,
            ));
        } catch (\Throwable $exception) {
            $this->logNotificationFailure($exception);
        }
    }

    private function logNotificationFailure(\Throwable $exception): void
    {
        $this->logger->error('Failed to record a storage scan notification; the scan itself is not affected.', ['exception' => $exception]);
    }

    private function logJournalFailure(\Throwable $exception): void
    {
        $this->logger->error('Failed to write the storage scan journal; the scan itself is not affected.', ['exception' => $exception]);
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

    /**
     * @return array{type: string, storage_path: string, cleaned_name: ?string, anime: ?array{id: ?int, title: string},
     *               candidates: list<array{anime_id: ?int, plugin_id: ?string, external_id: ?string, plugin_name: ?string, title: string}>,
     *               already_linked_storage_path: ?string, error_message: ?string}
     */
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
            'already_linked_storage_path' => $item->alreadyLinkedStoragePath,
            'error_message' => $item->errorMessage,
        ];
    }

    /**
     * $plugin_id/$external_id (issue #832) let the storage-scan confirm endpoint resolve the
     * exact same (pluginId, externalId) pair the scan itself already found, instead of a bare
     * title the server could never dedupe against the catalog by — see
     * StorageScanConfirmController, which used to fall back to a placeholder plugin id with no
     * real filler registered under it for exactly this reason.
     *
     * @return array{anime_id: ?int, plugin_id: ?string, external_id: ?string, plugin_name: ?string, title: string}
     */
    private function serializeCandidate(ScanCandidate $candidate): array
    {
        if ($candidate->orphan !== null) {
            return ['anime_id' => $candidate->orphan->id, 'plugin_id' => null, 'external_id' => null, 'plugin_name' => null, 'title' => $candidate->orphan->getTitle()];
        }

        $plugin = $candidate->plugin ?? throw new \LogicException('ScanCandidate must carry either an orphan or a plugin match');

        return [
            'anime_id' => null,
            'plugin_id' => $plugin->getPluginId(),
            'external_id' => $plugin->getExternalId(),
            'plugin_name' => $this->pluginDisplayName->name($plugin->getPluginId()),
            'title' => $plugin->getName(),
        ];
    }
}
