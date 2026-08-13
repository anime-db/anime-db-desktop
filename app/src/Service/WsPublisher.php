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

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Broadcasts backend events to every connected WebSocket client.
 *
 * Backed by the `ws_events` table on the dedicated `queue` connection (data/queue.db, see
 * issue #94) — a plain SQLite file, not process memory, so publish() and since() see the same
 * events regardless of which OS process calls them (the FrankenPHP HTTP worker running
 * WsController, or the separate messenger:consume process, see issue #97).
 *
 * Rows are never deleted on read: each connection tracks its own "last seen id" cursor and
 * calls since() with it, so every connection observes every event exactly once, regardless of
 * how many other connections are reading concurrently (issue #146 — the previous next(), which
 * deleted the row it returned, meant only one of several concurrent WebSocket connections would
 * ever see a given event). Rows are instead pruned by age in publish(), see TTL_SECONDS.
 *
 * The table is created lazily (CREATE TABLE IF NOT EXISTS) rather than through a Doctrine
 * migration, following the same precedent as JobLockService::ensureSchemaExists() (issue #98):
 * migrations only track the "default" connection.
 */
class WsPublisher
{
    /**
     * Events older than this are pruned from ws_events on every publish() — the table is a
     * short-lived broadcast log, not a durable event store.
     */
    private const TTL_SECONDS = 300;

    /**
     * How far back initialLastId() looks so a connection that only just finished its handshake
     * doesn't miss an event published a moment earlier.
     */
    private const INITIAL_LOOKBACK_SECONDS = 5;

    private bool $schemaEnsured = false;

    public function __construct(
        #[Target('queue.connection')]
        private readonly Connection $connection,
    ) {
    }

    /**
     * Enqueues an event for all connected WebSocket clients and prunes events older than
     * TTL_SECONDS.
     */
    public function publish(string $event, mixed $data): void
    {
        $this->ensureSchemaExists();

        $this->connection->insert('ws_events', [
            'event' => $event,
            'data' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);

        $this->connection->executeStatement(
            \sprintf("DELETE FROM ws_events WHERE created_at < datetime('now', '-%d seconds')", self::TTL_SECONDS),
        );
    }

    /**
     * Returns all events published after $lastId, oldest first, without removing them — callers
     * keep polling with the highest id they have already seen (see since()'s return value).
     *
     * @return list<array{id: int, event: string, data: mixed}>
     */
    public function since(int $lastId): array
    {
        $this->ensureSchemaExists();

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, event, data FROM ws_events WHERE id > :lastId ORDER BY id',
            ['lastId' => $lastId],
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'event' => $row['event'],
            'data' => json_decode((string) $row['data'], true, flags: JSON_THROW_ON_ERROR),
        ], $rows);
    }

    /**
     * Returns the cursor a freshly connected client should start since() from: the id of the
     * newest event older than INITIAL_LOOKBACK_SECONDS, so the first since() call still picks
     * up anything published in the last few seconds (e.g. between the WebSocket handshake and
     * the first poll), rather than replaying the entire un-pruned backlog.
     */
    public function initialLastId(): int
    {
        $this->ensureSchemaExists();

        $maxId = $this->connection->fetchOne(
            \sprintf(
                "SELECT MAX(id) FROM ws_events WHERE created_at < datetime('now', '-%d seconds')",
                self::INITIAL_LOOKBACK_SECONDS,
            ),
        );

        return $maxId !== null ? (int) $maxId : 0;
    }

    private function ensureSchemaExists(): void
    {
        if ($this->schemaEnsured) {
            return;
        }

        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS ws_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event TEXT NOT NULL,
            data TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )');

        $this->schemaEnsured = true;
    }
}
