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

namespace App\Tests\Unit\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Query\Query;
use DoctrineMigrations\Version20261007000000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__.'/../../../migrations/Version20261007000000.php';

/**
 * Runs Version20261007000000 (storage.path nullable, issue #957) against a populated SQLite
 * database: existing paths must survive and the referencing anime row must keep its storage_id.
 */
final class Version20261007000000Test extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');
        $this->connection->executeStatement('CREATE TABLE storage (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            name VARCHAR(256) NOT NULL,
            type VARCHAR(16) NOT NULL CHECK (type IN (\'folder\', \'external\', \'external-r\', \'video\')),
            path VARCHAR(1024) NOT NULL,
            date_update INTEGER DEFAULT NULL,
            file_modified INTEGER DEFAULT NULL
        )');
        $this->connection->executeStatement('CREATE TABLE anime (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            storage_id INTEGER DEFAULT NULL,
            CONSTRAINT FK_ANIME_STORAGE FOREIGN KEY (storage_id) REFERENCES storage (id) ON DELETE SET NULL
        )');
        $this->connection->executeStatement("INSERT INTO storage (id, name, type, path, date_update) VALUES (1, 'Main', 'folder', 'D:\\Anime', 5)");
        $this->connection->executeStatement('INSERT INTO anime (id, storage_id) VALUES (1, 1)');
    }

    public function testUpKeepsExistingRowsAndAllowsNullPath(): void
    {
        $this->migrate('up');

        $row = $this->connection->fetchAssociative('SELECT * FROM storage WHERE id = 1');
        $this->assertIsArray($row);
        $this->assertSame('D:\\Anime', $row['path']);
        $this->assertSame('folder', $row['type']);
        $this->assertSame(5, (int) $row['date_update']);
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT storage_id FROM anime WHERE id = 1'));

        $this->connection->executeStatement("INSERT INTO storage (name, type, path) VALUES ('Disc', 'video', NULL)");
        $this->assertNull($this->connection->fetchOne("SELECT path FROM storage WHERE name = 'Disc'"));
    }

    public function testDownFillsMissingPathsWithEmptyString(): void
    {
        $this->migrate('up');
        $this->connection->executeStatement("INSERT INTO storage (name, type, path) VALUES ('Disc', 'video', NULL)");

        $this->migrate('down');

        $this->assertSame('', $this->connection->fetchOne("SELECT path FROM storage WHERE name = 'Disc'"));
        $this->assertSame('D:\\Anime', $this->connection->fetchOne('SELECT path FROM storage WHERE id = 1'));
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT storage_id FROM anime WHERE id = 1'));
    }

    private function migrate(string $direction): void
    {
        $migration = new Version20261007000000($this->connection, new NullLogger());
        $schema = new Schema();

        $direction === 'up' ? $migration->up($schema) : $migration->down($schema);
        foreach ($migration->getSql() as $query) {
            /* @var Query $query */
            $this->connection->executeQuery($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        $migration->freeze();
        $direction === 'up' ? $migration->postUp($schema) : $migration->postDown($schema);
    }
}
