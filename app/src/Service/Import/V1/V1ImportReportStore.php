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

namespace App\Service\Import\V1;

use Psr\Log\LoggerInterface;

/**
 * Keeps the {@see V1ImportResult} of the last AnimeDB v1 import in `userData/import-v1-report.json`
 * (issue #954), so the report outlives the import screen, a page reload and navigation. The file
 * is written by the import command itself, read back for the settings page and removed when the
 * user dismisses it or when native/backup-restore replaces the catalog it describes.
 *
 * The file is read as fully untrusted input: unreadable or oversized files, invalid JSON, fields
 * of the wrong type, and over-long lists or strings never throw — they are zeroed, dropped or
 * truncated, and a file with nothing left to show reads as no report at all.
 */
final class V1ImportReportStore
{
    /** A genuine report is a few kilobytes; anything far larger is not one. */
    private const int MAX_FILE_BYTES = 262144;

    private const int MAX_LIST_ENTRIES = 100;

    private const int MAX_STRING_LENGTH = 200;

    private const int MAX_COUNTER = 100000000;

    public function __construct(
        private readonly string $importV1ReportPath,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Best-effort: a failure to persist the report is logged and never fails the import itself.
     * Written to a sibling temporary file and renamed, so a reader never sees a half-written file.
     */
    public function save(V1ImportResult $result): void
    {
        $temporaryPath = $this->importV1ReportPath.'.'.bin2hex(random_bytes(4)).'.tmp';

        try {
            $json = json_encode($result->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);
            if (file_put_contents($temporaryPath, $json) === false || !rename($temporaryPath, $this->importV1ReportPath)) {
                throw new \RuntimeException('Cannot write the report file.');
            }
        } catch (\Throwable $exception) {
            @unlink($temporaryPath);
            $this->logger->warning('Failed to save the AnimeDB v1 import report.', ['exception' => $exception]);
        }
    }

    /**
     * @return V1ImportResult|null null when there is nothing to show: no file, or one that is
     *                             unreadable, malformed or describes no created entries
     */
    public function load(): ?V1ImportResult
    {
        $data = $this->readData();
        if ($data === null) {
            return null;
        }

        $result = new V1ImportResult(
            animeCreated: $this->counter($data, 'animeCreated'),
            withStatusFromLabel: $this->counter($data, 'withStatusFromLabel'),
            withDefaultStatus: $this->counter($data, 'withDefaultStatus'),
            namesJapanese: $this->counter($data, 'namesJapanese'),
            namesRussian: $this->counter($data, 'namesRussian'),
            namesUnknownLocale: $this->counter($data, 'namesUnknownLocale'),
            sources: $this->counter($data, 'sources'),
            descriptions: $this->counter($data, 'descriptions'),
            studios: $this->counter($data, 'studios'),
            labels: $this->counter($data, 'labels'),
            genresMapped: $this->counter($data, 'genresMapped'),
            genresDroppedByDesign: $this->counter($data, 'genresDroppedByDesign'),
            genresUnmapped: $this->counter($data, 'genresUnmapped'),
            unmappedGenreNames: $this->strings($data, 'unmappedGenreNames'),
            coversImported: $this->counter($data, 'coversImported'),
            coversMissing: $this->counter($data, 'coversMissing'),
            storagesCreated: $this->counter($data, 'storagesCreated'),
            storagesUnavailable: $this->counter($data, 'storagesUnavailable'),
            skippedStorageNames: $this->strings($data, 'skippedStorageNames'),
            endDatesSynthesized: $this->counter($data, 'endDatesSynthesized'),
            durationsCleared: $this->counter($data, 'durationsCleared'),
            episodesDroppedTitles: $this->strings($data, 'episodesDroppedTitles'),
            needsAttention: $this->counter($data, 'needsAttention'),
            statusesDowngraded: $this->counter($data, 'statusesDowngraded'),
        );

        return $result->animeCreated > 0 ? $result : null;
    }

    /** Removes the report file; a missing file is not an error. */
    public function dismiss(): void
    {
        @unlink($this->importV1ReportPath);
    }

    /**
     * @return array<mixed>|null
     */
    private function readData(): ?array
    {
        if (!is_file($this->importV1ReportPath)) {
            return null;
        }

        $size = @filesize($this->importV1ReportPath);
        if ($size === false || $size > self::MAX_FILE_BYTES) {
            return null;
        }

        $contents = @file_get_contents($this->importV1ReportPath, false, null, 0, self::MAX_FILE_BYTES);
        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);

        return \is_array($data) ? $data : null;
    }

    /**
     * @param array<mixed> $data
     */
    private function counter(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        return \is_int($value) && $value >= 0 && $value <= self::MAX_COUNTER ? $value : 0;
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    private function strings(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!\is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (\count($result) >= self::MAX_LIST_ENTRIES) {
                break;
            }
            if (\is_string($item) && $item !== '' && mb_strlen($item) <= self::MAX_STRING_LENGTH) {
                $result[] = $item;
            }
        }

        return $result;
    }
}
