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

namespace App\Service;

use App\Entity\Anime;
use App\Entity\Enum\DownloadStatus;
use App\Message\RemoveFromSourceMessage;
use App\Message\SyncSeedMessage;
use App\Repository\DownloadRepository;
use App\Repository\SyncTombstoneRepository;
use App\Service\JobLock\JobLockService;
use App\Service\Plugin\SyncRegistry;
use App\Service\Qbittorrent\QbittorrentClient;
use App\Service\Sync\SourceRemovalPlanner;
use App\Service\Sync\SyncReviewService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Local deletion of a catalog entry (issue #916), the one place both the entry page and the "requires
 * attention" page go through. Only the catalog record goes: video files and folders in a storage are
 * never touched.
 *
 * Refused, with nothing changed, while a download of the entry is Pending (its torrent would keep
 * downloading with no entry to belong to) or while an active sync plugin holds its
 * {@see SyncSeedMessage::jobKey()} lock (a pull loads its index up front and would write sync state
 * for the deleted row, or flag it as removed from the source).
 *
 * In one transaction a {@see \App\Entity\SyncTombstone} is written per cached external id and the
 * entry is removed (the rest of its rows go by ON DELETE CASCADE), so a later pull or storage scan
 * does not create the title again. The tombstone is written here and not by a Doctrine listener:
 * {@see AnimeTypeMigrator} removes entries too, and a type change must leave no tombstone.
 *
 * With $removeFromSources the entry is also deleted from the user's list on the sources
 * ({@see SourceRemovalPlanner}, issue #918): the tombstone of each target source is written with the
 * pending flag, and after the commit a {@see RemoveFromSourceMessage} per target is queued. The
 * targets are counted at this moment, not taken from the caller. Without it the tombstones carry no
 * flag and stay for good. A message that is lost does not lose the removal: the flag is in the
 * database and the catch-up before the plugin's next pull ({@see Sync\SourceRemovalService}) finds it.
 *
 * After the commit, best-effort (a failure is logged and never undoes the deletion): the torrents of
 * Completed/Failed downloads are removed from qBittorrent *without* their files, the media directory
 * is deleted and the unresolved review items pointing at the entry are tidied.
 */
final class AnimeDeleteService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DownloadRepository $downloads,
        private readonly SyncRegistry $syncRegistry,
        private readonly JobLockService $jobLockService,
        private readonly SyncTombstoneRepository $tombstones,
        private readonly SourceRemovalPlanner $removalPlanner,
        private readonly MessageBusInterface $bus,
        private readonly SyncReviewService $syncReview,
        private readonly QbittorrentClient $qbittorrent,
        private readonly LoggerInterface $logger,
        private readonly string $mediaDir,
    ) {
    }

    /**
     * @param list<string> $excludeSourcePluginIds sources the entry is already gone from, never targeted
     */
    public function delete(Anime $anime, bool $removeFromSources = false, array $excludeSourcePluginIds = []): AnimeDeleteOutcome
    {
        $animeId = $anime->id ?? throw new \LogicException('Anime must be persisted before it can be deleted.');

        $torrentHashes = [];
        $animeDownloads = $this->downloads->findByAnime($animeId);
        foreach ($animeDownloads as $download) {
            if ($download->getStatus() === DownloadStatus::Pending) {
                return AnimeDeleteOutcome::PendingDownloads;
            }

            $torrentHashes[] = $download->getInfoHash();
        }

        if ($this->isSyncRunning()) {
            return AnimeDeleteOutcome::SyncRunning;
        }

        $externalIds = [];
        foreach ($anime->getExternalIdPluginIds() as $pluginId) {
            $externalId = $anime->getCachedExternalId($pluginId);
            if ($externalId !== null) {
                $externalIds[(string) $pluginId] = $externalId;
            }
        }

        $targets = $removeFromSources ? $this->removalPlanner->plan($anime, $excludeSourcePluginIds)->targets : [];

        $this->entityManager->wrapInTransaction(function () use ($anime, $externalIds, $animeDownloads, $targets): void {
            $deletedAt = new \DateTimeImmutable();
            foreach ($externalIds as $pluginId => $externalId) {
                $this->tombstones->record($pluginId, $externalId, $deletedAt, isset($targets[$pluginId]));
            }

            // The rows go by ON DELETE CASCADE, but the managed objects must not outlive the entry:
            // a later flush would find a removed Anime through Download#anime and throw.
            foreach ($animeDownloads as $download) {
                $this->entityManager->remove($download);
            }
            $this->entityManager->remove($anime);
            $this->entityManager->flush();
        });

        $this->queueSourceRemovals($targets);
        $this->removeTorrents($torrentHashes);
        $this->removeMediaDirectory($animeId);
        $this->tidyReviewItems($animeId);

        return AnimeDeleteOutcome::Deleted;
    }

    private function isSyncRunning(): bool
    {
        foreach ($this->syncRegistry->allActive() as $pluginId => $_) {
            if ($this->jobLockService->isLocked(SyncSeedMessage::jobKey((string) $pluginId))) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string> $targets plugin id => external id */
    private function queueSourceRemovals(array $targets): void
    {
        foreach ($targets as $pluginId => $externalId) {
            try {
                $this->bus->dispatch(new RemoveFromSourceMessage((string) $pluginId, $externalId));
            } catch (\Throwable $exception) {
                $this->logger->warning('The entry was deleted, but the removal from the source could not be queued; it is retried before the next sync of the plugin.', [
                    'pluginId' => (string) $pluginId,
                    'externalId' => $externalId,
                    'exception' => $exception,
                ]);
            }
        }
    }

    /** @param list<string> $hashes */
    private function removeTorrents(array $hashes): void
    {
        foreach ($hashes as $hash) {
            try {
                $this->qbittorrent->delete($hash, false);
            } catch (\Throwable $exception) {
                $this->logger->warning('The entry was deleted, but its torrent could not be removed from the download client.', [
                    'infoHash' => $hash,
                    'exception' => $exception,
                ]);
            }
        }
    }

    private function removeMediaDirectory(int $animeId): void
    {
        $directory = rtrim($this->mediaDir, '/\\').'/'.$animeId;

        try {
            (new Filesystem())->remove($directory);
        } catch (\Throwable $exception) {
            $this->logger->warning('The entry was deleted, but its media directory could not be removed.', [
                'directory' => $directory,
                'exception' => $exception,
            ]);
        }
    }

    private function tidyReviewItems(int $animeId): void
    {
        try {
            $this->syncReview->forgetAnime($animeId);
        } catch (\Throwable $exception) {
            $this->logger->warning('The entry was deleted, but the review items pointing at it could not be tidied.', [
                'animeId' => $animeId,
                'exception' => $exception,
            ]);
        }
    }
}
