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

namespace App\Service\Folder;

use AnimeDb\PluginContracts\Folder\ForeignFileException;
use AnimeDb\PluginContracts\Folder\RecordFolderFileState;
use AnimeDb\PluginContracts\Folder\RecordFolderWriterInterface;
use AnimeDb\PluginContracts\Folder\RecordHasNoFolderException;
use AnimeDb\PluginContracts\Folder\StorageNotWritableException;
use AnimeDb\PluginContracts\Media\StorageUnavailableException;
use AnimeDb\PluginContracts\Model\AnimeId;
use App\Entity\Anime;
use App\Entity\RecordFolderFile;
use App\Entity\ValueObject\PluginId;
use App\Service\Storage\StorageMarkerService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Writes files a plugin hands over into the folder of a catalog record (issue #1065), scoped to
 * that plugin's own {@see PluginId} — see {@see \App\Service\Plugin\DependencyInjection\Compiler\RecordFolderWriterScopePass},
 * the only place a plugin obtains one, and only a `local` plugin at that.
 *
 * Only finding the record's folder is shared with {@see \App\Service\Media\MediaLibrary}; the
 * checks for writing are stricter and live here: the storage must be writable, its marker must
 * name exactly this storage (a missing marker is not enough), and the resolved folder must lie
 * inside the storage root. A record whose path is a single file has no folder of its own and
 * nothing is written next to it.
 *
 * The plugin passes a bare file name and bytes and never sees an absolute path. A file is written
 * through a temporary file and a rename in the same folder. "Not ours" is decided by sha256 of the
 * content against the ledger ({@see RecordFolderFile}), never by size or mtime: an existing file
 * is replaced when it matches what this plugin recorded or already equals the new bytes, otherwise
 * {@see ForeignFileException}; `$overwrite` skips that check.
 */
final class RecordFolderWriter implements RecordFolderWriterInterface
{
    public const int MAX_BYTES = 5 * 1024 * 1024;

    private const array EXTENSIONS = ['html', 'jpg', 'jpeg', 'png', 'webp'];

    private const string NAME_MASK = '/\A[a-z0-9][a-z0-9._-]*\z/';

    private const string RESERVED_NAMES = '/\A(?:con|prn|aux|nul|com[1-9]|lpt[1-9])\z/';

    public function __construct(
        private readonly PluginId $pluginId,
        private readonly ManagerRegistry $registry,
        private readonly StorageMarkerService $markers,
        private readonly string $mediaDir,
    ) {
    }

    public function write(AnimeId $anime, string $fileName, string $contents, bool $overwrite = false): void
    {
        self::assertFileName($fileName);
        if (\strlen($contents) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('The contents exceed 5 MB.');
        }

        $this->store($anime, $this->resolveFolder($anime), $fileName, $contents, $overwrite);
    }

    public function writeCover(AnimeId $anime, string $baseName, bool $overwrite = false): ?string
    {
        self::assertFileName($baseName.'.jpg');

        $folder = $this->resolveFolder($anime);
        $cover = $this->readCover($anime);
        if ($cover === null) {
            return null;
        }

        [$bytes, $extension] = $cover;
        $fileName = $baseName.'.'.$extension;
        self::assertFileName($fileName);

        $this->store($anime, $folder, $fileName, $bytes, $overwrite);

        return $fileName;
    }

    public function inspect(AnimeId $anime, string $fileName): RecordFolderFileState
    {
        self::assertFileName($fileName);

        $path = $this->resolveFolder($anime).'/'.$fileName;
        if (!is_file($path)) {
            return RecordFolderFileState::Absent;
        }

        $recorded = $this->findRow($this->entityManager(), $anime->value, $fileName)?->sha256;

        return $recorded !== null && $recorded === @hash_file('sha256', $path)
            ? RecordFolderFileState::Written
            : RecordFolderFileState::Modified;
    }

    /** @throws \InvalidArgumentException */
    private static function assertFileName(string $fileName): void
    {
        $stem = strstr($fileName, '.', true);
        $extension = strtolower(pathinfo($fileName, \PATHINFO_EXTENSION));

        if (
            \strlen($fileName) > 255
            || preg_match(self::NAME_MASK, $fileName) !== 1
            || str_contains($fileName, '..')
            || str_ends_with($fileName, '.')
            || $stem === false
            || preg_match(self::RESERVED_NAMES, $stem) === 1
            || !\in_array($extension, self::EXTENSIONS, true)
        ) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an allowed file name.', $fileName));
        }
    }

    /**
     * Absolute path of the record's own folder, only once every check for writing passed.
     *
     * @throws RecordHasNoFolderException
     * @throws StorageNotWritableException
     * @throws StorageUnavailableException
     */
    private function resolveFolder(AnimeId $anime): string
    {
        $record = $this->entityManager()->find(Anime::class, $anime->value);
        $storage = $record?->getStorage();
        $storagePath = trim($record?->getStoragePath() ?? '');

        if ($record === null || $storage === null || $storagePath === '') {
            throw new RecordHasNoFolderException('The record is not linked to a folder.');
        }

        if (!$storage->getType()->isWritable()) {
            throw new StorageNotWritableException('The storage type does not allow writing.');
        }

        $rootPath = $storage->getPath();
        $root = $rootPath !== null && is_dir($rootPath) ? realpath($rootPath) : false;
        if ($root === false) {
            throw new StorageUnavailableException('Storage root is not available');
        }

        // Strictly `=== id`, a missing marker is refused too (as in DownloadIncomingRelocator).
        if ($storage->id === null || $this->markers->readMarkerId($rootPath) !== $storage->id) {
            throw new StorageNotWritableException('The storage marker does not match the storage.');
        }

        $target = realpath($root.'/'.str_replace('\\', '/', $storagePath));
        if ($target === false) {
            throw new RecordHasNoFolderException('The record folder does not exist.');
        }
        if (!str_starts_with($target, rtrim($root, '/\\').\DIRECTORY_SEPARATOR)) {
            throw new StorageNotWritableException('The record folder is outside the storage root.');
        }
        if (!is_dir($target)) {
            throw new RecordHasNoFolderException('The record is a single file, it has no folder of its own.');
        }
        if (!is_writable($target)) {
            throw new StorageNotWritableException('The record folder is not writable.');
        }

        return $target;
    }

    /** @return array{string, string}|null cover bytes and the extension matching their real format */
    private function readCover(AnimeId $anime): ?array
    {
        $name = $this->entityManager()->find(Anime::class, $anime->value)?->getCover();
        if ($name === null || $name === '') {
            return null;
        }

        $directory = realpath(rtrim($this->mediaDir, '/\\').'/'.$anime->value);
        $path = $directory === false ? false : realpath($directory.'/'.$name);
        if ($path === false || !str_starts_with($path, $directory.\DIRECTORY_SEPARATOR) || !is_file($path)) {
            return null;
        }

        $size = @filesize($path);
        $bytes = $size !== false && $size <= self::MAX_BYTES ? @file_get_contents($path) : false;
        if ($bytes === false || \strlen($bytes) > self::MAX_BYTES) {
            return null;
        }

        $info = @getimagesizefromstring($bytes);
        $extension = match ($info === false ? null : $info[2]) {
            \IMAGETYPE_JPEG => 'jpg',
            \IMAGETYPE_PNG => 'png',
            \IMAGETYPE_WEBP => 'webp',
            default => null,
        };

        return $extension === null ? null : [$bytes, $extension];
    }

    /**
     * @throws ForeignFileException
     * @throws StorageNotWritableException
     */
    private function store(AnimeId $anime, string $folder, string $fileName, string $contents, bool $overwrite): void
    {
        $path = $folder.'/'.$fileName;
        $newHash = hash('sha256', $contents);
        $entityManager = $this->entityManager();
        $row = $this->findRow($entityManager, $anime->value, $fileName);

        if (is_dir($path)) {
            throw new ForeignFileException(\sprintf('"%s" is a folder.', $fileName));
        }
        if (!$overwrite && file_exists($path)) {
            $current = is_link($path) ? false : @hash_file('sha256', $path);
            if ($current === false || ($current !== $row?->sha256 && $current !== $newHash)) {
                throw new ForeignFileException(\sprintf('"%s" already exists with other content.', $fileName));
            }
        }

        $this->writeAtomically($folder, $path, $contents);

        $now = new \DateTimeImmutable();
        if ($row === null) {
            $reference = $entityManager->getReference(Anime::class, $anime->value) ?? throw new \LogicException('No anime reference.');
            $entityManager->persist(new RecordFolderFile($reference, $this->pluginId, $fileName, $newHash, $now));
        } else {
            $row->record($newHash, $now);
        }
        $entityManager->flush();
    }

    /** @throws StorageNotWritableException */
    private function writeAtomically(string $folder, string $path, string $contents): void
    {
        $tmpPath = @tempnam($folder, 'tmp-');
        if ($tmpPath === false) {
            throw new StorageNotWritableException('The record folder is not writable.');
        }

        // tempnam() silently falls back to the system temp dir when $folder is not writable,
        // which would turn the rename() below into a cross-device move.
        if (
            realpath(\dirname($tmpPath)) !== realpath($folder)
            || @file_put_contents($tmpPath, $contents) === false
            || !@chmod($tmpPath, 0o644)
            || !@rename($tmpPath, $path)
        ) {
            @unlink($tmpPath);

            throw new StorageNotWritableException('The file could not be written to the record folder.');
        }
    }

    private function findRow(EntityManagerInterface $entityManager, int $animeId, string $fileName): ?RecordFolderFile
    {
        return $entityManager->createQueryBuilder()
            ->select('f')
            ->from(RecordFolderFile::class, 'f')
            ->andWhere('f.anime = :animeId')
            ->andWhere('f.pluginId = :pluginId')
            ->andWhere('f.fileName = :fileName')
            ->setParameter('animeId', $animeId)
            ->setParameter('pluginId', (string) $this->pluginId)
            ->setParameter('fileName', $fileName)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = $this->registry->getManagerForClass(RecordFolderFile::class);
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new \LogicException('No EntityManager is registered for '.RecordFolderFile::class.'.');
        }

        if (!$entityManager->isOpen()) {
            $this->registry->resetManager();
            $entityManager = $this->registry->getManagerForClass(RecordFolderFile::class);
        }

        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
