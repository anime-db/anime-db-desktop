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

use App\Service\Import\StagedImportService;
use PHPUnit\Framework\TestCase;

final class StagedImportServiceTest extends TestCase
{
    private string $importStagingDir;

    protected function setUp(): void
    {
        $this->importStagingDir = sys_get_temp_dir().'/animedb-staged-import-test-'.uniqid();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->importStagingDir);
    }

    public function testReadMarkerReturnsNullWhenStagingDirDoesNotExist(): void
    {
        self::assertNull($this->createService()->readMarker());
    }

    public function testReadMarkerReturnsNullWhenMarkerFileIsMissing(): void
    {
        mkdir($this->importStagingDir, 0o755, true);

        self::assertNull($this->createService()->readMarker());
    }

    public function testReadMarkerReturnsNullWhenMarkerIsNotValidJson(): void
    {
        mkdir($this->importStagingDir, 0o755, true);
        file_put_contents($this->importStagingDir.'/import.json', 'not json');

        self::assertNull($this->createService()->readMarker());
    }

    public function testReadMarkerReturnsNullWhenRequiredFieldsAreMissing(): void
    {
        mkdir($this->importStagingDir, 0o755, true);
        file_put_contents($this->importStagingDir.'/import.json', json_encode(['markerVersion' => 1]));

        self::assertNull($this->createService()->readMarker());
    }

    public function testReadMarkerReturnsNullWhenStagedAtIsNotAParseableDate(): void
    {
        mkdir($this->importStagingDir, 0o755, true);
        file_put_contents($this->importStagingDir.'/import.json', json_encode([
            'stagedAt' => 'not-a-date',
            'sourceArchive' => 'catalog.zip',
        ]));

        self::assertNull($this->createService()->readMarker());
    }

    public function testReadMarkerReturnsTheStagedAtAndSourceArchiveFromAValidMarker(): void
    {
        mkdir($this->importStagingDir, 0o755, true);
        file_put_contents($this->importStagingDir.'/import.json', json_encode([
            'markerVersion' => 1,
            'stagedAt' => '2026-09-18T12:34:56Z',
            'sourceArchive' => 'catalog-export.zip',
        ]));

        $marker = $this->createService()->readMarker();

        self::assertNotNull($marker);
        self::assertSame('2026-09-18T12:34:56+00:00', $marker->stagedAt->format('Y-m-d\TH:i:sP'));
        self::assertSame('catalog-export.zip', $marker->sourceArchive);
    }

    public function testCancelRemovesTheStagingDirEntirely(): void
    {
        mkdir($this->importStagingDir.'/media', 0o755, true);
        file_put_contents($this->importStagingDir.'/data.db', 'db-bytes');
        file_put_contents($this->importStagingDir.'/import.json', json_encode([
            'stagedAt' => '2026-09-18T12:34:56Z',
            'sourceArchive' => 'catalog.zip',
        ]));

        $this->createService()->cancel();

        self::assertDirectoryDoesNotExist($this->importStagingDir);
    }

    public function testCancelDoesNothingWhenStagingDirDoesNotExist(): void
    {
        $this->createService()->cancel();

        self::assertDirectoryDoesNotExist($this->importStagingDir);
    }

    private function createService(): StagedImportService
    {
        return new StagedImportService($this->importStagingDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.\DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
