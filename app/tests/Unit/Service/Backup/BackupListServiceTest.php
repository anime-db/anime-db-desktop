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

namespace App\Tests\Unit\Service\Backup;

use App\Service\Backup\BackupListService;
use PHPUnit\Framework\TestCase;

final class BackupListServiceTest extends TestCase
{
    private string $backupsDir;

    protected function setUp(): void
    {
        $this->backupsDir = sys_get_temp_dir().'/animedb-backup-list-test-'.uniqid();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->backupsDir);
    }

    public function testListReturnsAnEmptyArrayWhenTheBackupsDirectoryDoesNotExist(): void
    {
        self::assertSame([], (new BackupListService($this->backupsDir))->list());
    }

    public function testListReturnsAnEmptyArrayForAnEmptyBackupsDirectory(): void
    {
        mkdir($this->backupsDir, 0o755, true);

        self::assertSame([], (new BackupListService($this->backupsDir))->list());
    }

    public function testListIgnoresFilesThatDoNotMatchTheBackupNamingPattern(): void
    {
        mkdir($this->backupsDir, 0o755, true);
        file_put_contents($this->backupsDir.'/notes.txt', 'not a backup');
        file_put_contents($this->backupsDir.'/data.db', 'not a timestamped snapshot');

        self::assertSame([], (new BackupListService($this->backupsDir))->list());
    }

    public function testListReportsDateAndSizeAndFlagsPreImportSnapshotsSeparately(): void
    {
        mkdir($this->backupsDir, 0o755, true);
        file_put_contents($this->backupsDir.'/data-1.2.3-20260101-120000.db', str_repeat('a', 100));
        file_put_contents($this->backupsDir.'/data-preimport-20260102-093000.db', str_repeat('b', 250));

        $snapshots = (new BackupListService($this->backupsDir))->list();

        self::assertCount(2, $snapshots);

        $routine = self::findByName($snapshots, 'data-1.2.3-20260101-120000.db');
        self::assertFalse($routine->isPreImport);
        self::assertSame(100, $routine->sizeBytes);
        self::assertSame('2026-01-01T12:00:00', $routine->createdAt->format('Y-m-d\TH:i:s'));

        $preImport = self::findByName($snapshots, 'data-preimport-20260102-093000.db');
        self::assertTrue($preImport->isPreImport);
        self::assertSame(250, $preImport->sizeBytes);
        self::assertSame('2026-01-02T09:30:00', $preImport->createdAt->format('Y-m-d\TH:i:s'));
    }

    public function testListOrdersSnapshotsNewestFirst(): void
    {
        mkdir($this->backupsDir, 0o755, true);
        file_put_contents($this->backupsDir.'/data-1.2.3-20260101-000000.db', 'a');
        file_put_contents($this->backupsDir.'/data-preimport-20260103-000000.db', 'b');
        file_put_contents($this->backupsDir.'/data-1.2.3-20260102-000000.db', 'c');

        $names = array_map(static fn ($snapshot) => $snapshot->name, (new BackupListService($this->backupsDir))->list());

        self::assertSame([
            'data-preimport-20260103-000000.db',
            'data-1.2.3-20260102-000000.db',
            'data-1.2.3-20260101-000000.db',
        ], $names);
    }

    /**
     * @param list<\App\Service\Backup\BackupSnapshot> $snapshots
     */
    private static function findByName(array $snapshots, string $name): \App\Service\Backup\BackupSnapshot
    {
        foreach ($snapshots as $snapshot) {
            if ($snapshot->name === $name) {
                return $snapshot;
            }
        }

        self::fail(\sprintf('No snapshot named "%s" in the list.', $name));
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
