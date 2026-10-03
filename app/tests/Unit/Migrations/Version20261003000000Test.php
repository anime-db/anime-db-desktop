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
use DoctrineMigrations\Version20261003000000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__.'/../../../migrations/Version20261003000000.php';

/**
 * Runs Version20261003000000::up() (downloads.target_storage_id/failure_reason, issue #851)
 * against a real SQLite connection representing the pre-migration `downloads` shape (as it was
 * right after Version20261002000000): a pre-#851 Pending row never has a target storage, so it
 * must come out the other end as Failed/legacy_layout, while an already-Completed row (which has
 * its own #837 completion snapshot and no use for a target storage) must be left untouched.
 */
final class Version20261003000000Test extends TestCase
{
    private const string HASH_PENDING = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string HASH_COMPLETED = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');

        $this->connection->executeStatement('CREATE TABLE anime (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            title VARCHAR(256) NOT NULL
        )');
        $this->connection->executeStatement('CREATE TABLE storage (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            name VARCHAR(256) NOT NULL,
            path VARCHAR(1024) NOT NULL
        )');
        $this->connection->executeStatement('CREATE TABLE downloads (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            info_hash VARCHAR(40) NOT NULL,
            anime_id INTEGER NOT NULL,
            status VARCHAR(16) NOT NULL,
            date_add INTEGER NOT NULL,
            storage_id INTEGER DEFAULT NULL REFERENCES storage (id) ON DELETE SET NULL,
            storage_path VARCHAR(1024) DEFAULT NULL,
            version INTEGER DEFAULT 1 NOT NULL,
            CONSTRAINT FK_DOWNLOAD_ANIME FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE
        )');
        $this->connection->executeStatement('CREATE UNIQUE INDEX uniq_download_infohash ON downloads (info_hash)');
    }

    public function testUpFailsALegacyPendingRowWithNoTargetStorage(): void
    {
        $animeId = $this->insertAnime();
        $this->insertDownload(self::HASH_PENDING, $animeId, 'pending');

        $this->runUp();

        $row = $this->connection->fetchAssociative('SELECT status, failure_reason FROM downloads WHERE info_hash = ?', [self::HASH_PENDING]);
        $this->assertIsArray($row);
        $this->assertSame('failed', $row['status']);
        $this->assertSame('legacy_layout', $row['failure_reason']);
    }

    public function testUpLeavesACompletedRowUntouched(): void
    {
        $animeId = $this->insertAnime();
        $storageId = $this->insertStorage();
        $this->connection->executeStatement(
            'INSERT INTO downloads (info_hash, anime_id, status, date_add, storage_id, storage_path) VALUES (?, ?, ?, 0, ?, ?)',
            [self::HASH_COMPLETED, $animeId, 'completed', $storageId, 'Some.Release'],
        );

        $this->runUp();

        $row = $this->connection->fetchAssociative('SELECT status, failure_reason, target_storage_id FROM downloads WHERE info_hash = ?', [self::HASH_COMPLETED]);
        $this->assertIsArray($row);
        $this->assertSame('completed', $row['status']);
        $this->assertNull($row['failure_reason']);
        $this->assertNull($row['target_storage_id']);
    }

    public function testUpAddsTheTargetStorageColumnReferencingStorage(): void
    {
        $this->runUp();

        $storageId = $this->insertStorage();
        $animeId = $this->insertAnime();
        $this->connection->executeStatement(
            'INSERT INTO downloads (info_hash, anime_id, status, date_add, target_storage_id) VALUES (?, ?, ?, 0, ?)',
            [self::HASH_PENDING, $animeId, 'pending', $storageId],
        );

        $this->connection->executeStatement("DELETE FROM storage WHERE id = {$storageId}");

        $this->assertNull($this->connection->fetchOne('SELECT target_storage_id FROM downloads WHERE info_hash = ?', [self::HASH_PENDING]));
    }

    private function insertAnime(): int
    {
        $this->connection->executeStatement('INSERT INTO anime (title) VALUES (?)', ['Trigun']);

        return (int) $this->connection->lastInsertId();
    }

    private function insertStorage(): int
    {
        $this->connection->executeStatement("INSERT INTO storage (name, path) VALUES ('AnimeDB', 'D:\\Anime')");

        return (int) $this->connection->lastInsertId();
    }

    private function insertDownload(string $infoHash, int $animeId, string $status): void
    {
        $this->connection->executeStatement(
            'INSERT INTO downloads (info_hash, anime_id, status, date_add) VALUES (?, ?, ?, 0)',
            [$infoHash, $animeId, $status],
        );
    }

    private function runUp(): void
    {
        $migration = new Version20261003000000($this->connection, new NullLogger());
        $schema = new Schema();

        $migration->up($schema);
        $this->runQueries($migration->getSql());
        $migration->freeze();
        $migration->postUp($schema);
    }

    /** @param Query[] $queries */
    private function runQueries(array $queries): void
    {
        foreach ($queries as $query) {
            $this->connection->executeQuery($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
