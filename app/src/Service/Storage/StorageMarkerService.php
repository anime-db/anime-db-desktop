<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
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

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
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

        return false !== $sections ? $sections : [];
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
