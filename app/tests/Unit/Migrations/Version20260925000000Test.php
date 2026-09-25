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
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Query\Query;
use DoctrineMigrations\Version20260925000000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__.'/../../../migrations/Version20260925000000.php';

/**
 * Runs Version20260925000000::up() (downloads.info_hash becomes UNIQUE) against a real SQLite
 * connection: its DELETE step irreversibly drops user rows, so it must be proven to keep exactly
 * the lowest-id row per info_hash and nothing else.
 */
final class Version20260925000000Test extends TestCase
{
    private const string HASH_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string HASH_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');

        $this->connection->executeStatement('CREATE TABLE anime (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            title VARCHAR(256) NOT NULL
        )');
        $this->connection->executeStatement('CREATE TABLE downloads (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            anime_id INTEGER NOT NULL,
            info_hash VARCHAR(40) NOT NULL,
            CONSTRAINT FK_DOWNLOAD_ANIME FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE
        )');
        $this->connection->executeStatement('CREATE UNIQUE INDEX uniq_download_infohash_anime ON downloads (info_hash, anime_id)');
        $this->connection->executeStatement('CREATE INDEX IDX_DOWNLOAD_INFO_HASH ON downloads (info_hash)');
    }

    public function testUpKeepsLowestIdPerInfoHashAndLeavesOthersIntact(): void
    {
        $animeIds = [$this->insertAnime(), $this->insertAnime(), $this->insertAnime(), $this->insertAnime()];
        $first = $this->insertDownload($animeIds[0], self::HASH_A);
        $this->insertDownload($animeIds[1], self::HASH_A);
        $this->insertDownload($animeIds[2], self::HASH_A);
        $single = $this->insertDownload($animeIds[3], self::HASH_B);

        $this->runUp();

        $ids = array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM downloads ORDER BY id'));
        $this->assertSame([$first, $single], $ids);
        $this->assertSame(4, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM anime'));
    }

    public function testUpMakesSecondLinkOfTheSameInfoHashRejected(): void
    {
        $animeOne = $this->insertAnime();
        $animeTwo = $this->insertAnime();
        $this->insertDownload($animeOne, self::HASH_A);

        $this->runUp();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->insertDownload($animeTwo, self::HASH_A);
    }

    private function insertAnime(): int
    {
        $this->connection->executeStatement('INSERT INTO anime (title) VALUES (?)', ['Trigun']);

        return (int) $this->connection->lastInsertId();
    }

    private function insertDownload(int $animeId, string $infoHash): int
    {
        $this->connection->executeStatement(
            'INSERT INTO downloads (anime_id, info_hash) VALUES (?, ?)',
            [$animeId, $infoHash],
        );

        return (int) $this->connection->lastInsertId();
    }

    private function runUp(): void
    {
        $migration = new Version20260925000000($this->connection, new NullLogger());
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
