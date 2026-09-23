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

namespace App\Tests\Unit\Service\Export;

use App\Service\Download\FreeSpaceProvider;
use App\Service\Export\CatalogExportService;
use App\Service\Export\Exception\InsufficientDiskSpaceException;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\WsPublisher;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class CatalogExportServiceTest extends TestCase
{
    private string $dbPath;
    private string $mediaDir;
    private string $destinationDir;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir().'/animedb-export-test-'.uniqid().'.db';
        $this->mediaDir = sys_get_temp_dir().'/animedb-export-test-media-'.uniqid();
        $this->destinationDir = sys_get_temp_dir().'/animedb-export-test-dest-'.uniqid();
        mkdir($this->mediaDir, 0o755, true);
        mkdir($this->destinationDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->mediaDir);
        $this->removeDirectory($this->destinationDir);
        @unlink($this->dbPath);
    }

    public function testExportProducesArchiveWithExpectedContentsAndExcludesSecrets(): void
    {
        $connection = $this->createConnection();
        $this->seedSchema($connection);
        $connection->insert('anime', ['id' => 1, 'title' => 'Cowboy Bebop', 'cover' => 'cover.webp']);
        $connection->insert('anime_image', ['id' => 1, 'anime_id' => 1, 'source' => 'gallery1.webp']);
        $connection->insert('doctrine_migration_versions', ['version' => 'Version20260917120000']);
        mkdir($this->mediaDir.'/1', 0o755, true);
        file_put_contents($this->mediaDir.'/1/cover.webp', 'cover-bytes');
        file_put_contents($this->mediaDir.'/1/gallery1.webp', 'gallery-bytes');

        $result = $this->createService($connection)->export($this->destinationDir);

        $this->assertFileExists($result->archivePath);
        $this->assertSame(1, $result->animeCount);
        $this->assertSame(2, $result->mediaFileCount);
        $this->assertSame(0, $result->skippedMediaFiles);

        $zip = new \ZipArchive();
        $zip->open($result->archivePath);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $names[] = $zip->getNameIndex($i);
        }

        $this->assertContains('data.db', $names);
        $this->assertContains('media/1/cover.webp', $names);
        $this->assertContains('media/1/gallery1.webp', $names);
        $this->assertContains('manifest.json', $names);
        $this->assertNotContains('config.json', $names);
        $this->assertNotContains('plugins.json', $names);

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: \JSON_THROW_ON_ERROR);
        $zip->close();

        $this->assertSame(1, $manifest['formatVersion']);
        $this->assertSame('2.0.0', $manifest['app']['version']);
        $this->assertSame('Version20260917120000', $manifest['app']['lastMigration']);
        $this->assertSame(1, $manifest['counts']['anime']);
        $this->assertSame(2, $manifest['counts']['mediaFiles']);
        $this->assertSame([], $manifest['plugins']);
    }

    /**
     * Acceptance criterion 13 (issue #726): the plugin list App\Service\Import\ImportedPluginsService
     * shows after an import falls back to the bare id whenever an archive's manifest carries no
     * "name" — exporting one is what lets a future import show the friendlier display name instead.
     */
    public function testManifestPluginsEntryIncludesTheInstalledPluginsDisplayName(): void
    {
        $connection = $this->createConnection();
        $this->seedSchema($connection);

        $pluginsDir = sys_get_temp_dir().'/animedb-export-test-plugins-'.uniqid();
        mkdir($pluginsDir.'/animedb-shikimori', 0o755, true);
        file_put_contents($pluginsDir.'/animedb-shikimori/manifest.json', (string) json_encode([
            'id' => 'animedb-shikimori',
            'name' => 'Shikimori',
            'version' => '1.2.3',
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));
        $pluginsRegistry = new InstalledPluginsRegistry(
            $pluginsDir,
            new PluginsConfigStore($pluginsDir.'/plugins.json'),
            new NullLogger(),
        );
        $pluginsRegistry->reconcile();

        try {
            $result = $this->createService($connection, pluginsRegistry: $pluginsRegistry)->export($this->destinationDir);

            $zip = new \ZipArchive();
            $zip->open($result->archivePath);
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: \JSON_THROW_ON_ERROR);
            $zip->close();

            $this->assertSame([
                ['id' => 'animedb-shikimori', 'version' => '1.2.3', 'name' => 'Shikimori'],
            ], $manifest['plugins']);
        } finally {
            $this->removeDirectory($pluginsDir);
        }
    }

    public function testExportedArchiveContainsAWorkingDatabaseWithTheSameAnimeCountAsTheSource(): void
    {
        $connection = $this->createConnection();
        $this->seedSchema($connection);
        $connection->insert('anime', ['id' => 1, 'title' => 'A']);
        $connection->insert('anime', ['id' => 2, 'title' => 'B']);

        $result = $this->createService($connection)->export($this->destinationDir);

        $zip = new \ZipArchive();
        $zip->open($result->archivePath);
        $extractedDbPath = $this->destinationDir.'/extracted.db';
        file_put_contents($extractedDbPath, (string) $zip->getFromName('data.db'));
        $zip->close();

        $extracted = new \PDO('sqlite:'.$extractedDbPath);
        $statement = $extracted->query('SELECT COUNT(*) FROM anime');
        $this->assertInstanceOf(\PDOStatement::class, $statement);
        $this->assertSame(2, (int) $statement->fetchColumn());
    }

    public function testExportSkipsAnUnreadableMediaFileAndLogsAWarningInsteadOfFailing(): void
    {
        $connection = $this->createConnection();
        $this->seedSchema($connection);
        $connection->insert('anime', ['id' => 1, 'title' => 'A', 'cover' => 'missing.webp']);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $result = $this->createService($connection, $logger)->export($this->destinationDir);

        $this->assertSame(1, $result->skippedMediaFiles);

        $zip = new \ZipArchive();
        $zip->open($result->archivePath);
        $this->assertFalse($zip->locateName('media/1/missing.webp'));
        $zip->close();
    }

    public function testExportOfManyMediaFilesKeepsPeakMemoryUsageBoundedInsteadOfGrowingWithTheirTotalSize(): void
    {
        $connection = $this->createConnection();
        $this->seedSchema($connection);

        $fileCount = 20;
        $fileSize = 1024 * 1024; // 20 MB of media across all files.
        $content = str_repeat('x', $fileSize);
        for ($i = 1; $i <= $fileCount; ++$i) {
            $connection->insert('anime', ['id' => $i, 'title' => 'Anime '.$i, 'cover' => 'cover.webp']);
            mkdir($this->mediaDir.'/'.$i, 0o755, true);
            file_put_contents($this->mediaDir.'/'.$i.'/cover.webp', $content);
        }
        unset($content);
        gc_collect_cycles();

        $peakBefore = memory_get_peak_usage(true);

        $result = $this->createService($connection)->export($this->destinationDir);

        $peakAfter = memory_get_peak_usage(true);

        $this->assertSame(0, $result->skippedMediaFiles);
        // Regression guard for issue #657's export OOM: writeArchive() must stream each media
        // file straight from disk instead of buffering its content in PHP memory, so peak memory
        // growth stays bounded by roughly one file's size instead of scaling with the archive's
        // total media size (20 MB across $fileCount files here).
        $this->assertLessThan($fileSize * 4, $peakAfter - $peakBefore);
    }

    public function testExportOfManyMediaFilesWritesTheArchiveInASingleCloseInsteadOfOnceEach(): void
    {
        $connection = $this->createConnection();
        $this->seedSchema($connection);

        $fileCount = 5;
        for ($i = 1; $i <= $fileCount; ++$i) {
            $connection->insert('anime', ['id' => $i, 'title' => 'Anime '.$i, 'cover' => 'cover.webp']);
            mkdir($this->mediaDir.'/'.$i, 0o755, true);
            file_put_contents($this->mediaDir.'/'.$i.'/cover.webp', 'cover-bytes-'.$i);
        }

        /** @var \ArrayObject<int, int|null> $tmpArchiveSizesDuringMediaPhase */
        $tmpArchiveSizesDuringMediaPhase = new \ArrayObject();
        $wsPublisher = new class(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $this->destinationDir, $tmpArchiveSizesDuringMediaPhase) extends WsPublisher {
            /**
             * @param \ArrayObject<int, int|null> $tmpArchiveSizesDuringMediaPhase
             */
            public function __construct(
                Connection $connection,
                private readonly string $destinationDir,
                private readonly \ArrayObject $tmpArchiveSizesDuringMediaPhase,
            ) {
                parent::__construct($connection);
            }

            public function publish(string $event, mixed $data): void
            {
                if ($event === 'export.progress' && \is_array($data) && ($data['phase'] ?? null) === 'media') {
                    $tmpZipPaths = glob($this->destinationDir.'/*.zip.tmp') ?: [];
                    // libzip does not create the underlying file at all until close() commits the
                    // whole archive, so the expected reading here is "missing", not "zero bytes" —
                    // recorded as null rather than 0 to keep that distinct from an actually-empty file.
                    $this->tmpArchiveSizesDuringMediaPhase[] = $tmpZipPaths === [] ? null : (int) filesize($tmpZipPaths[0]);
                }

                parent::publish($event, $data);
            }
        };

        $result = $this->createService($connection, wsPublisher: $wsPublisher)->export($this->destinationDir);

        $this->assertSame(0, $result->skippedMediaFiles);
        // Regression guard (issue #657 code review): a media file added via addFile() is only
        // committed to disk by the single close() in writeArchive(), so the temporary archive is
        // never partially written for any 'media' progress event queued before that close().
        // Reverting to a close()/reopen() per file — the change that made writeArchive() quadratic
        // in the number of media files — would make the temporary archive already exist with
        // non-zero size partway through the loop instead.
        $this->assertCount($fileCount, $tmpArchiveSizesDuringMediaPhase);
        foreach ($tmpArchiveSizesDuringMediaPhase as $size) {
            $this->assertTrue($size === null || $size === 0, 'The temporary archive must not be partially committed before writeArchive()\'s single close() call.');
        }
        $this->assertGreaterThan(0, filesize($result->archivePath));
    }

    public function testExportThrowsAndLeavesNothingInTheDestinationWhenTheVolumeDoesNotHaveEnoughFreeSpace(): void
    {
        $connection = $this->createConnection();
        $this->seedSchema($connection);
        $connection->insert('anime', ['id' => 1, 'title' => 'A']);

        $this->expectException(InsufficientDiskSpaceException::class);

        try {
            $this->createService($connection, freeBytes: 1)->export($this->destinationDir);
        } finally {
            $this->assertSame([], array_values(array_diff((array) scandir($this->destinationDir), ['.', '..'])));
        }
    }

    public function testExportThrowsAndLeavesNothingInTheDestinationWhenFreeSpaceCannotBeDetermined(): void
    {
        $connection = $this->createConnection();
        $this->seedSchema($connection);
        $connection->insert('anime', ['id' => 1, 'title' => 'A']);

        $this->expectException(InsufficientDiskSpaceException::class);

        try {
            $this->createService($connection, freeBytes: null)->export($this->destinationDir);
        } finally {
            $this->assertSame([], array_values(array_diff((array) scandir($this->destinationDir), ['.', '..'])));
        }
    }

    private function createService(Connection $connection, ?LoggerInterface $logger = null, ?int $freeBytes = \PHP_INT_MAX, ?WsPublisher $wsPublisher = null, ?InstalledPluginsRegistry $pluginsRegistry = null): CatalogExportService
    {
        $freeSpaceProvider = new class($freeBytes) implements FreeSpaceProvider {
            public function __construct(private readonly ?int $bytes)
            {
            }

            public function getFreeBytes(string $path): ?int
            {
                return $this->bytes;
            }
        };

        $pluginsRegistry ??= new InstalledPluginsRegistry(
            sys_get_temp_dir().'/animedb-export-test-plugins-does-not-exist',
            new PluginsConfigStore(sys_get_temp_dir().'/animedb-export-test-plugins-'.uniqid().'.json'),
            new NullLogger(),
        );

        return new CatalogExportService(
            $connection,
            $freeSpaceProvider,
            $wsPublisher ?? new WsPublisher(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true])),
            $pluginsRegistry,
            $logger ?? new NullLogger(),
            $this->mediaDir,
            '2.0.0',
        );
    }

    private function createConnection(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->dbPath]);
    }

    private function seedSchema(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE anime (id INTEGER PRIMARY KEY, title TEXT, cover TEXT)');
        $connection->executeStatement('CREATE TABLE anime_image (id INTEGER PRIMARY KEY, anime_id INTEGER, source TEXT)');
        $connection->executeStatement('CREATE TABLE doctrine_migration_versions (version TEXT PRIMARY KEY, executed_at TEXT)');
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (array_diff((array) scandir($dir), ['.', '..']) as $item) {
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
