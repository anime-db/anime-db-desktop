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

/**
 * Decides whether unlinking a Download may also clear the storage folder pointer
 * (Anime::$storage/$storagePath) it once set (issue #837). The anime's pointer may have nothing
 * to do with this download any more — a human could have repointed the anime at a different
 * folder, or split a season pack across subfolders — so it is cleared only when it still matches
 * exactly what this row's completion recorded (see Download::recordLinkedStorage()), never
 * unconditionally.
 *
 * Database-only: this never touches files on disk or the torrent in qBittorrent, and the caller
 * (DownloadsUnlinkCommand) is responsible for flushing/committing whatever this leaves dirty.
 */
final class DownloadFolderPointer
{
    /**
     * @return bool true if the anime's pointer was cleared, false if it was left as is (no
     *              snapshot was ever recorded for this row, or the anime's pointer has since
     *              moved to a different storage or path)
     */
    public function releaseIfOwnedBy(Download $download): bool
    {
        $storage = $download->getLinkedStorage();
        $storagePath = $download->getLinkedStoragePath();
        if ($storage === null || $storagePath === null) {
            return false;
        }

        $anime = $download->getAnime();
        if ($anime->getStorage()?->id !== $storage->id || $anime->getStoragePath() !== $storagePath) {
            return false;
        }

        $anime->setStorage(null)->setStoragePath(null);

        return true;
    }
}
