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

namespace App\Repository;

use App\Entity\ValueObject\PluginId;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Access to {@see \App\Entity\SyncTombstone} (issue #916) through plain SQL: the write is an
 * upsert, which the ORM has no way to express.
 */
class SyncTombstoneRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * Writes the tombstone, or refreshes its date if it is already there (a title deleted, added
     * again and deleted once more must not fail on the primary key). Joins the connection's
     * current transaction, so a caller can commit it together with the deletion.
     */
    public function record(string $pluginId, string $externalId, \DateTimeImmutable $deletedAt, bool $removalPending = false): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO sync_tombstone (plugin_id, external_id, deleted_at, removal_pending) VALUES (?, ?, ?, ?) ON CONFLICT (plugin_id, external_id) DO UPDATE SET deleted_at = excluded.deleted_at, removal_pending = excluded.removal_pending',
            [$pluginId, $externalId, $deletedAt->getTimestamp(), (int) $removalPending],
        );
    }

    /** Whether the tombstone is there and still waits for the deletion on the source (issue #918). */
    public function isRemovalPending(string $pluginId, string $externalId): bool
    {
        return $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM sync_tombstone WHERE plugin_id = ? AND external_id = ? AND removal_pending = 1',
            [$pluginId, $externalId],
        ) !== false;
    }

    /** @return list<string> external ids of the plugin's tombstones that wait for the deletion on the source */
    public function findRemovalPending(PluginId $pluginId): array
    {
        /** @var list<string> $ids */
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT external_id FROM sync_tombstone WHERE plugin_id = ? AND removal_pending = 1 ORDER BY deleted_at, external_id',
            [(string) $pluginId],
        );

        return array_map('strval', $ids);
    }

    public function clearRemovalPending(string $pluginId, string $externalId): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE sync_tombstone SET removal_pending = 0 WHERE plugin_id = ? AND external_id = ?',
            [$pluginId, $externalId],
        );
    }

    /** Called only once the title is gone from the source's list, see {@see \App\Service\Sync\SourceRemovalService}. */
    public function remove(string $pluginId, string $externalId): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM sync_tombstone WHERE plugin_id = ? AND external_id = ?',
            [$pluginId, $externalId],
        );
    }

    /**
     * All tombstoned external ids of one plugin, loaded once per pull run.
     *
     * @return array<string, true> external id => true
     */
    public function indexByPlugin(PluginId $pluginId): array
    {
        $rows = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT external_id FROM sync_tombstone WHERE plugin_id = ?',
            [(string) $pluginId],
        );

        $index = [];
        foreach ($rows as $externalId) {
            $index[(string) $externalId] = true;
        }

        return $index;
    }

    /**
     * Drops every tombstone, whatever the plugin (issue #951): they outlive an emptied catalog,
     * and the v1 import replaces it wholesale. All or nothing — a half-cleared table is worse
     * than either. Joins the connection's current transaction.
     *
     * @return int number of tombstones removed
     */
    public function removeAll(): int
    {
        return (int) $this->entityManager->getConnection()->executeStatement('DELETE FROM sync_tombstone');
    }

    public function exists(string $pluginId, string $externalId): bool
    {
        return $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM sync_tombstone WHERE plugin_id = ? AND external_id = ?',
            [$pluginId, $externalId],
        ) !== false;
    }
}
