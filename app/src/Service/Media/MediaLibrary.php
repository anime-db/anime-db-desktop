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

namespace App\Service\Media;

use AnimeDb\PluginContracts\Media\MediaFile;
use AnimeDb\PluginContracts\Media\MediaLibraryInterface;
use AnimeDb\PluginContracts\Media\StorageUnavailableException;
use AnimeDb\PluginContracts\Model\AnimeId;
use App\Entity\Anime;
use App\Service\Storage\StorageMarkerService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lists the media files of a record's storage folder. Read-only: never repairs a moved path and
 * never starts an external process.
 *
 * A file target yields exactly that file. A folder target is walked level by level: the first
 * level holding any video/audio/subtitle file is the result, and only an empty level descends
 * into all of its subfolders (so an NCOP next to episodes in a `Season 1/` folder hides them —
 * accepted).
 */
final class MediaLibrary implements MediaLibraryInterface
{
    public const MAX_DEPTH = 5;
    public const MAX_DIRECTORIES = 200;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StorageMarkerService $markers,
        private readonly MediaHandleRegistry $registry,
    ) {
    }

    public function listFiles(AnimeId $anime): array
    {
        $record = $this->entityManager->find(Anime::class, $anime->value);
        $storage = $record?->getStorage();
        $storagePath = trim($record?->getStoragePath() ?? '');

        if ($record === null || $storage === null || $storagePath === '') {
            return [];
        }

        $rootPath = $storage->getPath();
        if ($rootPath === null) {
            throw new StorageUnavailableException('Storage has no path');
        }
        $root = is_dir($rootPath) && is_readable($rootPath) ? realpath($rootPath) : false;

        if ($root === false) {
            throw new StorageUnavailableException('Storage root is not readable');
        }

        $markerId = $this->markers->readMarkerId($rootPath);

        if ($markerId !== null && $markerId !== $storage->id) {
            throw new StorageUnavailableException('Storage root belongs to another storage');
        }

        $target = realpath($root.'/'.str_replace('\\', '/', $storagePath));

        if ($target === false || !$this->isInside($target, $root)) {
            return [];
        }

        $animeId = $record->id ?? $anime->value;

        if (is_file($target)) {
            $file = $this->buildFile($target, basename($target));

            return $file === null ? [] : $this->issue([$file->relativePath => [$file, $target]], $animeId);
        }

        if (!is_readable($target)) {
            throw new StorageUnavailableException('Record folder is not readable');
        }

        return $this->issue($this->walk($target), $animeId);
    }

    /**
     * @param array<string, array{MediaFile, string}> $found
     *
     * @return MediaFile[]
     */
    private function issue(array $found, int $animeId): array
    {
        uksort($found, strnatcasecmp(...));
        $files = [];

        foreach ($found as [$file, $path]) {
            $this->registry->register($file, $animeId, $path);
            $files[] = $file;
        }

        return $files;
    }

    /** @return array<string, array{MediaFile, string}> */
    private function walk(string $target): array
    {
        $level = [$target];
        $viewed = 0;

        for ($depth = 0; $depth <= self::MAX_DEPTH && $level !== []; ++$depth) {
            $found = [];
            $next = [];

            foreach ($level as $dir) {
                if ($viewed >= self::MAX_DIRECTORIES) {
                    break;
                }

                ++$viewed;
                $entries = @scandir($dir);

                if ($entries === false) {
                    continue;
                }

                foreach ($entries as $entry) {
                    if ($entry[0] === '.') {
                        continue;
                    }

                    $real = realpath($dir.'/'.$entry);

                    if ($real === false || !$this->isInside($real, $target)) {
                        continue;
                    }

                    if (is_dir($real)) {
                        $next[] = $real;
                    } elseif (is_file($real)) {
                        $relative = str_replace(\DIRECTORY_SEPARATOR, '/', substr($real, \strlen($target) + 1));
                        $file = $this->buildFile($real, $relative);

                        if ($file !== null) {
                            $found[$relative] = [$file, $real];
                        }
                    }
                }
            }

            if ($found !== [] || $viewed >= self::MAX_DIRECTORIES) {
                return $found;
            }

            $level = $next;
        }

        return [];
    }

    private function buildFile(string $path, string $relativePath): ?MediaFile
    {
        if (!MediaExtensions::isMedia(pathinfo($path, \PATHINFO_EXTENSION))) {
            return null;
        }

        clearstatcache(true, $path);
        $size = @filesize($path);
        $modified = @filemtime($path);

        if ($size === false || $modified === false) {
            return null;
        }

        return new MediaFile(basename($path), $relativePath, $size, (new \DateTimeImmutable())->setTimestamp($modified));
    }

    private function isInside(string $path, string $dir): bool
    {
        return str_starts_with($path, rtrim($dir, '/\\').\DIRECTORY_SEPARATOR);
    }
}
