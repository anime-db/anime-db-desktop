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

use App\Entity\Storage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Identifies which Storage a filesystem path belongs to via a `desktop.ini` marker in its
 * root, independent of the path itself (drive letter, mount point) — the v2 equivalent of v1's
 * `.storage` marker / `ScanStoragesCommand::checkStorageId()`. Only the `[AnimeDB]` section is
 * ever touched; any `[.ShellClassInfo]` section a future icon-marking feature adds is preserved
 * as-is on rewrite.
 */
final class StorageMarkerService
{
    private const MARKER_FILENAME = 'desktop.ini';
    private const SECTION = 'AnimeDB';

    /**
     * @param ?iterable<string> $driveRoots overrides the drives searched by findByMarker() —
     *                                      tests inject a list of temp directories here, since
     *                                      real drive letters don't exist on the ubuntu-latest
     *                                      CI runner (see the app-only-ships-for-Windows note
     *                                      on writeMarker()); left null in production, where
     *                                      findByMarker() enumerates `A:\`-`Z:\` itself
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ?iterable $driveRoots = null,
    ) {
    }

    /**
     * Reconciles the marker at $storage's own path: no marker means this path is now claimed by
     * $storage; a marker already carrying $storage's id is left alone; a marker pointing at a
     * deleted Storage is rewritten (the path is free); a marker pointing at another existing
     * Storage means the path is occupied and scanning it must be skipped.
     */
    public function reconcile(Storage $storage): StorageMarkerResult
    {
        $id = $storage->id ?? throw new \LogicException('Storage must be persisted before its marker can be reconciled');
        $path = $storage->getPath();
        $markerId = $this->readMarkerId($path);

        if ($markerId === null) {
            $this->writeMarker($path, $id);

            return StorageMarkerResult::Created;
        }

        if ($markerId === $id) {
            return StorageMarkerResult::Owned;
        }

        $owner = $this->entityManager->find(Storage::class, $markerId);

        if ($owner === null) {
            $this->writeMarker($path, $id);

            return StorageMarkerResult::Reclaimed;
        }

        return StorageMarkerResult::Conflict;
    }

    /**
     * A storage that physically moved (drive reconnected under a different letter, folder
     * relocated to another disk) keeps its marker, so a scan of the new $path can recognize it
     * as $storage even though Storage::$path in the database still points at the old location.
     * Relocates $storage to $path and returns true when that's exactly what's found; otherwise
     * leaves $storage untouched and returns false.
     */
    public function relocateIfMarkerMoved(Storage $storage, string $path): bool
    {
        if ($this->readMarkerId($path) !== $storage->id || $storage->getPath() === $path) {
            return false;
        }

        $storage->relocate($path);

        return true;
    }

    /**
     * Removes the marker's [AnimeDB] id record when $storage is deleted, so a stale id doesn't
     * linger on disk until a future scan of that path reclaims it (see reconcile()'s Reclaimed
     * case). Does nothing if the marker is missing or no longer names $storage (path was already
     * reclaimed by another storage). Any unrelated [.ShellClassInfo] section is preserved; the
     * marker file itself is only deleted once no sections remain.
     */
    public function forget(Storage $storage): void
    {
        $id = $storage->id ?? throw new \LogicException('Storage must be persisted before its marker can be forgotten');
        $path = $storage->getPath();

        if ($this->readMarkerId($path) !== $id) {
            return;
        }

        $markerPath = $this->markerPath($path);
        $sections = $this->readSections($markerPath);
        unset($sections[self::SECTION]);

        if ($sections === []) {
            unlink($markerPath);

            return;
        }

        file_put_contents($markerPath, $this->serializeIni($sections));
    }

    /**
     * Searches every existing drive root for a desktop.ini marker naming $storage — for when
     * $storage's own path became unreadable (drive reassigned a new letter, external drive
     * reconnected elsewhere) and there is no candidate path to check yet, unlike
     * relocateIfMarkerMoved() above, which only confirms one already-known candidate. $storage's
     * path is rarely a drive root itself (e.g. "D:\Anime\", not "D:\") — reconnecting the same
     * physical drive under a new letter keeps its internal folder structure, so the search
     * re-applies $storage's own tail ("Anime\") to each candidate root instead of only checking
     * the root itself. Gives up immediately (returns null) for UNC paths and any other path
     * shape without a drive letter to reassign, since there is nothing to search across.
     */
    public function findByMarker(Storage $storage): ?string
    {
        $storageId = $storage->id ?? throw new \LogicException('Storage must be persisted before its marker can be searched for');
        $tail = $this->relativeTail($storage->getPath());

        if ($tail === null) {
            return null;
        }

        foreach ($this->driveRoots ?? $this->existingDriveRoots() as $root) {
            $candidate = rtrim($root, '\\/').($tail === '' ? '' : \DIRECTORY_SEPARATOR.$tail);

            if ($this->readMarkerId($candidate) === $storageId) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return iterable<string> */
    private function existingDriveRoots(): iterable
    {
        foreach (range('A', 'Z') as $letter) {
            $root = "$letter:\\";
            if (is_dir($root)) {
                yield $root;
            }
        }
    }

    /**
     * Strips the drive letter off a Windows path ("D:\Anime\" -> "Anime"), the part that
     * doesn't survive a drive-letter reassignment. Returns null for UNC paths
     * ("\\server\share\...", no drive letter to reassign) and any other path shape (e.g. a
     * POSIX path, only ever seen in this test suite) findByMarker() has no candidate roots to
     * search across for.
     */
    private function relativeTail(string $storagePath): ?string
    {
        if (preg_match('#^[A-Za-z]:[\\\\/]?(.*)$#', $storagePath, $matches) !== 1) {
            return null;
        }

        return rtrim($matches[1], '\\/');
    }

    private function readMarkerId(string $storagePath): ?int
    {
        $markerPath = $this->markerPath($storagePath);

        $id = $this->readSections($markerPath)[self::SECTION]['id'] ?? null;

        return \is_numeric($id) ? (int) $id : null;
    }

    private function writeMarker(string $storagePath, int $id): void
    {
        $markerPath = $this->markerPath($storagePath);

        $sections = $this->readSections($markerPath);
        $sections[self::SECTION] = ['id' => $id];

        file_put_contents($markerPath, $this->serializeIni($sections));

        // The app only ships for Windows (see .claude-docs/architecture.md); the test suite
        // also runs on ubuntu-latest CI (.github/workflows/ci.yml), where `attrib` doesn't
        // exist and hiding the file has no meaning, so this is skipped there.
        if (\PHP_OS_FAMILY === 'Windows') {
            exec('attrib +H '.escapeshellarg($markerPath));
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function readSections(string $markerPath): array
    {
        if (!is_file($markerPath)) {
            return [];
        }

        $sections = @parse_ini_file($markerPath, true, \INI_SCANNER_RAW);

        return $sections !== false ? $sections : [];
    }

    /** @param array<string, array<string, mixed>> $sections */
    private function serializeIni(array $sections): string
    {
        $lines = [];

        foreach ($sections as $section => $values) {
            $lines[] = "[$section]";

            foreach ($values as $key => $value) {
                $lines[] = "$key=$value";
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function markerPath(string $storagePath): string
    {
        return rtrim($storagePath, '\\/').\DIRECTORY_SEPARATOR.self::MARKER_FILENAME;
    }
}
