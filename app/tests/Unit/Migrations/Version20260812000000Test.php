<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Query\Query;
use DoctrineMigrations\Version20260812000000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__.'/../../../migrations/Version20260812000000.php';

/**
 * Exercises Version20260812000000::up()/down() (issue #365) against a real SQLite connection
 * with "PRAGMA foreign_keys = ON" — the same setting every real connection gets from the
 * EnableForeignKeys middleware (see gotchas.md). up() adds the two new columns and backfills
 * watch_progress_updated_at from date_update; down() rebuilds the table to drop them again,
 * which — same as Version20260801000003 — must not let the rebuild's implicit DELETE cascade
 * into anime_genres.
 */
final class Version20260812000000Test extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');

        $this->connection->executeStatement("CREATE TABLE anime (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            title VARCHAR(256) NOT NULL,
            date_premiere INTEGER DEFAULT NULL,
            date_end INTEGER DEFAULT NULL,
            duration_minutes INTEGER DEFAULT NULL,
            episodes_count INTEGER DEFAULT NULL,
            watched_episodes INTEGER DEFAULT NULL,
            watch_status VARCHAR(16) NOT NULL,
            user_rating INTEGER DEFAULT NULL,
            notes CLOB DEFAULT NULL,
            type VARCHAR(16) NOT NULL,
            countries CLOB DEFAULT NULL,
            cover VARCHAR(256) DEFAULT NULL,
            storage_id INTEGER DEFAULT NULL,
            date_add INTEGER NOT NULL,
            date_update INTEGER NOT NULL,
            storage_path VARCHAR(1024) DEFAULT NULL,
            normalized_title VARCHAR(256) NOT NULL DEFAULT '',
            demographic VARCHAR(16) DEFAULT NULL
        )");

        $this->connection->executeStatement('CREATE TABLE anime_genres (
            anime_id INTEGER NOT NULL,
            genre_code VARCHAR(32) NOT NULL,
            PRIMARY KEY (anime_id, genre_code),
            FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE
        )');

        $this->connection->executeStatement('CREATE VIRTUAL TABLE anime_fts USING fts5(name, anime_id UNINDEXED)');
        $this->connection->executeStatement('
            CREATE TRIGGER anime_fts_ai_anime AFTER INSERT ON anime BEGIN
                INSERT INTO anime_fts(rowid, anime_id, name) VALUES (new.id, new.id, new.title);
            END
        ');
        $this->connection->executeStatement('
            CREATE TRIGGER anime_fts_au_anime AFTER UPDATE OF title ON anime BEGIN
                UPDATE anime_fts SET name = new.title WHERE rowid = new.id;
            END
        ');
        $this->connection->executeStatement('
            CREATE TRIGGER anime_fts_ad_anime AFTER DELETE ON anime BEGIN
                DELETE FROM anime_fts WHERE rowid = old.id;
            END
        ');
    }

    public function testUpAddsColumnsAndBackfillsWatchProgressUpdatedAtFromDateUpdate(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO anime (title, watch_status, type, date_add, date_update) VALUES ('Trigun', 'plan', 'tv', 1000, 12345)",
        );
        $animeId = (int) $this->connection->lastInsertId();

        $this->runUp();

        self::assertSame(
            12345,
            (int) $this->connection->fetchOne('SELECT watch_progress_updated_at FROM anime WHERE id = ?', [$animeId]),
        );
        self::assertNull($this->connection->fetchOne('SELECT watch_progress_rejected_at FROM anime WHERE id = ?', [$animeId]));
    }

    public function testDownRebuildsAnimeWithoutCascadingIntoChildTables(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO anime (title, watch_status, type, date_add, date_update) VALUES ('Trigun', 'plan', 'tv', 1000, 12345)",
        );
        $animeId = (int) $this->connection->lastInsertId();
        $this->connection->executeStatement(
            "INSERT INTO anime_genres (anime_id, genre_code) VALUES ({$animeId}, 'action')",
        );

        $this->runUp();
        $this->runDown();

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM anime WHERE id = ?', [$animeId]));
        self::assertSame(
            1,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM anime_genres WHERE anime_id = ?', [$animeId]),
            'anime_genres row must survive the rebuild',
        );
        self::assertSame(1, (int) $this->connection->fetchOne('PRAGMA foreign_keys'), 'the migration must restore foreign_keys = ON when it is done');
        self::assertSame(
            0,
            (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pragma_table_info('anime') WHERE name = 'watch_progress_updated_at'"),
        );
    }

    private function runUp(): void
    {
        $migration = $this->newMigration();
        $schema = new Schema();

        $migration->up($schema);
        $queries = $migration->getSql();
        $migration->freeze();

        $this->runQueries($queries);
        $migration->postUp($schema);
    }

    private function runDown(): void
    {
        $migration = $this->newMigration();
        $schema = new Schema();

        $migration->down($schema);
        $queries = $migration->getSql();
        $migration->freeze();

        $this->runQueries($queries);
        $migration->postDown($schema);
    }

    private function newMigration(): Version20260812000000
    {
        return new Version20260812000000($this->connection, new NullLogger());
    }

    /** @param Query[] $queries */
    private function runQueries(array $queries): void
    {
        foreach ($queries as $query) {
            $this->connection->executeQuery($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
