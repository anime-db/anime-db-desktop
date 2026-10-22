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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Reconciles the marker at $storage's own path: no marker means this path is now claimed by
     * $storage; a marker already carrying $storage's id is left alone; a marker pointing at a
     * deleted Storage is rewritten (the path is free); a marker pointing at another existing
     * Storage means the path is occupied and scanning it must be skipped. A desktop.ini that
     * cannot be parsed is left untouched (Unreadable).
     */
    public function reconcile(Storage $storage): StorageMarkerResult
    {
        $id = $storage->id ?? throw new \LogicException('Storage must be persisted before its marker can be reconciled');
        $path = $storage->requirePath();

        if ($this->isUnreadable($this->markerPath($path))) {
            $this->logger->warning('Storage marker file cannot be parsed, leaving it untouched', ['path' => $this->markerPath($path)]);

            return StorageMarkerResult::Unreadable;
        }

        $markerId = $this->readMarkerId($path);

        if ($markerId === null) {
            return $this->writeMarker($path, $id) ? StorageMarkerResult::Created : StorageMarkerResult::WriteFailed;
        }

        if ($markerId === $id) {
            return StorageMarkerResult::Owned;
        }

        $owner = $this->entityManager->find(Storage::class, $markerId);

        if ($owner === null) {
            return $this->writeMarker($path, $id) ? StorageMarkerResult::Reclaimed : StorageMarkerResult::WriteFailed;
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
        if ($this->readMarkerId($path) !== $storage->id || $storage->requirePath() === $path) {
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
     *
     * $path overrides which location's marker is forgotten — used when $storage is being
     * relocated (StorageEditController) and the marker to clear is the *old* path, which
     * $storage->getPath() no longer reflects once relocate() has already run.
     */
    public function forget(Storage $storage, ?string $path = null): void
    {
        $id = $storage->id ?? throw new \LogicException('Storage must be persisted before its marker can be forgotten');
        $path ??= $storage->requirePath();

        if ($this->readMarkerId($path) !== $id) {
            return;
        }

        $markerPath = $this->markerPath($path);
        $remaining = $this->withoutMarkerSection((string) file_get_contents($markerPath));

        if (trim($remaining) === '') {
            unlink($markerPath);

            return;
        }

        $this->writeFile($markerPath, $remaining);
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
        $tail = $this->relativeTail($storage->requirePath());

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

    /** The Storage id recorded in the `[AnimeDB]` section of $storagePath's marker, or null when there is none. */
    public function readMarkerId(string $storagePath): ?int
    {
        $markerPath = $this->markerPath($storagePath);

        $id = $this->readSections($markerPath)[self::SECTION]['id'] ?? null;

        return \is_numeric($id) ? (int) $id : null;
    }

    private function writeMarker(string $storagePath, int $id): bool
    {
        $markerPath = $this->markerPath($storagePath);
        $content = is_file($markerPath) ? (string) file_get_contents($markerPath) : '';

        return $this->writeFile($markerPath, $this->withMarkerId($content, $id));
    }

    /**
     * True when desktop.ini exists but cannot be safely read back: PHP's ini grammar rejects it
     * (parse_ini_file() === false — e.g. keys with parentheses, reserved words as keys) or it is
     * UTF-16 (BOM), which PHP would misread as garbage keys. Such a file is never written to.
     * The BOM check is the only guard for UTF-16BE (parse_ini_file() accepts it as an empty
     * file); UTF-16LE is also rejected by parse_ini_file() itself, so there it is a safety net.
     */
    private function isUnreadable(string $markerPath): bool
    {
        if (!is_file($markerPath)) {
            return false;
        }

        $content = @file_get_contents($markerPath);

        if ($content === false || str_starts_with($content, "\xFF\xFE") || str_starts_with($content, "\xFE\xFF")) {
            return true;
        }

        return @parse_ini_file($markerPath, true, \INI_SCANNER_RAW) === false;
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

    /** Sets `id` in the `[AnimeDB]` section of $content, editing line by line so everything else stays byte-identical. */
    private function withMarkerId(string $content, int $id): string
    {
        $eol = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $lines = $content === '' ? [] : preg_split('/\r\n|\n|\r/', $content);
        \assert(\is_array($lines));
        $range = $this->sectionRange($lines);

        if ($range === null) {
            if ($lines !== [] && end($lines) === '') {
                array_pop($lines);
            }
            $lines[] = '['.self::SECTION.']';
            $lines[] = "id=$id";
            $lines[] = '';

            return implode($eol, $lines);
        }

        [$start, $end] = $range;
        $found = false;
        // Case-sensitive like parse_ini_file(), which reads the last duplicate: keep the first
        // `id` line (rewritten) and drop the rest so the reader and writer always agree.
        for ($i = $start + 1; $i < $end; ++$i) {
            if (preg_match('/^\s*id\s*=/', $lines[$i]) !== 1) {
                continue;
            }
            if (!$found) {
                $lines[$i] = "id=$id";
                $found = true;

                continue;
            }
            array_splice($lines, $i, 1);
            --$i;
            --$end;
        }

        if (!$found) {
            array_splice($lines, $start + 1, 0, ["id=$id"]);
        }

        return implode($eol, $lines);
    }

    /** Removes the `[AnimeDB]` section (header through the line before the next section) from $content. */
    private function withoutMarkerSection(string $content): string
    {
        $eol = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $lines = preg_split('/\r\n|\n|\r/', $content);
        \assert(\is_array($lines));
        $range = $this->sectionRange($lines);

        if ($range === null) {
            return $content;
        }

        array_splice($lines, $range[0], $range[1] - $range[0]);

        return implode($eol, $lines);
    }

    /**
     * @param list<string> $lines
     *
     * @return ?array{int, int} [index of the `[AnimeDB]` header line, index of the next section header or line count]
     */
    private function sectionRange(array $lines): ?array
    {
        $start = null;

        foreach ($lines as $i => $line) {
            if (preg_match('/^(?:\xEF\xBB\xBF)?\s*\[([^\]]*)\]\s*(?:;.*)?$/', $line, $m) !== 1) {
                continue;
            }
            if ($start !== null) {
                return [$start, $i];
            }
            if (trim($m[1]) === self::SECTION) {
                $start = $i;
            }
        }

        return $start === null ? null : [$start, \count($lines)];
    }

    /**
     * Writes $content to the marker. An existing hidden/system file refuses writes on Windows, so
     * its attributes are lifted for the write and put back afterwards; a new file becomes hidden.
     * A failed write is logged, never thrown, and reported as false.
     */
    private function writeFile(string $markerPath, string $content): bool
    {
        $existed = is_file($markerPath);
        $attributes = ['H' => !$existed, 'S' => false];

        // The app only ships for Windows (see .claude-docs/architecture.md); the test suite
        // also runs on ubuntu-latest CI (.github/workflows/ci.yml), where `attrib` doesn't
        // exist and hiding the file has no meaning, so this is skipped there.
        if (\PHP_OS_FAMILY === 'Windows' && $existed) {
            $read = $this->readAttributes($markerPath);
            // Unexpected attrib output: leave the attributes alone rather than guess.
            if ($read !== null) {
                $attributes = $read;
                exec('attrib -S -H '.escapeshellarg($markerPath));
            }
        }

        $written = @file_put_contents($markerPath, $content);

        if ($written === false) {
            $this->logger->warning('Failed to write storage marker file', ['path' => $markerPath]);
        }

        if (\PHP_OS_FAMILY === 'Windows') {
            foreach ($attributes as $flag => $set) {
                if ($set && is_file($markerPath)) {
                    exec("attrib +$flag ".escapeshellarg($markerPath));
                }
            }
        }

        return $written !== false;
    }

    /** @return ?array{H: bool, S: bool} null when `attrib` output could not be parsed */
    private function readAttributes(string $markerPath): ?array
    {
        $output = [];
        exec('attrib '.escapeshellarg($markerPath), $output);

        return self::parseAttributes($output[0] ?? '', $markerPath);
    }

    /**
     * Parses one line of `attrib <file>` output ("A  SH       C:\dir\desktop.ini"): only the
     * attribute column before the path is inspected, and the line must end with $markerPath
     * (slashes and case ignored). Anything else (error message, empty output) yields null.
     *
     * @return ?array{H: bool, S: bool}
     */
    public static function parseAttributes(string $line, string $markerPath): ?array
    {
        if (preg_match('/^([ASHRIOXVPB ]*?)\s+((?:[A-Za-z]:[\\\\\/]|\\\\\\\\).*)$/', rtrim($line), $m) !== 1) {
            return null;
        }

        $normalize = static fn (string $path): string => strtolower(str_replace('/', '\\', $path));

        if ($normalize(trim($m[2])) !== $normalize($markerPath)) {
            return null;
        }

        return ['H' => str_contains($m[1], 'H'), 'S' => str_contains($m[1], 'S')];
    }

    private function markerPath(string $storagePath): string
    {
        return rtrim($storagePath, '\\/').\DIRECTORY_SEPARATOR.self::MARKER_FILENAME;
    }
}
