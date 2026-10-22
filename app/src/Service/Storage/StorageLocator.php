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

namespace App\Service\Storage;

use App\Entity\Enum\StorageType;
use App\Entity\Storage;
use App\Repository\StorageRepository;
use App\Service\Path\LexicalPathNormalizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Read-only answer to "which storage does this path belong to" (issues #997, #1121), shared by
 * {@see ManualLinkService} and the open-in-app resolver so both answer the same way. First it
 * climbs from the path looking for a storage marker; only without one it takes the storage with
 * the longest root containing the path. It tells a marker found at the root stored in the database
 * from a marker found elsewhere ({@see StorageLocation::$relocated}, e.g. the drive letter changed).
 * It reads markers but never writes to the database or the filesystem.
 */
final class StorageLocator
{
    private const array LINKABLE_TYPES = [StorageType::Folder, StorageType::External, StorageType::ExternalR];

    public function __construct(
        private readonly StorageRepository $storages,
        private readonly StorageMarkerService $markerService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function locate(string $selectedPath): StorageLocation
    {
        $selectedPath = trim($selectedPath);
        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|\\\\\\\\|\/)/', $selectedPath) !== 1) {
            return StorageLocation::invalidPath();
        }

        // The marker climb works on the raw string, so a path that could leave a directory sideways is not accepted.
        if (preg_grep('/^\.\.?$/', preg_split('/[\\\\\/]+/', $selectedPath) ?: []) !== []) {
            return StorageLocation::invalidPath();
        }

        $path = LexicalPathNormalizer::normalize($selectedPath);
        if ($path === '') {
            return StorageLocation::invalidPath();
        }

        $marked = $this->findStorageByMarker($selectedPath);
        if ($marked !== null) {
            [$storage, $rootPath] = $marked;

            return StorageLocation::found($storage, $rootPath, !$this->isSamePath($storage->requirePath(), $rootPath), $path);
        }

        $storage = $this->findStorageByPath($path);
        if ($storage === null) {
            return StorageLocation::outside($this->parentPath($selectedPath) ?? $selectedPath);
        }

        return StorageLocation::found($storage, $storage->requirePath(), false, $path);
    }

    /**
     * The marker directory the way {@see Storage::relocate()} accepts it: backslash separators and
     * resolved segments, with the root shape (drive, UNC share, POSIX root) kept, which normalize()
     * strips.
     */
    public function absoluteRoot(string $path): string
    {
        $normalized = LexicalPathNormalizer::normalize($path);

        if (preg_match('/^[A-Za-z]:$/', $normalized) === 1) {
            return $normalized.'\\';
        }
        if (preg_match('/^(?:\\\\|\/\/)/', $path) === 1) {
            return '\\\\'.$normalized;
        }
        if (str_starts_with($path, '/')) {
            return '/'.str_replace('\\', '/', $normalized);
        }

        return $normalized;
    }

    /**
     * Walks up from $selectedPath looking for the first marker that names a storage existing in
     * the database; a marker with an unknown id (deleted storage, foreign installation) is skipped.
     *
     * @return array{Storage, string}|null the storage and the directory its marker was found in
     */
    private function findStorageByMarker(string $selectedPath): ?array
    {
        for ($dir = $selectedPath; $dir !== null; $dir = $this->parentPath($dir)) {
            $markerId = $this->markerService->readMarkerId($dir);
            if ($markerId === null) {
                continue;
            }

            $storage = $this->entityManager->find(Storage::class, $markerId);
            if ($storage instanceof Storage && \in_array($storage->getType(), self::LINKABLE_TYPES, true) && $storage->getPath() !== null) {
                return [$storage, $dir];
            }
        }

        return null;
    }

    /** The storage with the longest root containing $normalizedPath (storage paths are not unique, storages can nest). */
    private function findStorageByPath(string $normalizedPath): ?Storage
    {
        $best = null;
        $bestLength = -1;
        foreach ($this->storages->findAllOrderedByName() as $storage) {
            $root = $storage->getPath();
            if ($root === null || !\in_array($storage->getType(), self::LINKABLE_TYPES, true)) {
                continue;
            }

            $normalizedRoot = LexicalPathNormalizer::normalize($root);
            if ($normalizedRoot !== '' && LexicalPathNormalizer::isWithin($normalizedRoot, $normalizedPath) && \strlen($normalizedRoot) > $bestLength) {
                $best = $storage;
                $bestLength = \strlen($normalizedRoot);
            }
        }

        return $best;
    }

    private function isSamePath(string $a, string $b): bool
    {
        return mb_strtolower(LexicalPathNormalizer::normalize($a)) === mb_strtolower(LexicalPathNormalizer::normalize($b));
    }

    /** The containing directory of $path, or null once the drive/share root is reached. */
    private function parentPath(string $path): ?string
    {
        $trimmed = rtrim($path, '\\/');
        $cut = max((int) strrpos($trimmed, '\\'), (int) strrpos($trimmed, '/'));
        $parent = substr($trimmed, 0, $cut);

        if (preg_match('/^[A-Za-z]:$/', $parent) === 1) {
            return $parent.'\\';
        }

        // What is left has no separator of its own: a bare UNC host, or nothing at all.
        $rest = ltrim($parent, '\\/');
        if ($rest === '' || strpbrk($rest, '\\/') === false) {
            return null;
        }

        return $parent;
    }
}
