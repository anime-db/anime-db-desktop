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
use PHPUnit\Framework\TestCase;

/**
 * Exercises the anime_fts FTS5 virtual table and its sync triggers directly against a real
 * SQLite connection (see Version20260713000000 and issue #195), following the same pattern
 * as CatalogSchemaTest: this is the only reliable way to prove trigger-based sync and MATCH
 * queries actually work, since Doctrine's SchemaTool (used by AnimeRepositoryTest) has no
 * concept of virtual tables or triggers.
 */
final class AnimeFtsSchemaTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->connection->executeStatement('CREATE TABLE anime (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            title VARCHAR(256) NOT NULL
        )');

        $this->connection->executeStatement('CREATE TABLE anime_name (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            anime_id INTEGER NOT NULL,
            name VARCHAR(256) NOT NULL
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

        $this->connection->executeStatement('
            CREATE TRIGGER anime_fts_ai_anime_name AFTER INSERT ON anime_name BEGIN
                INSERT INTO anime_fts(rowid, anime_id, name) VALUES (-new.id, new.anime_id, new.name);
            END
        ');
        $this->connection->executeStatement('
            CREATE TRIGGER anime_fts_au_anime_name AFTER UPDATE OF name ON anime_name BEGIN
                UPDATE anime_fts SET name = new.name WHERE rowid = -new.id;
            END
        ');
        $this->connection->executeStatement('
            CREATE TRIGGER anime_fts_ad_anime_name AFTER DELETE ON anime_name BEGIN
                DELETE FROM anime_fts WHERE rowid = -old.id;
            END
        ');
    }

    /** @return list<int> */
    private function matchAnimeIds(string $needle): array
    {
        return array_map(
            intval(...),
            $this->connection->fetchFirstColumn('SELECT DISTINCT anime_id FROM anime_fts WHERE anime_fts MATCH ?', [$needle]),
        );
    }

    public function testInsertingAnimeAddsItsTitleToFts(): void
    {
        $this->connection->executeStatement("INSERT INTO anime (title) VALUES ('Trigun')");
        $animeId = (int) $this->connection->lastInsertId();

        $this->assertSame([$animeId], $this->matchAnimeIds('"trigun"*'));
    }

    public function testUpdatingAnimeTitleUpdatesFts(): void
    {
        $this->connection->executeStatement("INSERT INTO anime (title) VALUES ('Trigun')");
        $animeId = (int) $this->connection->lastInsertId();

        $this->connection->executeStatement("UPDATE anime SET title = 'Cowboy Bebop' WHERE id = {$animeId}");

        $this->assertSame([], $this->matchAnimeIds('"trigun"*'));
        $this->assertSame([$animeId], $this->matchAnimeIds('"bebop"*'));
    }

    public function testDeletingAnimeRemovesItFromFts(): void
    {
        $this->connection->executeStatement("INSERT INTO anime (title) VALUES ('Trigun')");
        $animeId = (int) $this->connection->lastInsertId();

        $this->connection->executeStatement("DELETE FROM anime WHERE id = {$animeId}");

        $this->assertSame([], $this->matchAnimeIds('"trigun"*'));
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM anime_fts'));
    }

    public function testInsertingAlternativeNameAddsItToFtsWithoutReplacingTheTitleRow(): void
    {
        $this->connection->executeStatement("INSERT INTO anime (title) VALUES ('Trigun')");
        $animeId = (int) $this->connection->lastInsertId();

        $this->connection->executeStatement(
            "INSERT INTO anime_name (anime_id, name) VALUES ({$animeId}, 'Toraiga')",
        );

        $this->assertSame([$animeId], $this->matchAnimeIds('"trigun"*'));
        $this->assertSame([$animeId], $this->matchAnimeIds('"toraiga"*'));
        // Only this one anime exists in this test, so 2 total rows proves both the title
        // row and the alternative-name row coexist rather than one replacing the other.
        $this->assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM anime_fts'));
    }

    public function testDeletingAlternativeNameRemovesOnlyThatFtsRow(): void
    {
        $this->connection->executeStatement("INSERT INTO anime (title) VALUES ('Trigun')");
        $animeId = (int) $this->connection->lastInsertId();

        $this->connection->executeStatement(
            "INSERT INTO anime_name (anime_id, name) VALUES ({$animeId}, 'Toraiga')",
        );
        $nameId = (int) $this->connection->lastInsertId();

        $this->connection->executeStatement("DELETE FROM anime_name WHERE id = {$nameId}");

        $this->assertSame([], $this->matchAnimeIds('"toraiga"*'));
        $this->assertSame([$animeId], $this->matchAnimeIds('"trigun"*'));
    }
}
