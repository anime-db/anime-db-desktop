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
use DoctrineMigrations\Version20261004000001;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__.'/../../../migrations/Version20261004000001.php';

/**
 * Runs Version20261004000001::up() (anime_sync_state.push_pending + pending_sync_push, issue #862)
 * against a real SQLite connection representing the pre-migration shape of anime_sync_state (as it
 * was right after Version20260927000001): an existing snapshot row predates the push_pending
 * marker entirely, so it must come out the other end with "no marker" (false/0), not some
 * manufactured guess about whether a push to it was ever pending.
 */
final class Version20261004000001Test extends TestCase
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
        $this->connection->executeStatement('CREATE TABLE anime_sync_state (
            anime_id INTEGER NOT NULL,
            participant_id VARCHAR(64) NOT NULL,
            last_status VARCHAR(16) NOT NULL,
            last_watched_episodes INTEGER DEFAULT NULL,
            last_updated_at INTEGER NOT NULL,
            PRIMARY KEY (anime_id, participant_id),
            CONSTRAINT FK_ANIME_SYNC_STATE_ANIME FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->connection->executeStatement('CREATE INDEX IDX_4214C246794BBE89 ON anime_sync_state (anime_id)');
    }

    public function testUpGivesAnExistingSnapshotRowNoMarker(): void
    {
        $animeId = $this->insertAnime();
        $this->connection->executeStatement(
            'INSERT INTO anime_sync_state (anime_id, participant_id, last_status, last_watched_episodes, last_updated_at) VALUES (?, ?, ?, ?, ?)',
            [$animeId, 'animedb-shikimori', 'watching', 5, 0],
        );

        $this->runUp();

        $row = $this->connection->fetchAssociative('SELECT push_pending FROM anime_sync_state WHERE anime_id = ?', [$animeId]);
        $this->assertIsArray($row);
        $this->assertSame(0, (int) $row['push_pending']);
    }

    public function testUpCreatesThePendingSyncPushTable(): void
    {
        $this->runUp();

        $animeId = $this->insertAnime();
        $this->connection->executeStatement(
            'INSERT INTO pending_sync_push (anime_id, participant_id) VALUES (?, ?)',
            [$animeId, 'animedb-mal'],
        );

        $this->assertSame(
            1,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pending_sync_push WHERE anime_id = ? AND participant_id = ?', [$animeId, 'animedb-mal']),
        );

        $this->connection->executeStatement('DELETE FROM anime WHERE id = ?', [$animeId]);
        $this->assertSame(
            0,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pending_sync_push WHERE anime_id = ?', [$animeId]),
        );
    }

    private function insertAnime(): int
    {
        $this->connection->executeStatement('INSERT INTO anime (title) VALUES (?)', ['Trigun']);

        return (int) $this->connection->lastInsertId();
    }

    private function runUp(): void
    {
        $migration = new Version20261004000001($this->connection, new NullLogger());
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
