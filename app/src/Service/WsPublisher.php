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

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Queues backend events for delivery to connected WebSocket clients.
 *
 * Backed by the `ws_events` table on the dedicated `queue` connection (data/queue.db, see
 * issue #94) — a plain SQLite file, not process memory, so publish() and next() see the same
 * queue regardless of which OS process calls them (the FrankenPHP HTTP worker running
 * WsController, or the separate messenger:consume process, see issue #97).
 *
 * The table is created lazily (CREATE TABLE IF NOT EXISTS) rather than through a Doctrine
 * migration, following the same precedent as JobLockService::ensureSchemaExists() (issue #98):
 * migrations only track the "default" connection.
 */
class WsPublisher
{
    private bool $schemaEnsured = false;

    public function __construct(
        #[Target('queue.connection')]
        private readonly Connection $connection,
    ) {
    }

    /**
     * Enqueues an event for all connected WebSocket clients.
     */
    public function publish(string $event, mixed $data): void
    {
        $this->ensureSchemaExists();

        $this->connection->insert('ws_events', [
            'event' => $event,
            'data' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Returns and removes the next queued event, or null if the queue is empty.
     *
     * @return array{event: string, data: mixed}|null
     */
    public function next(): ?array
    {
        $this->ensureSchemaExists();

        $row = $this->connection->fetchAssociative('SELECT id, event, data FROM ws_events ORDER BY id LIMIT 1');
        if ($row === false) {
            return null;
        }

        $this->connection->delete('ws_events', ['id' => $row['id']]);

        return [
            'event' => $row['event'],
            'data' => json_decode((string) $row['data'], true, flags: JSON_THROW_ON_ERROR),
        ];
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
