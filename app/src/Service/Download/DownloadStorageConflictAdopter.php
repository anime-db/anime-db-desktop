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

use App\Entity\Anime;
use App\Entity\Download;
use App\Entity\Enum\DownloadStatus;
use App\Repository\AnimeRepository;

/**
 * "Link to entry" for a Failed("storage_conflict") row: the same checks as {@see DownloadOrphanAdopter}
 * on the torrent's live `content_path`, plus "the storage the classifier chose is the row's own
 * target storage" ({@see DownloadCompletionPoller} computes the path relative to it). Then the
 * row goes to the chosen entry and back to Pending via {@see DownloadActionService::relink()};
 * the poller links the folder on its next pass. The torrent is never touched: only
 * `torrents/info` is read, nothing is started, moved or deleted.
 *
 * Every check runs before the single write, so a refusal leaves the database as it was.
 */
class DownloadStorageConflictAdopter
{
    public function __construct(
        private readonly DownloadOrphanAdopter $adopter,
        private readonly AnimeRepository $animes,
        private readonly DownloadActionService $actions,
    ) {
    }

    /**
     * The entry that owns the row's folder right now — the usual choice for the picker. Null when
     * the folder is free, or the classifier or the storage check refuses.
     */
    public function findFolderOwner(Download $download): ?Anime
    {
        $targetStorage = $download->getTargetStorage();
        if ($targetStorage === null) {
            return null;
        }

        try {
            [$plan, $storage] = $this->adopter->resolvePlan($download->getInfoHash());
        } catch (DownloadAdoptionRefusedException) {
            return null;
        }
        if ($storage->id !== $targetStorage->id) {
            return null;
        }

        return $this->animes->findByStorageAndPath($targetStorage, $plan->name);
    }

    /**
     * @throws DownloadAdoptionRefusedException
     */
    public function adopt(Download $download, Anime $anime, int $expectedVersion, DownloadStatus $expectedStatus): DownloadActionOutcome
    {
        // A repeated submit (double click): the first one already relinked the row to this entry.
        if ($download->getStatus() === DownloadStatus::Pending && $download->getAnime()->id === $anime->id) {
            return DownloadActionOutcome::Success;
        }

        $targetStorage = $download->getTargetStorage();
        if (!$download->hasStorageConflict() || $targetStorage === null) {
            return DownloadActionOutcome::Refused;
        }

        [$plan, $storage] = $this->adopter->resolvePlan($download->getInfoHash());
        if ($storage->id !== $targetStorage->id) {
            throw new DownloadAdoptionRefusedException('download_adopt.error_storage_mismatch', ['%storage%' => $targetStorage->getName()]);
        }

        $this->adopter->assertFolderAvailable($anime, $storage, $plan);

        return $this->actions->relink($download, $anime, $expectedVersion, $expectedStatus);
    }
}
