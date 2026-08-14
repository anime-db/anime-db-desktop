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

namespace App\Tests\Unit\Command;

use App\Command\DatabaseBackupCommand;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DatabaseBackupCommandTest extends TestCase
{
    private string $dbPath;
    private string $backupPath;

    protected function setUp(): void
    {
        $srcStub = tempnam(sys_get_temp_dir(), 'animedb_backup_src_');
        $dstStub = tempnam(sys_get_temp_dir(), 'animedb_backup_dst_');
        $this->dbPath = $srcStub.'.db';
        $this->backupPath = $dstStub.'.db';
        unlink($srcStub);
        unlink($dstStub);
    }

    protected function tearDown(): void
    {
        @unlink($this->dbPath);
        @unlink($this->backupPath);
    }

    public function testWritesAConsistentSnapshotToTheGivenPath(): void
    {
        $connection = $this->createConnection($this->dbPath);
        $connection->executeStatement('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
        $connection->executeStatement("INSERT INTO t (v) VALUES ('hello')");

        $tester = new CommandTester(new DatabaseBackupCommand($connection));
        $tester->execute(['path' => $this->backupPath]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertFileExists($this->backupPath);

        $backupConnection = $this->createConnection($this->backupPath);
        $row = $backupConnection->fetchAssociative('SELECT v FROM t');
        $this->assertIsArray($row);
        $this->assertSame('hello', $row['v']);
    }

    private function createConnection(string $path): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
    }
}
