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
use DoctrineMigrations\Version20260923000000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__.'/../../../migrations/Version20260923000000.php';

/**
 * Runs Version20260923000000::up()/down() (the anime_name.type -> locale + role rebuild,
 * issue #724) against a real SQLite connection with anime_fts and its sync triggers already in
 * place (Version20260713000000) — the only reliable way to prove the rebuild does not silently
 * break the quick-filter: DROP TABLE does not fire triggers, so a naive rebuild would leave the
 * three anime_fts_*_anime_name triggers gone and any post-migration insert invisible to search,
 * without a single failing assertion anywhere else in the suite (migrations are not exercised
 * in CI, see the migration's own docblock).
 */
final class Version20260923000000Test extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');

        $this->connection->executeStatement('CREATE TABLE anime (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            title VARCHAR(256) NOT NULL
        )');

        $this->connection->executeStatement('CREATE TABLE anime_name (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            anime_id INTEGER NOT NULL,
            name VARCHAR(256) NOT NULL,
            type VARCHAR(16) NOT NULL CHECK (type IN (\'original\', \'english\', \'russian\', \'synonym\')),
            normalized_name VARCHAR(256) NOT NULL DEFAULT \'\',
            CONSTRAINT FK_ANIME_NAME_ANIME FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->connection->executeStatement('CREATE INDEX IDX_ANIME_NAME_ANIME ON anime_name (anime_id)');
        $this->connection->executeStatement('CREATE INDEX IDX_ANIME_NAME_NORMALIZED_NAME ON anime_name (normalized_name)');

        $this->connection->executeStatement('CREATE VIRTUAL TABLE anime_fts USING fts5(name, anime_id UNINDEXED)');
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

    public function testUpConvertsSynonymTypeToSynonymRoleWithNullLocale(): void
    {
        $animeId = $this->insertAnime('Trigun');
        $nameId = $this->insertName($animeId, 'Toraiga', 'synonym');

        $this->runUp();

        $this->assertSame('synonym', $this->connection->fetchOne('SELECT role FROM anime_name WHERE id = ?', [$nameId]));
        $this->assertNull($this->connection->fetchOne('SELECT locale FROM anime_name WHERE id = ?', [$nameId]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideOfficialLikeTypes(): iterable
    {
        yield 'original' => ['original'];
        yield 'english' => ['english'];
        yield 'russian' => ['russian'];
    }

    #[DataProvider('provideOfficialLikeTypes')]
    public function testUpConvertsNonSynonymTypesToOfficialRoleWithNullLocale(string $type): void
    {
        $animeId = $this->insertAnime('Trigun');
        $nameId = $this->insertName($animeId, 'Some Name', $type);

        $this->runUp();

        $this->assertSame('official', $this->connection->fetchOne('SELECT role FROM anime_name WHERE id = ?', [$nameId]));
        $this->assertNull($this->connection->fetchOne('SELECT locale FROM anime_name WHERE id = ?', [$nameId]));
    }

    public function testUpPreservesTheRowId(): void
    {
        $animeId = $this->insertAnime('Trigun');
        $nameId = $this->insertName($animeId, 'Toraiga', 'synonym');

        $this->runUp();

        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM anime_name WHERE id = ?', [$nameId]));
    }

    public function testUpRecreatesTheThreeFtsTriggersAndAnInsertReachesAnimeFts(): void
    {
        $animeId = $this->insertAnime('Trigun');

        $this->runUp();

        $this->connection->executeStatement(
            "INSERT INTO anime_name (anime_id, name, normalized_name, locale, role) VALUES ({$animeId}, 'Vash', 'vash', NULL, 'synonym')",
        );
        $nameId = (int) $this->connection->lastInsertId();

        $this->assertSame(
            'Vash',
            $this->connection->fetchOne('SELECT name FROM anime_fts WHERE rowid = ?', [-$nameId]),
            'anime_fts_ai_anime_name must still fire after the rebuild',
        );

        $this->connection->executeStatement("UPDATE anime_name SET name = 'Vash the Stampede' WHERE id = {$nameId}");
        $this->assertSame(
            'Vash the Stampede',
            $this->connection->fetchOne('SELECT name FROM anime_fts WHERE rowid = ?', [-$nameId]),
            'anime_fts_au_anime_name must still fire after the rebuild',
        );

        $this->connection->executeStatement("DELETE FROM anime_name WHERE id = {$nameId}");
        $this->assertSame(
            0,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM anime_fts WHERE rowid = ?', [-$nameId]),
            'anime_fts_ad_anime_name must still fire after the rebuild',
        );
    }

    public function testUpRecreatesBothIndexes(): void
    {
        $this->runUp();

        $indexes = array_column(
            $this->connection->fetchAllAssociative("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'anime_name'"),
            'name',
        );

        $this->assertContains('IDX_ANIME_NAME_ANIME', $indexes);
        $this->assertContains('IDX_ANIME_NAME_NORMALIZED_NAME', $indexes);
    }

    public function testDownRebuildsTheOldTypeColumnAndRestoresFtsTriggers(): void
    {
        $animeId = $this->insertAnime('Trigun');

        $this->runUp();
        $this->connection->executeStatement(
            "INSERT INTO anime_name (anime_id, name, normalized_name, locale, role) VALUES ({$animeId}, 'Vash', 'vash', 'en', 'official')",
        );
        $nameId = (int) $this->connection->lastInsertId();

        $this->runDown();

        $this->assertSame('original', $this->connection->fetchOne('SELECT type FROM anime_name WHERE id = ?', [$nameId]));

        $this->connection->executeStatement("DELETE FROM anime_name WHERE id = {$nameId}");
        $this->assertSame(
            0,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM anime_fts WHERE rowid = ?', [-$nameId]),
            'anime_fts_ad_anime_name must still fire after the down() rebuild',
        );
    }

    private function insertAnime(string $title): int
    {
        $this->connection->executeStatement('INSERT INTO anime (title) VALUES (?)', [$title]);

        return (int) $this->connection->lastInsertId();
    }

    private function insertName(int $animeId, string $name, string $type): int
    {
        $this->connection->executeStatement(
            'INSERT INTO anime_name (anime_id, name, normalized_name, type) VALUES (?, ?, ?, ?)',
            [$animeId, $name, mb_strtolower($name), $type],
        );

        return (int) $this->connection->lastInsertId();
    }

    private function runUp(): void
    {
        $migration = $this->newMigration();
        $schema = new Schema();

        $migration->up($schema);
        $this->runQueries($migration->getSql());
        $migration->freeze();
        $migration->postUp($schema);
    }

    private function runDown(): void
    {
        $migration = $this->newMigration();
        $schema = new Schema();

        $migration->down($schema);
        $this->runQueries($migration->getSql());
        $migration->freeze();
        $migration->postDown($schema);
    }

    private function newMigration(): Version20260923000000
    {
        return new Version20260923000000($this->connection, new NullLogger());
    }

    /** @param Query[] $queries */
    private function runQueries(array $queries): void
    {
        foreach ($queries as $query) {
            $this->connection->executeQuery($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
