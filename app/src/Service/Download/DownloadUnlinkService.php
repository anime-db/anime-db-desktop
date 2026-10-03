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

namespace App\Service\Download;

use App\Entity\Download;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Removes the pairing between a torrent (infohash) and an anime entry (issue #769), shared by
 * {@see \App\Command\DownloadsUnlinkCommand} (console) and the anime page's "Unlink" button (issue
 * #857), so both go through the exact same transaction instead of two copies drifting apart. Always
 * deletes the `downloads` row. The anime's storage/storage_path pointer is cleared too, but only
 * when it still matches exactly what this pairing's completion recorded there (issue #837; see
 * {@see DownloadFolderPointer::releaseIfOwnedBy()}) — a pointer a human repointed elsewhere, or that
 * belongs to a different download, is left alone. Files on disk and the torrent in qBittorrent are
 * never touched. Once the pointer is cleared this way, the same torrent can be enqueued for
 * another anime without AnimeDownloadLinker rejecting it as a storage-path conflict.
 *
 * The delete is conditional on the row's optimistic-lock version (`DELETE ... WHERE id = ? AND
 * version = ?`): DownloadCompletionPoller runs in a separate process with no locking of its own,
 * so a caller here and a poll() pass can race on the same row. If the row changed (typically: the
 * poller just completed it) between the caller reading it and this method deleting it, the delete
 * matches zero rows and the whole transaction (including any pointer it cleared) is rolled back —
 * {@see DownloadUnlinkResult::versionConflict()} tells the caller to ask the user to retry rather
 * than silently doing nothing or acting on stale data.
 */
final class DownloadUnlinkService
{
    public function __construct(
        private readonly DownloadFolderPointer $folderPointer,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function unlink(Download $download): DownloadUnlinkResult
    {
        $downloadId = $download->id ?? throw new \LogicException('Download must be persisted before it can be unlinked.');
        $version = $download->getVersion();
        $anime = $download->getAnime();
        $released = $this->folderPointer->releaseIfOwnedBy($download);

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $this->entityManager->flush();
            $affected = $connection->executeStatement('DELETE FROM downloads WHERE id = ? AND version = ?', [$downloadId, $version]);
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }

        if ($affected === 0) {
            $connection->rollBack();
            // The rollback only undoes the DB write; $download and $anime in the identity map still
            // hold the pointer-release mutation flush() applied before the DELETE lost its race, so
            // the caller would otherwise re-render a stale status/pointer instead of what is really
            // on disk now (issue #857 review).
            $this->entityManager->refresh($download);
            $this->entityManager->refresh($anime);

            return DownloadUnlinkResult::versionConflict();
        }

        $connection->commit();

        return DownloadUnlinkResult::unlinked($released);
    }
}
