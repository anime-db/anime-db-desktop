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
use App\Repository\AnimeRepository;
use App\Service\Exception\DownloadStoragePathConflictException;
use App\Service\Exception\DownloadTargetStorageMissingException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Links a completed download's folder to the catalog entry it belongs to — a CORE
 * responsibility (issue #346), not a plugin one: business processes elsewhere (the file
 * scanner, the entry page's "open folder" action) already key off Anime::$storage/$storagePath,
 * so a download completing has to populate the exact same fields, not a parallel one.
 *
 * Not to be confused with the (separate, pre-release) item-folder-filler plugin, which dumps
 * metadata FROM an already-linked folder into the card — this class only performs the link
 * itself, before any plugin ever sees the download.
 *
 * Reuses the existing Storage abstraction rather than inventing a second "this anime lives at
 * this raw path" mechanism: $download->getTargetStorage() (issue #851 — the Storage it was
 * enqueued into, set by QbittorrentDownloadService::enqueueTo() before the row was ever written)
 * IS the Storage the anime is linked to, and $storagePath is computed relative to it —
 * consistent with how the file scanner already links Anime to files under a Storage.
 */
final class AnimeDownloadLinker
{
    public function __construct(
        private readonly AnimeRepository $animes,
        private readonly EntityManagerInterface $entityManager,
        private readonly DownloadFolderJail $jail,
    ) {
    }

    /**
     * Links $download's anime to $contentPath AND records the same (storage, relative path) pair
     * onto $download itself (see Download::recordLinkedStorage()) in one flush() — so a Completed
     * row never exists in the database without the snapshot a later unlink needs (issue #837).
     *
     * @throws \App\Service\Exception\DownloadPathOutsideJailException if $contentPath is not
     *                                                                 inside $download's target storage
     * @throws DownloadStoragePathConflictException                    if another Anime already holds the same
     *                                                                 (storage, relative path) pair
     * @throws DownloadTargetStorageMissingException                   if $download's target storage was
     *                                                                 deleted while it was still in flight
     */
    public function link(Download $download, string $contentPath): void
    {
        $anime = $download->getAnime();
        $storage = $download->getTargetStorage() ?? throw new DownloadTargetStorageMissingException($download->getInfoHash());
        $root = $storage->getPath();
        $resolvedPath = $this->jail->assertWithinRoot($root, $contentPath);

        $relativePath = $this->jail->relativePathUnderRoot($root, $resolvedPath);

        $occupant = $this->animes->findByStorageAndPath($storage, $relativePath);
        if ($occupant !== null && $occupant->id !== $anime->id) {
            throw new DownloadStoragePathConflictException($occupant->id ?? throw new \LogicException('Anime loaded from the database must have an id.'), $relativePath);
        }

        $anime->setStorage($storage)->setStoragePath($relativePath);
        $download->recordLinkedStorage($storage, $relativePath);
        $this->entityManager->flush();
    }
}
