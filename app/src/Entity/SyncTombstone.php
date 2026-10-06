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

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Remembers that a catalog entry linked to (plugin_id, external_id) was deleted locally (issue
 * #916), so a later pull or storage scan does not create it again. Rows are written by
 * {@see \App\Service\AnimeDeleteService} only (never by a Doctrine listener: an entry type change
 * removes the old row too and must not leave a tombstone). A row is removed only by
 * {@see \App\Service\Sync\SourceRemovalService}, once the title is gone from the user's list on the
 * source (issue #918); until then, and for a deletion made without that, a live entry with the same
 * external id always wins, see {@see \App\Service\Plugin\PullSyncService}.
 *
 * $removalPending says the deletion on the source is still to be done. Rows are written through
 * {@see \App\Repository\SyncTombstoneRepository} in plain SQL.
 *
 * Not a foreign key to anime, and plugin_id is not one to anything either, same as
 * {@see AnimeExternalId}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sync_tombstone')]
class SyncTombstone
{
    #[ORM\Id]
    #[ORM\Column(name: 'plugin_id', length: 64)]
    public readonly string $pluginId;

    #[ORM\Id]
    #[ORM\Column(name: 'external_id', length: 255)]
    public readonly string $externalId;

    #[ORM\Column(name: 'deleted_at', type: 'unix_timestamp')]
    public readonly \DateTimeImmutable $deletedAt;

    #[ORM\Column(name: 'removal_pending', options: ['default' => false])]
    public readonly bool $removalPending;

    public function __construct(string $pluginId, string $externalId, \DateTimeImmutable $deletedAt, bool $removalPending = false)
    {
        $this->pluginId = $pluginId;
        $this->externalId = $externalId;
        $this->deletedAt = $deletedAt;
        $this->removalPending = $removalPending;
    }
}
