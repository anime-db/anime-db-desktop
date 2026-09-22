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

namespace App\Service\Import;

use App\Service\Import\Exception\InvalidCatalogArchiveException;
use App\Service\Plugin\PluginDirectoryRemover;
use App\Service\WsPublisher;
use App\Service\Zip\SafeZipEntryNames;
use Psr\Log\LoggerInterface;

/**
 * Receives a catalog export archive (issue #657) and stages it for a later import (issue #669):
 * validates it, then unpacks `data.db` and `media/` into `%AppData%/import-staging/` alongside a
 * `import.json` marker. Deliberately never touches the app's live `data.db` — swapping it while
 * FrankenPHP's worker process still holds an open Doctrine connection would corrupt data, not
 * just risk it (see the class docblock reasoning duplicated in issue #669's description). That
 * swap, `media/` application, a schema-version guard and a backup of the current database are all
 * out of scope here — separate, not-yet-built subtasks.
 *
 * Every way the archive can be rejected — unreadable/corrupt ZIP, no valid `manifest.json`, an
 * unsupported `formatVersion`, no `data.db`, or an unsafe entry path (zip-slip, checked via the
 * same {@see SafeZipEntryNames::findUnsafe()} guard {@see \App\Service\Plugin\ZipPluginInstaller}
 * uses) — is checked before {@see self::resetStagingDir()} ever runs, so a rejected archive never
 * touches the staging directory at all. `manifest.json`'s `counts` field is the one exception:
 * a mismatch against what actually got staged is logged, not rejected — it is an informational
 * cross-check, not a validity gate (issue #669 acceptance criterion 4).
 *
 * The staging directory is wiped and recreated on every successful validation, never merged with
 * whatever a previous run left behind: there is no sound way to merge two staged catalogs, and
 * refusing a re-run because something is already staged would permanently lock a user out after
 * one failed attempt.
 */
final class CatalogStageService
{
    /**
     * Keep in sync with `CatalogExportService::FORMAT_VERSION`. Not shared as a single constant
     * on purpose: export and staging are independent subtasks (issues #657 / #669) that only need
     * to agree on the archive shape, not on any other implementation detail.
     */
    private const int SUPPORTED_FORMAT_VERSION = 1;

    private const string MARKER_FILENAME = 'import.json';

    public function __construct(
        private readonly WsPublisher $wsPublisher,
        private readonly LoggerInterface $logger,
        private readonly string $importStagingDir,
        private readonly string $importRejectionPath,
    ) {
    }

