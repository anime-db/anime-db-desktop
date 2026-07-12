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
use Doctrine\DBAL\Exception as DbalException;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the ON DELETE behaviours of the catalog schema (see Version20260704000000
 * and issue #48, bug B-20) directly against a real SQLite connection. This is the only
 * reliable way to prove the constraints work: SQLite ignores foreign key actions unless
 * "PRAGMA foreign_keys = ON" is set on the connection (see the doctrine.middleware-tagged
 * Doctrine\DBAL\Driver\AbstractSQLiteDriver\Middleware\EnableForeignKeys in services.yaml).
 */
final class CatalogSchemaTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');

        $this->connection->executeStatement('CREATE TABLE storage (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            name VARCHAR(256) NOT NULL,
            type VARCHAR(16) NOT NULL,
            path VARCHAR(1024) NOT NULL
        )');

        $this->connection->executeStatement('CREATE TABLE studio (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            name VARCHAR(256) NOT NULL
        )');

        $this->connection->executeStatement('CREATE TABLE anime (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            title VARCHAR(256) NOT NULL,
            date_premiere INTEGER DEFAULT NULL,
            date_end INTEGER DEFAULT NULL,
            watch_status VARCHAR(16) NOT NULL,
            type VARCHAR(16) NOT NULL,
            storage_id INTEGER DEFAULT NULL,
            CHECK (date_end IS NULL OR date_premiere IS NULL OR date_end >= date_premiere),
            FOREIGN KEY (storage_id) REFERENCES storage (id) ON DELETE SET NULL
        )');

        $this->connection->executeStatement('CREATE TABLE anime_studios (
            anime_id INTEGER NOT NULL,
            studio_id INTEGER NOT NULL,
            PRIMARY KEY (anime_id, studio_id),
            FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE,
            FOREIGN KEY (studio_id) REFERENCES studio (id) ON DELETE RESTRICT
        )');

        $this->connection->executeStatement('CREATE TABLE anime_themes (
            anime_id INTEGER NOT NULL,
            theme_code VARCHAR(32) NOT NULL CHECK (theme_code IN (
                \'harem\', \'historical\', \'isekai\', \'martial-arts\', \'mecha\', \'military\', \'music\',
                \'mythology\', \'parody\', \'psychological\', \'school\', \'strategy-game\', \'super-power\', \'vampire\'
            )),
            PRIMARY KEY (anime_id, theme_code),
            FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE
        )');
    }

    public function testDeletingStorageSetsAnimeStorageIdToNull(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO storage (name, type, path) VALUES ('Main', 'folder', 'D:\\Anime')",
        );
        $storageId = (int) $this->connection->lastInsertId();

        $this->connection->executeStatement(
            "INSERT INTO anime (title, watch_status, type, storage_id) VALUES ('Trigun', 'plan', 'tv', {$storageId})",
        );
        $animeId = (int) $this->connection->lastInsertId();

        $this->connection->executeStatement("DELETE FROM storage WHERE id = {$storageId}");

        $this->assertSame(
            0,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM storage'),
        );
        $this->assertSame(
            1,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM anime WHERE id = ?', [$animeId]),
        );
        $this->assertNull(
            $this->connection->fetchOne('SELECT storage_id FROM anime WHERE id = ?', [$animeId]),
        );
    }

    public function testDeletingStudioLinkedToAnimeIsRejected(): void
    {
        $this->connection->executeStatement("INSERT INTO studio (name) VALUES ('Sunrise')");
        $studioId = (int) $this->connection->lastInsertId();

        $this->connection->executeStatement(
            "INSERT INTO anime (title, watch_status, type) VALUES ('Cowboy Bebop', 'plan', 'tv')",
        );
        $animeId = (int) $this->connection->lastInsertId();

        $this->connection->executeStatement(
            "INSERT INTO anime_studios (anime_id, studio_id) VALUES ({$animeId}, {$studioId})",
        );

        $this->expectException(DbalException::class);

        try {
            $this->connection->executeStatement("DELETE FROM studio WHERE id = {$studioId}");
        } finally {
            $this->assertSame(
                1,
                (int) $this->connection->fetchOne('SELECT COUNT(*) FROM studio WHERE id = ?', [$studioId]),
            );
        }
    }

    public function testDeletingUnlinkedStudioSucceeds(): void
    {
        $this->connection->executeStatement("INSERT INTO studio (name) VALUES ('Sunrise')");
        $studioId = (int) $this->connection->lastInsertId();

        $this->connection->executeStatement("DELETE FROM studio WHERE id = {$studioId}");

        $this->assertSame(
            0,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM studio WHERE id = ?', [$studioId]),
        );
    }

    public function testInvalidThemeCodeIsRejectedByCheckConstraint(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO anime (title, watch_status, type) VALUES ('Trigun', 'plan', 'tv')",
        );
        $animeId = (int) $this->connection->lastInsertId();

        $this->expectException(DbalException::class);

        $this->connection->executeStatement(
            "INSERT INTO anime_themes (anime_id, theme_code) VALUES ({$animeId}, 'not-a-theme')",
        );
    }

    public function testDeletingAnimeCascadesToItsThemes(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO anime (title, watch_status, type) VALUES ('Trigun', 'plan', 'tv')",
        );
        $animeId = (int) $this->connection->lastInsertId();

        $this->connection->executeStatement(
            "INSERT INTO anime_themes (anime_id, theme_code) VALUES ({$animeId}, 'isekai')",
        );

        $this->connection->executeStatement("DELETE FROM anime WHERE id = {$animeId}");

        $this->assertSame(
            0,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM anime_themes WHERE anime_id = ?', [$animeId]),
        );
    }

    public function testDateEndEarlierThanDatePremiereIsRejectedByCheckConstraint(): void
    {
        $this->expectException(DbalException::class);

        $datePremiere = (new \DateTimeImmutable('2026-06-01'))->getTimestamp();
        $dateEnd = (new \DateTimeImmutable('2026-01-01'))->getTimestamp();
        $this->connection->executeStatement(
            'INSERT INTO anime (title, watch_status, type, date_premiere, date_end) '
            ."VALUES ('Trigun', 'plan', 'tv', {$datePremiere}, {$dateEnd})",
        );
    }

    public function testDateEndEqualToDatePremiereIsAccepted(): void
    {
        $date = (new \DateTimeImmutable('2026-06-01'))->getTimestamp();
        $this->connection->executeStatement(
            'INSERT INTO anime (title, watch_status, type, date_premiere, date_end) '
            ."VALUES ('Trigun', 'plan', 'tv', {$date}, {$date})",
        );

        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM anime'));
    }
}
