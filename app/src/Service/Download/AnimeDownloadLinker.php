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
use App\Entity\Enum\StorageType;
use App\Entity\Storage;
use App\Repository\AnimeRepository;
use App\Repository\StorageRepository;
use App\Service\Exception\DownloadStoragePathConflictException;
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
 * this raw path" mechanism: the downloads root becomes a single shared, find-or-created Storage
 * row (same as any user-added storage), and each linked Anime gets $storagePath relative to it —
 * consistent with how the file scanner already links Anime to files under a Storage.
 */
final class AnimeDownloadLinker
{
    private const string DOWNLOADS_STORAGE_NAME = 'Downloads';

    public function __construct(
        private readonly StorageRepository $storages,
        private readonly AnimeRepository $animes,
        private readonly EntityManagerInterface $entityManager,
        private readonly DownloadFolderJail $jail,
    ) {
    }

    /**
     * @throws \App\Service\Exception\DownloadPathOutsideJailException if $contentPath is not
     *                                                                 inside the configured downloads root
     * @throws DownloadStoragePathConflictException                    if another Anime already holds the same
     *                                                                 (storage, relative path) pair
     */
    public function link(Anime $anime, string $contentPath): void
    {
        $root = $this->jail->getRoot();
        $resolvedPath = $this->jail->assertWithinRoot($contentPath);

        $storage = $this->storages->findOneByPath($root) ?? $this->createDownloadsStorage($root);
        $relativePath = ltrim(substr($resolvedPath, \strlen($root)), '\\/');

        // Checked BEFORE writing: the pair is unique in the schema, and a flush() that violates it
        // closes the EntityManager. A Storage created just above has no id yet and so no Anime.
        $occupant = $storage->id !== null ? $this->animes->findByStorageAndPath($storage, $relativePath) : null;
        if ($occupant !== null && $occupant->id !== $anime->id) {
            throw new DownloadStoragePathConflictException($occupant->id ?? throw new \LogicException('Anime loaded from the database must have an id.'), $relativePath);
        }

        $anime->setStorage($storage)->setStoragePath($relativePath);
        $this->entityManager->flush();
    }

    private function createDownloadsStorage(string $root): Storage
    {
        $storage = new Storage(self::DOWNLOADS_STORAGE_NAME, $root, StorageType::Folder);
        $this->entityManager->persist($storage);

        return $storage;
    }
}