    /**
     * @throws InvalidCatalogArchiveException
     */
    public function stage(string $archivePath): CatalogStageResult
    {
        $zip = new \ZipArchive();
        $openResult = $zip->open($archivePath);
        if ($openResult !== true) {
            throw new InvalidCatalogArchiveException(InvalidCatalogArchiveException::REASON_UNREADABLE, ['%path%' => $archivePath], \sprintf('Unable to open catalog archive "%s" (error code %s).', $archivePath, $openResult));
        }

        try {
            $manifest = $this->readManifest($zip, $archivePath);
            $this->assertFormatVersionSupported($manifest, $archivePath);
            $this->assertDatabasePresent($zip, $archivePath);
            $this->assertSafeEntryNames($zip, $archivePath);

            $this->resetStagingDir();

            $mediaFileCount = $this->extract($zip);
            $animeCount = $this->countAnime($this->importStagingDir.\DIRECTORY_SEPARATOR.'data.db');

            $this->logCountMismatches($manifest, $animeCount, $mediaFileCount);
            $this->writeMarker($archivePath);

            return new CatalogStageResult($this->importStagingDir, $animeCount, $mediaFileCount);
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidCatalogArchiveException
     */
    private function readManifest(\ZipArchive $zip, string $archivePath): array
    {
        $contents = $zip->getFromName('manifest.json');
        $manifest = $contents !== false ? json_decode($contents, true) : null;

        if (!\is_array($manifest)) {
            throw new InvalidCatalogArchiveException(InvalidCatalogArchiveException::REASON_MISSING_MANIFEST, ['%path%' => $archivePath], \sprintf('Catalog archive "%s" has no valid manifest.json.', $archivePath));
        }

        return $manifest;
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @throws InvalidCatalogArchiveException
     */
    private function assertFormatVersionSupported(array $manifest, string $archivePath): void
    {
        $formatVersion = $manifest['formatVersion'] ?? null;

        if ($formatVersion !== self::SUPPORTED_FORMAT_VERSION) {
            throw new InvalidCatalogArchiveException(InvalidCatalogArchiveException::REASON_UNSUPPORTED_FORMAT_VERSION, ['%path%' => $archivePath, '%formatVersion%' => \is_scalar($formatVersion) ? (string) $formatVersion : 'unknown'], \sprintf('Catalog archive "%s" has an unsupported format version.', $archivePath));
        }
    }

    /**
     * @throws InvalidCatalogArchiveException
     */
    private function assertDatabasePresent(\ZipArchive $zip, string $archivePath): void
    {
        if ($zip->locateName('data.db') === false) {
            throw new InvalidCatalogArchiveException(InvalidCatalogArchiveException::REASON_MISSING_DATABASE, ['%path%' => $archivePath], \sprintf('Catalog archive "%s" has no data.db.', $archivePath));
        }
    }

    /**
     * @throws InvalidCatalogArchiveException
     */
    private function assertSafeEntryNames(\ZipArchive $zip, string $archivePath): void
    {
        $unsafeEntry = SafeZipEntryNames::findUnsafe($zip);
        if ($unsafeEntry !== null) {
            throw new InvalidCatalogArchiveException(InvalidCatalogArchiveException::REASON_UNSAFE_ENTRY, ['%path%' => $archivePath, '%entry%' => $unsafeEntry], \sprintf('Catalog archive "%s" contains an unsafe entry path "%s".', $archivePath, $unsafeEntry));
        }
    }

    /**
     * Also clears any rejection reason a previous native/supervisor/staged-import.js decision
     * (issue #706) left behind: once a new archive has passed every validation check above and
     * is about to be staged, that old reason no longer describes anything the user still needs
     * to act on, and StagedImportService::readRejectionReason() must not keep surfacing it on
     * /settings/backup after this newly staged import is decided on.
     */
    private function resetStagingDir(): void
    {
        PluginDirectoryRemover::remove($this->importStagingDir);
        @unlink($this->importRejectionPath);

        if (!mkdir($this->importStagingDir, recursive: true) && !is_dir($this->importStagingDir)) {
            throw new \RuntimeException(\sprintf('Unable to create staging directory "%s".', $this->importStagingDir));
        }
    }

    /**
     * Extracts `data.db` and every `media/` entry one at a time — rather than a single
     * `extractTo($dir)` of the whole archive — publishing `import.progress` after each, the same
     * `{phase, current, total}` shape {@see \App\Service\Export\CatalogExportService} publishes
     * `export.progress` with. Extracting entry-by-entry, instead of the whole archive at once,
     * also means only the two known-good entries ever land on disk, regardless of what else a
     * (by this point already validated) archive happens to contain.
     *
     * @return int number of media files extracted
     */
    private function extract(\ZipArchive $zip): int
    {
        if (!$zip->extractTo($this->importStagingDir, 'data.db')) {
            throw new \RuntimeException(\sprintf('Unable to extract data.db into "%s".', $this->importStagingDir));
        }
        $this->wsPublisher->publish('import.progress', ['phase' => 'database', 'current' => 1, 'total' => 1]);

        $mediaEntries = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if ($name !== false && str_starts_with($name, 'media/') && !str_ends_with($name, '/')) {
                $mediaEntries[] = $name;
            }
        }

        $total = \count($mediaEntries);
        $current = 0;
        foreach ($mediaEntries as $entryName) {
            ++$current;

            if (!$zip->extractTo($this->importStagingDir, $entryName)) {
                throw new \RuntimeException(\sprintf('Unable to extract "%s" into "%s".', $entryName, $this->importStagingDir));
            }

            $this->wsPublisher->publish('import.progress', [
                'phase' => 'media',
                'current' => $current,
                'total' => $total,
            ]);
        }

        return $total;
    }

    private function countAnime(string $dbPath): int
    {
        $connection = null;

        try {
            $connection = new \PDO('sqlite:'.$dbPath);
            $statement = $connection->query('SELECT COUNT(*) FROM anime');

            return $statement !== false ? (int) $statement->fetchColumn() : 0;
        } catch (\Throwable) {
            return 0;
        } finally {
            // Same reasoning as CatalogExportService::doExport(): PDO's SQLite driver keeps the
            // file open until this reference is dropped.
            $connection = null;
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function logCountMismatches(array $manifest, int $actualAnimeCount, int $actualMediaFileCount): void
    {
        $expectedAnimeCount = $manifest['counts']['anime'] ?? null;
        if ($expectedAnimeCount !== $actualAnimeCount) {
            $this->logger->warning('Staged catalog archive manifest "counts.anime" does not match the staged contents.', [
                'expected' => $expectedAnimeCount,
                'actual' => $actualAnimeCount,
            ]);
        }

        $expectedMediaFileCount = $manifest['counts']['mediaFiles'] ?? null;
        if ($expectedMediaFileCount !== $actualMediaFileCount) {
            $this->logger->warning('Staged catalog archive manifest "counts.mediaFiles" does not match the staged contents.', [
                'expected' => $expectedMediaFileCount,
                'actual' => $actualMediaFileCount,
            ]);
        }
    }

    private function writeMarker(string $archivePath): void
    {
        $marker = [
            'markerVersion' => StagedImportService::SUPPORTED_MARKER_VERSION,
            'stagedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            'sourceArchive' => basename($archivePath),
        ];

        $markerPath = $this->importStagingDir.\DIRECTORY_SEPARATOR.self::MARKER_FILENAME;
        $written = file_put_contents(
            $markerPath,
            json_encode($marker, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
        );

        if ($written === false) {
            throw new \RuntimeException(\sprintf('Unable to write staging marker "%s".', $markerPath));
        }
    }
}
