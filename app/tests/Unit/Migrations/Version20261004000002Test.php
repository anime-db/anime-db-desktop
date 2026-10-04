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
use DoctrineMigrations\Version20261004000002;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__.'/../../../migrations/Version20261004000002.php';

/**
 * Runs Version20261004000002::up() (issue #863 cleanup) against a real SQLite connection
 * representing sync_review_item and anime_sync_state: an unresolved deleted_from_source/
 * deletion_conflict item whose payload.anime_id has no anime_sync_state row for
 * payload.deleted_from would not be raised under the new rule, so it must come out the other end
 * resolved — while an item that already has that snapshot row, is already resolved, or is of
 * another kind, must not be touched.
 */
final class Version20261004000002Test extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

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
            push_pending BOOLEAN DEFAULT 0 NOT NULL,
            PRIMARY KEY (anime_id, participant_id)
        )');
        $this->connection->executeStatement('CREATE TABLE sync_review_item (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            kind VARCHAR(32) NOT NULL,
            payload CLOB NOT NULL,
            created_at INTEGER NOT NULL,
            resolved_at INTEGER DEFAULT NULL
        )');
    }

    /**
     * Scenario 7 (issue #863): an unresolved deleted_from_source item without a snapshot row for
     * its deleted_from plugin is closed.
     */
    public function testResolvesAnUnresolvedDeletedFromSourceItemWithoutASnapshotRow(): void
    {
        $animeId = $this->insertAnime();
        $itemId = $this->insertReviewItem('deleted_from_source', ['anime_id' => $animeId, 'deleted_from' => 'animedb-shikimori']);

        $this->runUp();

        $this->assertResolved($itemId);
    }

    /**
     * Scenario 8 (issue #863): an unresolved deletion_conflict item without a snapshot row for
     * its deleted_from plugin is closed too — the extra still_present_on payload key does not
     * matter, only payload.anime_id/payload.deleted_from drive the rule.
     */
    public function testResolvesAnUnresolvedDeletionConflictItemWithoutASnapshotRow(): void
    {
        $animeId = $this->insertAnime();
        $itemId = $this->insertReviewItem('deletion_conflict', [
            'anime_id' => $animeId,
            'deleted_from' => 'animedb-shikimori',
            'still_present_on' => ['animedb-mal'],
        ]);

        $this->runUp();

        $this->assertResolved($itemId);
    }

    /**
     * Scenario 9 (issue #863): an unresolved item whose payload.deleted_from plugin does have a
     * snapshot row for payload.anime_id is left untouched — it still passes the new rule.
     */
    public function testLeavesAnUnresolvedItemWithASnapshotRowUntouched(): void
    {
        $animeId = $this->insertAnime();
        $itemId = $this->insertReviewItem('deleted_from_source', ['anime_id' => $animeId, 'deleted_from' => 'animedb-shikimori']);
        $this->insertSyncState($animeId, 'animedb-shikimori');

        $this->runUp();

        $this->assertUnresolved($itemId);
    }

    /**
     * Scenario 10 (issue #863): an already-resolved item without a snapshot row stays resolved at
     * its original timestamp (not touched/overwritten), and an item of another kind
     * (potential_duplicate) without a snapshot row is not resolved at all — this migration only
     * ever acts on unresolved deleted_from_source/deletion_conflict items.
     */
    public function testLeavesAlreadyResolvedItemsAndOtherKindsUntouched(): void
    {
        $animeId = $this->insertAnime();

        $resolvedItemId = $this->insertReviewItem('deleted_from_source', ['anime_id' => $animeId, 'deleted_from' => 'animedb-shikimori'], resolvedAt: 1_700_000_000);
        $otherKindItemId = $this->insertReviewItem('potential_duplicate', ['anime_ids' => [$animeId, $animeId]]);

        $this->runUp();

        $row = $this->connection->fetchAssociative('SELECT resolved_at FROM sync_review_item WHERE id = ?', [$resolvedItemId]);
        $this->assertIsArray($row);
        $this->assertSame(1_700_000_000, (int) $row['resolved_at']);

        $this->assertUnresolved($otherKindItemId);
    }

    private function insertAnime(): int
    {
        $this->connection->executeStatement('INSERT INTO anime (title) VALUES (?)', ['Trigun']);

        return (int) $this->connection->lastInsertId();
    }

    private function insertSyncState(int $animeId, string $participantId): void
    {
        $this->connection->executeStatement(
            'INSERT INTO anime_sync_state (anime_id, participant_id, last_status, last_watched_episodes, last_updated_at) VALUES (?, ?, ?, ?, ?)',
            [$animeId, $participantId, 'watching', null, 0],
        );
    }

    /** @param array<string, mixed> $payload */
    private function insertReviewItem(string $kind, array $payload, ?int $resolvedAt = null): int
    {
        $this->connection->executeStatement(
            'INSERT INTO sync_review_item (kind, payload, created_at, resolved_at) VALUES (?, ?, ?, ?)',
            [$kind, json_encode($payload), 0, $resolvedAt],
        );

        return (int) $this->connection->lastInsertId();
    }

    private function assertResolved(int $itemId): void
    {
        $row = $this->connection->fetchAssociative('SELECT resolved_at FROM sync_review_item WHERE id = ?', [$itemId]);
        $this->assertIsArray($row);
        $this->assertNotNull($row['resolved_at']);
    }

    private function assertUnresolved(int $itemId): void
    {
        $row = $this->connection->fetchAssociative('SELECT resolved_at FROM sync_review_item WHERE id = ?', [$itemId]);
        $this->assertIsArray($row);
        $this->assertNull($row['resolved_at']);
    }

    private function runUp(): void
    {
        $migration = new Version20261004000002($this->connection, new NullLogger());
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
