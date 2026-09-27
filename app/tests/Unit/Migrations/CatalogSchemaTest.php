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

use App\Tests\Support\RunsMigrations;
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
 *
 * The schema is built by running every real migration (see RunsMigrations, issue #762) rather
 * than hand-copied DDL, so the CHECK constraints and foreign keys exercised here always match
 * what the app actually creates instead of a simplified stand-in that can silently drift from it.
 */
final class CatalogSchemaTest extends TestCase
{
    use RunsMigrations;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');

        $this->buildSchemaByRunningMigrations($this->connection);
    }

    public function testDeletingStorageSetsAnimeStorageIdToNull(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO storage (name, type, path) VALUES ('Main', 'folder', 'D:\\Anime')",
        );
        $storageId = (int) $this->connection->lastInsertId();

        $this->connection->executeStatement(
            'INSERT INTO anime (title, watch_status, type, storage_id, date_add, date_update) '
            ."VALUES ('Trigun', 'plan', 'tv', {$storageId}, 0, 0)",
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
            "INSERT INTO anime (title, watch_status, type, date_add, date_update) VALUES ('Cowboy Bebop', 'plan', 'tv', 0, 0)",
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
            "INSERT INTO anime (title, watch_status, type, date_add, date_update) VALUES ('Trigun', 'plan', 'tv', 0, 0)",
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
            "INSERT INTO anime (title, watch_status, type, date_add, date_update) VALUES ('Trigun', 'plan', 'tv', 0, 0)",
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

    public function testInvalidDemographicIsRejectedByCheckConstraint(): void
    {
        $this->expectException(DbalException::class);

        $this->connection->executeStatement(
            'INSERT INTO anime (title, watch_status, type, demographic, date_add, date_update) '
            ."VALUES ('Trigun', 'plan', 'tv', 'not-a-demographic', 0, 0)",
        );
    }

    public function testNullDemographicIsAccepted(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO anime (title, watch_status, type, date_add, date_update) VALUES ('Trigun', 'plan', 'tv', 0, 0)",
        );

        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM anime'));
    }

    public function testDateEndEarlierThanDatePremiereIsRejectedByCheckConstraint(): void
    {
        $this->expectException(DbalException::class);

        $datePremiere = (new \DateTimeImmutable('2026-06-01'))->getTimestamp();
        $dateEnd = (new \DateTimeImmutable('2026-01-01'))->getTimestamp();
        $this->connection->executeStatement(
            'INSERT INTO anime (title, watch_status, type, date_premiere, date_end, date_add, date_update) '
            ."VALUES ('Trigun', 'plan', 'tv', {$datePremiere}, {$dateEnd}, 0, 0)",
        );
    }

    public function testDateEndEqualToDatePremiereIsAccepted(): void
    {
        $date = (new \DateTimeImmutable('2026-06-01'))->getTimestamp();
        $this->connection->executeStatement(
            'INSERT INTO anime (title, watch_status, type, date_premiere, date_end, date_add, date_update) '
            ."VALUES ('Trigun', 'plan', 'tv', {$date}, {$date}, 0, 0)",
        );

        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM anime'));
    }
}
