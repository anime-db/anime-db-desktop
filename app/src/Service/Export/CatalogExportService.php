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

namespace App\Service\Export;

use App\Service\Download\FreeSpaceProvider;
use App\Service\Export\Exception\InsufficientDiskSpaceException;
use App\Service\Plugin\InstalledPlugin;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\WsPublisher;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Builds a one-shot, one-way export archive of the catalog (issue #657) — `data.db` (a
 * `VACUUM INTO` snapshot, same technique as {@see \App\Command\DatabaseBackupCommand}), the
 * `media/` covers/gallery images referenced by that snapshot, and a `manifest.json` describing
 * the export. Deliberately excludes `config.json`/`plugins.json`: both carry secrets (a proxy
 * password, plugin OAuth tokens) protected only by filesystem ACLs on `%AppData%`, a protection
 * that stops applying the moment an archive leaves that directory — see the class docblock on
 * {@see \App\Command\CatalogExportCommand} for the full reasoning. This is an export, not a
 * backup or a sync: nothing here reads the archive back.
 *
 * `data.db`'s file list for `media/` is read from the just-taken snapshot, not the live
 * database or a directory scan of `media/` itself — a plugin can still be downloading a cover
 * while the archive is being built, and the snapshot is the only source that stays consistent
 * with `data.db`'s own contents for the whole run. A referenced file that is unreadable or has
 * been replaced since the snapshot was taken is skipped and logged rather than failing the whole
 * export (issue #657, "Острые углы") — {@see \App\Service\Plugin\Filler\HttpPluginMediaDownloader::writeAtomically()} always
 * publishes a cover via `rename()`, so a reader here only ever sees a whole old file, a whole new
 * file, or nothing at all, never a partial write.
 *
 * The free-space check ({@see self::assertEnoughFreeSpace()}) runs against the estimated size of
 * what is about to be written to the *destination* volume, after the snapshot already exists in a
 * system temp location — so a failed check never leaves anything behind on the destination at
 * all, not even a `.tmp` file (issue #657 acceptance criterion 6).
 */
final class CatalogExportService
{
    /**
     * Bumped only if a future release changes the archive's shape in a way the importer (a
     * separate, not-yet-built subtask) needs to branch on. Written from day one — see issue #657.
     */
    private const int FORMAT_VERSION = 1;

    /**
     * Flat safety margin on top of the estimated archive size. The estimate itself already sums
     * the *uncompressed* size of every file that goes in, while `data.db` is actually stored
     * compressed (see self::writeArchive()) — so the estimate already over-counts the real disk
     * usage on its own; this is just headroom for filesystem block rounding, not a ratio like
     * {@see \App\Service\Download\FreeSpaceChecker::OVERHEAD_RATIO} needs for an unknown-until-
     * downloaded torrent size.
     */
    private const int MIN_OVERHEAD_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly Connection $connection,
        private readonly FreeSpaceProvider $freeSpaceProvider,
        private readonly WsPublisher $wsPublisher,
        private readonly InstalledPluginsRegistry $pluginsRegistry,
        private readonly LoggerInterface $logger,
        private readonly string $mediaDir,
        private readonly ?string $coreVersion = null,
    ) {
    }

    /**
     * @throws InsufficientDiskSpaceException
     */
    public function export(string $destinationDir): CatalogExportResult
    {
        try {
            return $this->doExport(rtrim($destinationDir, '/\\'));
        } catch (\Throwable $exception) {
            $this->wsPublisher->publish('export.failed', [
                'reason' => $exception instanceof InsufficientDiskSpaceException ? 'insufficient_space' : 'exception',
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function doExport(string $destinationDir): CatalogExportResult
    {
        $tmpDbPath = tempnam(sys_get_temp_dir(), 'animedb-export-');
        if ($tmpDbPath === false) {
            throw new \RuntimeException('Unable to allocate a temporary file for the database snapshot.');
        }
        // VACUUM INTO refuses to write to a file that already exists.
        unlink($tmpDbPath);

        try {
            $this->connection->executeStatement('VACUUM INTO ?', [$tmpDbPath]);
            $this->wsPublisher->publish('export.progress', ['phase' => 'database', 'current' => 1, 'total' => 1]);

            $snapshot = new \PDO('sqlite:'.$tmpDbPath);
            $animeCount = (int) $snapshot->query('SELECT COUNT(*) FROM anime')->fetchColumn();
            $mediaEntries = $this->collectMediaEntries($snapshot);

            $dbSize = filesize($tmpDbPath);
            $estimatedBytes = ($dbSize !== false ? $dbSize : 0) + array_sum(array_column($mediaEntries, 'size'));
            $this->assertEnoughFreeSpace($destinationDir, $estimatedBytes);

            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $finalPath = $destinationDir.'/'.\sprintf('animedb-catalog-%s.zip', $now->format('Ymd-His'));

            $skipped = $this->writeArchive($tmpDbPath, $finalPath, $mediaEntries, $this->buildManifest($now, $animeCount, \count($mediaEntries)));

            $result = new CatalogExportResult($finalPath, $animeCount, \count($mediaEntries), $skipped);

            $this->wsPublisher->publish('export.done', [
                'path' => $result->archivePath,
                'animeCount' => $result->animeCount,
                'mediaFileCount' => $result->mediaFileCount,
                'skippedMediaFiles' => $result->skippedMediaFiles,
            ]);

            return $result;
        } finally {
            @unlink($tmpDbPath);
        }
    }

    /**
     * @param list<array{relativePath: string, fullPath: string, size: int}> $mediaEntries
     * @param array<string, mixed>                                           $manifest
     *
     * @return int number of media files skipped because they became unreadable since the snapshot
     */
    private function writeArchive(string $tmpDbPath, string $finalPath, array $mediaEntries, array $manifest): int
    {
        $tmpZipPath = $finalPath.'.tmp';
        @unlink($tmpZipPath);

        $zip = new \ZipArchive();
        if ($zip->open($tmpZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException(\sprintf('Unable to create the archive at "%s".', $tmpZipPath));
        }

        // Already-compressed webp covers gain nothing from DEFLATE and just cost CPU time —
        // data.db is plain SQLite pages and compresses well, so only it is stored compressed
        // (issue #657, "Сжатие").
        if (!$zip->addFile($tmpDbPath, 'data.db')) {
            throw new \RuntimeException('Unable to add the database snapshot to the archive.');
        }
        $zip->setCompressionName('data.db', \ZipArchive::CM_DEFLATE);

        $skipped = 0;
        $total = \count($mediaEntries);
        $current = 0;
        foreach ($mediaEntries as $entry) {
            ++$current;

            // A single file_get_contents() call, not an is_readable() check followed by a
            // separate read: two calls would leave a window for the file to disappear or change
            // between them. See the class docblock for why a plugin download in progress can
            // never be observed as a *partial* file here, only as present-or-not.
            $content = @file_get_contents($entry['fullPath']);
            if ($content === false) {
                ++$skipped;
                $this->logger->warning('Skipping a media file during catalog export: unreadable or removed since the database snapshot was taken.', [
                    'path' => $entry['relativePath'],
                ]);
            } else {
                $entryName = 'media/'.$entry['relativePath'];
                $zip->addFromString($entryName, $content);
                $zip->setCompressionName($entryName, \ZipArchive::CM_STORE);
            }

            $this->wsPublisher->publish('export.progress', [
                'phase' => 'media',
                'current' => $current,
                'total' => $total,
            ]);
        }

        $zip->addFromString('manifest.json', json_encode(
            $manifest,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        ));

        if (!$zip->close()) {
            throw new \RuntimeException('Unable to finalize the archive.');
        }

        // The archive only appears under its real name once it is fully written — a cancelled
        // export (the native layer kills this process by PID) never leaves a file that looks
        // finished but isn't (issue #657 acceptance criterion 5).
        if (!rename($tmpZipPath, $finalPath)) {
            throw new \RuntimeException(\sprintf('Unable to move the finished archive to "%s".', $finalPath));
        }

        return $skipped;
    }

    /**
     * @return list<array{relativePath: string, fullPath: string, size: int}>
     */
    private function collectMediaEntries(\PDO $snapshot): array
    {
        $entries = [];

        $covers = $snapshot->query('SELECT id, cover FROM anime WHERE cover IS NOT NULL');
        foreach ($covers as $row) {
            $entries[] = $this->buildMediaEntry((string) $row['id'], (string) $row['cover']);
        }

        $images = $snapshot->query('SELECT anime_id, source FROM anime_image');
        foreach ($images as $row) {
            $entries[] = $this->buildMediaEntry((string) $row['anime_id'], (string) $row['source']);
        }

        return $entries;
    }

    /**
     * @return array{relativePath: string, fullPath: string, size: int}
     */
    private function buildMediaEntry(string $animeId, string $filename): array
    {
        // Same "id/filename" layout native/protocols/app-media.js resolves app-media:// URLs
        // against — see Anime::$cover's docblock.
        $relativePath = $animeId.'/'.$filename;
        $fullPath = rtrim($this->mediaDir, '/\\').'/'.$relativePath;
        $size = is_file($fullPath) ? (int) (@filesize($fullPath) ?: 0) : 0;

        return ['relativePath' => $relativePath, 'fullPath' => $fullPath, 'size' => $size];
    }

    /**
     * @throws InsufficientDiskSpaceException
     */
    private function assertEnoughFreeSpace(string $destinationDir, int $estimatedBytes): void
    {
        $free = $this->freeSpaceProvider->getFreeBytes($destinationDir);

        // Unlike FreeSpaceChecker::hasEnoughFreeSpace(), unknown free space here does NOT fail
        // open: this check runs synchronously right before the only write into the destination
        // directory, so there is no later real-write backstop the way qBittorrent's own ENOSPC
        // handling backstops FreeSpaceChecker's smoothing. Fail closed with a clear message
        // instead (issue #657 acceptance criterion 6).
        if ($free === null || $free < $estimatedBytes + self::MIN_OVERHEAD_BYTES) {
            throw new InsufficientDiskSpaceException(\sprintf('The export needs approximately %d bytes (+ overhead) but the destination volume has %s free.', $estimatedBytes, $free === null ? 'an unknown amount of' : \sprintf('only %d bytes', $free)));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildManifest(\DateTimeImmutable $now, int $animeCount, int $mediaFileCount): array
    {
        return [
            'formatVersion' => self::FORMAT_VERSION,
            'app' => [
                'version' => $this->coreVersion,
                'lastMigration' => $this->resolveLastMigration(),
            ],
            'createdAt' => $now->format('Y-m-d\TH:i:s\Z'),
            'counts' => [
                'anime' => $animeCount,
                'mediaFiles' => $mediaFileCount,
            ],
            'plugins' => array_map(
                static fn (InstalledPlugin $plugin): array => [
                    'id' => (string) $plugin->id,
                    'version' => $plugin->manifest->version,
                ],
                $this->pluginsRegistry->all(),
            ),
        ];
    }

    /**
     * The latest applied migration version, e.g. "Version20260917120000" — read from Doctrine
     * Migrations' own bookkeeping table ({@see \Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration}'s
     * defaults, never overridden by config/packages/doctrine_migrations.yaml in this app), not
     * tracked anywhere else. Returns null rather than throwing on a database old enough, or broken
     * enough, not to have the table at all — a manifest field being absent is far less surprising
     * than an export failing entirely over metadata that isn't essential to the archive itself.
     */
    private function resolveLastMigration(): ?string
    {
        try {
            $version = $this->connection->fetchOne('SELECT version FROM doctrine_migration_versions ORDER BY version DESC LIMIT 1');
        } catch (\Throwable) {
            return null;
        }

        return $version !== false ? (string) $version : null;
    }
}
