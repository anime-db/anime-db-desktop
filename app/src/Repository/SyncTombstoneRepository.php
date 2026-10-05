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
    public function record(string $pluginId, string $externalId, \DateTimeImmutable $deletedAt): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO sync_tombstone (plugin_id, external_id, deleted_at) VALUES (?, ?, ?) ON CONFLICT (plugin_id, external_id) DO UPDATE SET deleted_at = excluded.deleted_at',
            [$pluginId, $externalId, $deletedAt->getTimestamp()],
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

    public function exists(string $pluginId, string $externalId): bool
    {
        return $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM sync_tombstone WHERE plugin_id = ? AND external_id = ?',
            [$pluginId, $externalId],
        ) !== false;
    }
}
