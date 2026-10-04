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
use App\Entity\Enum\DownloadStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Database-only half of the "Downloads" page's row-mutating actions (issue #856): retrying a
 * Failed row and deleting a Pending/Failed one. {@see \App\Controller\DownloadActionController}
 * only calls into QbittorrentClient (torrents/start, torrents/delete) AFTER one of these methods
 * reports {@see DownloadActionOutcome::Success} — never before, and never at all on Refused or
 * Conflict.
 *
 * Both methods persist via a plain conditional SQL statement keyed on the row's optimistic-lock
 * `version` (delete() also pins `status`), the same "DB commit first, irreversible step second"
 * shape {@see DownloadUnlinkService} already uses for unlinking (issue #837):
 * DownloadCompletionPoller runs in a separate process with no locking of its own, so a caller here
 * and a poll() pass can race on the same row. A statement that matches zero rows means the row
 * changed since the caller read it — reported back as Conflict rather than silently doing nothing
 * or acting on stale data.
 *
 * Uses the Connection directly rather than EntityManager::flush(): Doctrine closes the whole
 * EntityManager after any failed flush() (an optimistic-lock conflict included, see
 * PluginDataStore's docblock for the same trade-off), which would break every other DB read the
 * same request still needs afterwards — most of all re-rendering the "Downloads" page itself.
 */
final class DownloadActionService
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** Failed => Pending, resetting failure_reason and move_attempts — see Download::retry(). */
    public function retry(Download $download): DownloadActionOutcome
    {
        $originalVersion = $download->getVersion();
        if (!$download->retry()) {
            return DownloadActionOutcome::Refused;
        }

        $downloadId = $download->id ?? throw new \LogicException('Download must be persisted before it can be retried.');
        $affected = $this->entityManager->getConnection()->executeStatement(
            'UPDATE downloads SET status = ?, failure_reason = NULL, move_attempts = 0, version = version + 1 WHERE id = ? AND version = ? AND status = ?',
            [DownloadStatus::Pending->value, $downloadId, $originalVersion, DownloadStatus::Failed->value],
        );

        if ($affected === 0) {
            $this->entityManager->refresh($download);

            return DownloadActionOutcome::Conflict;
        }

        return DownloadActionOutcome::Success;
    }

    /**
     * Removes the `downloads` row for a Pending or Failed download. Never touches qBittorrent or
     * any file on disk itself — the caller decides, once this reports Success, whether to also
     * call QbittorrentClient::delete() for this row's torrent (issue #856's "Pending"/"Failed"
     * delete action) and with what `deleteFiles` value.
     */
    public function delete(Download $download): DownloadActionOutcome
    {
        $status = $download->getStatus();
        if ($status !== DownloadStatus::Pending && $status !== DownloadStatus::Failed) {
            return DownloadActionOutcome::Refused;
        }

        $downloadId = $download->id ?? throw new \LogicException('Download must be persisted before it can be deleted.');
        $affected = $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM downloads WHERE id = ? AND version = ? AND status = ?',
            [$downloadId, $download->getVersion(), $status->value],
        );

        if ($affected === 0) {
            $this->entityManager->refresh($download);

            return DownloadActionOutcome::Conflict;
        }

        return DownloadActionOutcome::Success;
    }
}
