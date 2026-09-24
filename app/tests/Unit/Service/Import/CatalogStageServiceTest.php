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

namespace App\Tests\Unit\Service\Import;

use App\Service\Import\CatalogStageService;
use App\Service\Import\Exception\InvalidCatalogArchiveException;
use App\Service\WsPublisher;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class CatalogStageServiceTest extends TestCase
{
    private string $fixturesDir;
    private string $importStagingDir;
    private string $importRejectionPath;

    protected function setUp(): void
    {
        $this->fixturesDir = sys_get_temp_dir().'/animedb-stage-test-fixtures-'.uniqid();
        $this->importStagingDir = sys_get_temp_dir().'/animedb-stage-test-staging-'.uniqid();
        $this->importRejectionPath = sys_get_temp_dir().'/animedb-stage-test-rejection-'.uniqid().'.json';
        mkdir($this->fixturesDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->fixturesDir);
        $this->removeDirectory($this->importStagingDir);
        @unlink($this->importRejectionPath);
        // The zip-slip test writes one level above importStagingDir when the guard fails to stop it.
        @unlink(\dirname($this->importStagingDir).'/escaped.txt');
    }

    public function testCorruptArchiveIsRejectedAndStagingDirIsNotCreated(): void
    {
        $archivePath = $this->fixturesDir.'/corrupt.zip';
        file_put_contents($archivePath, 'this is not a zip file');

        try {
            $this->createService()->stage($archivePath);
            $this->fail('Expected InvalidCatalogArchiveException.');
        } catch (InvalidCatalogArchiveException $exception) {
            $this->assertSame(InvalidCatalogArchiveException::REASON_UNREADABLE, $exception->reasonKey);
        }

        $this->assertDirectoryDoesNotExist($this->importStagingDir);
    }

    public function testArchiveWithoutManifestIsRejectedAndStagingDirIsNotCreated(): void
    {
        $archivePath = $this->buildArchive(null, $this->sqliteDbBytes(0));

        try {
            $this->createService()->stage($archivePath);
            $this->fail('Expected InvalidCatalogArchiveException.');
        } catch (InvalidCatalogArchiveException $exception) {
            $this->assertSame(InvalidCatalogArchiveException::REASON_MISSING_MANIFEST, $exception->reasonKey);
        }

        $this->assertDirectoryDoesNotExist($this->importStagingDir);
    }

    public function testArchiveWithUnsupportedFormatVersionIsRejectedAndStagingDirIsNotCreated(): void
    {
        $archivePath = $this->buildArchive($this->defaultManifest(['formatVersion' => 99]), $this->sqliteDbBytes(0));

        try {
            $this->createService()->stage($archivePath);
            $this->fail('Expected InvalidCatalogArchiveException.');
        } catch (InvalidCatalogArchiveException $exception) {
            $this->assertSame(InvalidCatalogArchiveException::REASON_UNSUPPORTED_FORMAT_VERSION, $exception->reasonKey);
        }

        $this->assertDirectoryDoesNotExist($this->importStagingDir);
    }

    public function testArchiveWithoutDatabaseIsRejectedAndStagingDirIsNotCreated(): void
    {
        $archivePath = $this->buildArchive($this->defaultManifest(), null);

        try {
            $this->createService()->stage($archivePath);
            $this->fail('Expected InvalidCatalogArchiveException.');
        } catch (InvalidCatalogArchiveException $exception) {
            $this->assertSame(InvalidCatalogArchiveException::REASON_MISSING_DATABASE, $exception->reasonKey);
        }

        $this->assertDirectoryDoesNotExist($this->importStagingDir);
    }

    public function testArchiveWithUnsafeEntryNameIsRejectedAndNothingIsWrittenOutsideStaging(): void
    {
        $archivePath = $this->buildArchive(
            $this->defaultManifest(),
            $this->sqliteDbBytes(0),
            extraEntries: ['../escaped.txt' => 'zip-slip payload'],
        );

        try {
            $this->createService()->stage($archivePath);
            $this->fail('Expected InvalidCatalogArchiveException.');
        } catch (InvalidCatalogArchiveException $exception) {
            $this->assertSame(InvalidCatalogArchiveException::REASON_UNSAFE_ENTRY, $exception->reasonKey);
        }

        $this->assertDirectoryDoesNotExist($this->importStagingDir);
        $this->assertFileDoesNotExist(\dirname($this->importStagingDir).'/escaped.txt');
    }

    public function testCountsMismatchIsLoggedButDoesNotRejectTheArchive(): void
    {
        $archivePath = $this->buildArchive(
            $this->defaultManifest(['counts' => ['anime' => 5, 'mediaFiles' => 3]]),
            $this->sqliteDbBytes(1),
            ['1/cover.webp' => 'cover-bytes'],
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('warning');

        $result = $this->createService($logger)->stage($archivePath);

        $this->assertSame(1, $result->animeCount);
        $this->assertSame(1, $result->mediaFileCount);
    }

    public function testSuccessfulStagingProducesDataDbMediaAndAValidMarker(): void
    {
        $archivePath = $this->buildArchive(
            $this->defaultManifest(['counts' => ['anime' => 1, 'mediaFiles' => 1]]),
            $this->sqliteDbBytes(1),
            ['1/cover.webp' => 'cover-bytes'],
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $result = $this->createService($logger)->stage($archivePath);

        $this->assertSame($this->importStagingDir, $result->stagingDir);
        $this->assertSame(1, $result->animeCount);
        $this->assertSame(1, $result->mediaFileCount);

        $this->assertFileExists($this->importStagingDir.'/data.db');
        $this->assertFileExists($this->importStagingDir.'/media/1/cover.webp');
        $this->assertSame('cover-bytes', file_get_contents($this->importStagingDir.'/media/1/cover.webp'));

        // Issue #726: manifest.json is extracted alongside data.db/media/, for
        // native/supervisor/import-apply.js to later carry over to import-applied.json.
        $stagedManifestPath = $this->importStagingDir.'/manifest.json';
        $this->assertFileExists($stagedManifestPath);
        $this->assertSame(
            $this->defaultManifest(['counts' => ['anime' => 1, 'mediaFiles' => 1]]),
            json_decode((string) file_get_contents($stagedManifestPath), true, flags: \JSON_THROW_ON_ERROR),
        );

        $markerPath = $this->importStagingDir.'/import.json';
        $this->assertFileExists($markerPath);
        $marker = json_decode((string) file_get_contents($markerPath), true, flags: \JSON_THROW_ON_ERROR);

        $this->assertSame(1, $marker['markerVersion']);
        $this->assertSame(basename($archivePath), $marker['sourceArchive']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $marker['stagedAt']);
    }

    /**
     * Acceptance criterion 9 (issue #726): staging a new import must never remove
     * `userData/import-applied.json` — that file is only carried over by
     * native/supervisor/import-apply.js on a successful *apply*, and describes whatever import
     * was applied last, independent of what is currently being staged (which may never even be
     * applied at all).
     */
    public function testStagingDoesNotTouchAnUnrelatedImportAppliedFile(): void
    {
        $importAppliedPath = \dirname($this->importStagingDir).'/import-applied-'.uniqid().'.json';
        file_put_contents($importAppliedPath, '{"plugins":[{"id":"animedb-shikimori"}]}');

        try {
            $this->createService()->stage($this->buildArchive($this->defaultManifest(), $this->sqliteDbBytes(0)));

            $this->assertFileExists($importAppliedPath);
            $this->assertSame('{"plugins":[{"id":"animedb-shikimori"}]}', file_get_contents($importAppliedPath));
        } finally {
            @unlink($importAppliedPath);
        }
    }

    public function testRepeatedStagingReplacesThePreviousStagingContentsInsteadOfMerging(): void
    {
        $firstArchive = $this->buildArchive(
            $this->defaultManifest(['counts' => ['anime' => 1, 'mediaFiles' => 1]]),
            $this->sqliteDbBytes(1),
            ['1/cover.webp' => 'first-cover'],
        );
        $secondArchive = $this->buildArchive(
            $this->defaultManifest(['counts' => ['anime' => 2, 'mediaFiles' => 1]]),
            $this->sqliteDbBytes(2),
            ['2/cover.webp' => 'second-cover'],
        );

        $service = $this->createService();
        $service->stage($firstArchive);
        $result = $service->stage($secondArchive);

        $this->assertSame(2, $result->animeCount);
        $this->assertFileDoesNotExist($this->importStagingDir.'/media/1/cover.webp');
        $this->assertFileExists($this->importStagingDir.'/media/2/cover.webp');
        $this->assertSame(basename($secondArchive), json_decode(
            (string) file_get_contents($this->importStagingDir.'/import.json'),
            true,
            flags: \JSON_THROW_ON_ERROR,
        )['sourceArchive']);
    }

    public function testStagingRemovesAStaleRejectionReasonLeftByAPreviousDecision(): void
    {
        file_put_contents($this->importRejectionPath, json_encode(['reason' => 'incompatible_schema']));

        $this->createService()->stage($this->buildArchive($this->defaultManifest(), $this->sqliteDbBytes(1)));

        $this->assertFileDoesNotExist($this->importRejectionPath);
    }

    public function testStagingPublishesImportProgressEventsForTheDatabaseAndEachMediaFile(): void
    {
        $archivePath = $this->buildArchive(
            $this->defaultManifest(['counts' => ['anime' => 1, 'mediaFiles' => 2]]),
            $this->sqliteDbBytes(1),
            ['1/cover.webp' => 'cover-bytes', '1/gallery1.webp' => 'gallery-bytes'],
        );

        /** @var \ArrayObject<int, array{event: string, data: mixed}> $events */
        $events = new \ArrayObject();
        $wsPublisher = new class(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $events) extends WsPublisher {
            /**
             * @param \ArrayObject<int, array{event: string, data: mixed}> $events
             */
            public function __construct(Connection $connection, private readonly \ArrayObject $events)
            {
                parent::__construct($connection);
            }

            public function publish(string $event, mixed $data): void
            {
                $this->events[] = ['event' => $event, 'data' => $data];
                parent::publish($event, $data);
            }
        };

        $this->createService(wsPublisher: $wsPublisher)->stage($archivePath);

        $progressEvents = array_values(array_filter(
            $events->getArrayCopy(),
            static fn (array $event): bool => $event['event'] === 'import.progress',
        ));
        $this->assertCount(3, $progressEvents);
        $this->assertSame('database', $progressEvents[0]['data']['phase']);
        $this->assertSame(['phase' => 'media', 'current' => 1, 'total' => 2], $progressEvents[1]['data']);
        $this->assertSame(['phase' => 'media', 'current' => 2, 'total' => 2], $progressEvents[2]['data']);
    }

    public function testStagingManyMediaFilesKeepsPeakMemoryUsageBoundedInsteadOfGrowingWithTheirTotalSize(): void
    {
        $fileCount = 40;
        $fileSize = 256 * 1024; // 10 MB of incompressible media across all entries.
        $blob = random_bytes($fileSize); // Reused across entries: ZIP compresses each entry on
        // its own, so the archive still grows with $fileCount even though the source blob lives
        // in memory only once.

        $mediaEntries = [];
        for ($i = 1; $i <= $fileCount; ++$i) {
            $mediaEntries[$i.'/cover.webp'] = $blob;
        }

        $archivePath = $this->buildArchive(
            $this->defaultManifest(['counts' => ['anime' => 0, 'mediaFiles' => $fileCount]]),
            $this->sqliteDbBytes(0),
            $mediaEntries,
        );

        // random_bytes() is incompressible, so a naive str_repeat() filler — which ZIP would
        // deflate to almost nothing — could not accidentally make this test measure an empty
        // archive instead of a large one.
        $this->assertGreaterThan((int) ($fileCount * $fileSize * 0.9), filesize($archivePath));

        memory_reset_peak_usage();
        $peakBeforeStage = memory_get_peak_usage(true);

        $result = $this->createService()->stage($archivePath);

        $peakAfterStage = memory_get_peak_usage(true);

        $this->assertSame($fileCount, $result->mediaFileCount);
        // Regression guard for issue #740: extract() must extractTo() each media entry straight
        // to disk instead of reading it fully into memory (e.g. via getFromName()), so peak
        // memory growth stays bounded by roughly one entry's size instead of scaling with the
        // archive's total media size ($fileCount * $fileSize here).
        $this->assertLessThan($fileSize * 4, $peakAfterStage - $peakBeforeStage);
    }

    private function createService(?LoggerInterface $logger = null, ?WsPublisher $wsPublisher = null): CatalogStageService
    {
        return new CatalogStageService(
            $wsPublisher ?? new WsPublisher(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true])),
            $logger ?? new NullLogger(),
            $this->importStagingDir,
            $this->importRejectionPath,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function defaultManifest(array $overrides = []): array
    {
        return array_replace_recursive([
            'formatVersion' => 1,
            'counts' => ['anime' => 0, 'mediaFiles' => 0],
        ], $overrides);
    }

    /**
     * @param array<string, mixed>|null $manifest     null omits manifest.json from the archive entirely
     * @param array<string, string>     $mediaEntries relative path under media/ => content
     * @param array<string, string>     $extraEntries raw entry name => content, added exactly as given
     */
    private function buildArchive(?array $manifest, ?string $dbBytes, array $mediaEntries = [], array $extraEntries = []): string
    {
        $path = $this->fixturesDir.'/'.uniqid('archive-', true).'.zip';

        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);

        if ($dbBytes !== null) {
            $zip->addFromString('data.db', $dbBytes);
        }

        foreach ($mediaEntries as $relativePath => $content) {
            $zip->addFromString('media/'.$relativePath, $content);
        }

        if ($manifest !== null) {
            $zip->addFromString('manifest.json', json_encode($manifest, \JSON_THROW_ON_ERROR));
        }

        foreach ($extraEntries as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();

        return $path;
    }

    private function sqliteDbBytes(int $animeCount): string
    {
        $path = $this->fixturesDir.'/'.uniqid('db-', true).'.db';
        $pdo = new \PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE anime (id INTEGER PRIMARY KEY, title TEXT)');
        for ($i = 1; $i <= $animeCount; ++$i) {
            $statement = $pdo->prepare('INSERT INTO anime (id, title) VALUES (:id, :title)');
            $statement->execute(['id' => $i, 'title' => 'Anime '.$i]);
        }
        $pdo = null;

        $bytes = file_get_contents($path);

        return $bytes === false ? '' : $bytes;
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
