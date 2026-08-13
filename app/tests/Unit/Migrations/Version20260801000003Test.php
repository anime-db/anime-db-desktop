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
use DoctrineMigrations\Version20260801000003;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__.'/../../../migrations/Version20260801000003.php';

/**
 * Runs Version20260801000003::up() (the anime.metadata rebuild, issue #300) against a real
 * SQLite connection with "PRAGMA foreign_keys = ON" — the same setting every real connection
 * gets from the EnableForeignKeys middleware (see gotchas.md). Rebuilding a table referenced
 * by ON DELETE CASCADE children via DROP TABLE + CREATE TABLE performs an implicit DELETE of
 * every row first, which fires those cascades unless foreign keys are switched off for the
 * rebuild: this proves the migration's PRAGMA toggling actually keeps every cascading child
 * row (genres, studios, labels, alternative names, external ids, descriptions, plugin data)
 * intact instead of silently wiping them.
 */
final class Version20260801000003Test extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');

        $this->connection->executeStatement('CREATE TABLE storage (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            name VARCHAR(256) NOT NULL
        )');
        $this->connection->executeStatement('CREATE TABLE studio (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            name VARCHAR(256) NOT NULL
        )');
        $this->connection->executeStatement('CREATE TABLE label (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            name VARCHAR(256) NOT NULL
        )');

        $this->connection->executeStatement('CREATE TABLE anime (
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
            normalized_title VARCHAR(256) NOT NULL DEFAULT \'\',
            demographic VARCHAR(16) DEFAULT NULL,
            metadata CLOB DEFAULT NULL
        )');

        $this->connection->executeStatement('CREATE VIRTUAL TABLE anime_fts USING fts5(name, anime_id UNINDEXED)');
        $this->connection->executeStatement('
            CREATE TRIGGER anime_fts_ai_anime AFTER INSERT ON anime BEGIN
                INSERT INTO anime_fts(rowid, anime_id, name) VALUES (new.id, new.id, new.title);
            END
        ');

        $this->connection->executeStatement('CREATE TABLE anime_genres (
            anime_id INTEGER NOT NULL,
            genre_code VARCHAR(32) NOT NULL,
            PRIMARY KEY (anime_id, genre_code),
            FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE
        )');
        $this->connection->executeStatement('CREATE TABLE anime_studios (
            anime_id INTEGER NOT NULL,
            studio_id INTEGER NOT NULL,
            PRIMARY KEY (anime_id, studio_id),
            FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE,
            FOREIGN KEY (studio_id) REFERENCES studio (id) ON DELETE RESTRICT
        )');
        $this->connection->executeStatement('CREATE TABLE anime_labels (
            anime_id INTEGER NOT NULL,
            label_id INTEGER NOT NULL,
            PRIMARY KEY (anime_id, label_id),
            FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE,
            FOREIGN KEY (label_id) REFERENCES label (id) ON DELETE CASCADE
        )');
        $this->connection->executeStatement('CREATE TABLE anime_name (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            anime_id INTEGER NOT NULL,
            name VARCHAR(256) NOT NULL,
            type VARCHAR(16) NOT NULL,
            FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE
        )');
        $this->connection->executeStatement('CREATE TABLE anime_external_id (
            anime_id INTEGER NOT NULL,
            plugin_id VARCHAR(64) NOT NULL,
            external_id VARCHAR(255) NOT NULL,
            PRIMARY KEY (anime_id, plugin_id),
            FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE
        )');
        $this->connection->executeStatement('CREATE TABLE anime_description (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            anime_id INTEGER NOT NULL,
            locale VARCHAR(8) NOT NULL,
            description CLOB NOT NULL,
            FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE
        )');
        $this->connection->executeStatement('CREATE TABLE anime_plugin_data (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            anime_id INTEGER NOT NULL,
            plugin_id VARCHAR(128) NOT NULL,
            payload CLOB NOT NULL,
            FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE
        )');
    }

    public function testUpRebuildsAnimeWithoutCascadingIntoChildTables(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO studio (name) VALUES ('Sunrise')",
        );
        $studioId = (int) $this->connection->lastInsertId();
        $this->connection->executeStatement(
            "INSERT INTO label (name) VALUES ('Favorites')",
        );
        $labelId = (int) $this->connection->lastInsertId();

        $this->connection->executeStatement(
            'INSERT INTO anime (title, watch_status, type, date_add, date_update) '
            ."VALUES ('Trigun', 'plan', 'tv', 0, 0)",
        );
        $animeId = (int) $this->connection->lastInsertId();

        $this->connection->executeStatement(
            "INSERT INTO anime_genres (anime_id, genre_code) VALUES ({$animeId}, 'action')",
        );
        $this->connection->executeStatement(
            "INSERT INTO anime_studios (anime_id, studio_id) VALUES ({$animeId}, {$studioId})",
        );
        $this->connection->executeStatement(
            "INSERT INTO anime_labels (anime_id, label_id) VALUES ({$animeId}, {$labelId})",
        );
        $this->connection->executeStatement(
            "INSERT INTO anime_name (anime_id, name, type) VALUES ({$animeId}, 'Toraiga', 'synonym')",
        );
        $this->connection->executeStatement(
            "INSERT INTO anime_external_id (anime_id, plugin_id, external_id) VALUES ({$animeId}, 'mal', '1')",
        );
        $this->connection->executeStatement(
            "INSERT INTO anime_description (anime_id, locale, description) VALUES ({$animeId}, 'en', 'A gunman.')",
        );
        $this->connection->executeStatement(
            "INSERT INTO anime_plugin_data (anime_id, plugin_id, payload) VALUES ({$animeId}, 'mal', '{}')",
        );

        $this->runMigration();

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM anime WHERE id = ?', [$animeId]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM anime_genres WHERE anime_id = ?', [$animeId]), 'anime_genres row must survive the rebuild');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM anime_studios WHERE anime_id = ?', [$animeId]), 'anime_studios row must survive the rebuild');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM anime_labels WHERE anime_id = ?', [$animeId]), 'anime_labels row must survive the rebuild');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM anime_name WHERE anime_id = ?', [$animeId]), 'anime_name row must survive the rebuild');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM anime_external_id WHERE anime_id = ?', [$animeId]), 'anime_external_id row must survive the rebuild');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM anime_description WHERE anime_id = ?', [$animeId]), 'anime_description row must survive the rebuild');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM anime_plugin_data WHERE anime_id = ?', [$animeId]), 'anime_plugin_data row must survive the rebuild');

        self::assertSame(1, (int) $this->connection->fetchOne('PRAGMA foreign_keys'), 'the migration must restore foreign_keys = ON when it is done');
    }

    private function runMigration(): void
    {
        $migration = new Version20260801000003($this->connection, new NullLogger());
        $schema = new Schema();

        $migration->up($schema);
        $queries = $migration->getSql();
        $migration->freeze();

        foreach ($queries as $query) {
            $this->connection->executeQuery($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        $migration->postUp($schema);
    }
}
