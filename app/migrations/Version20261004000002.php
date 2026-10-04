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

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One-time cleanup for issue #863: {@see \App\Service\Sync\DeletedFromSourceDetector} and
 * {@see \App\Service\Plugin\PullSyncService} now require an {@see \App\Entity\AnimeSyncState}
 * snapshot row for the plugin a title is missing from, not just a cached external_id — a filler,
 * bulk-fill, or scan can cache an id for a plugin that never actually synced the record as a list
 * item, so that alone never proved the source had ever listed the title in the first place.
 *
 * Every unresolved sync_review_item of kind 'deleted_from_source' or 'deletion_conflict' raised
 * before this change, whose payload.anime_id has no anime_sync_state row for
 * payload.deleted_from, would not be raised under the new rule — this closes (sets resolved_at)
 * every such item in one pass. An item that is already resolved, of another kind, or whose
 * anime_sync_state row for payload.deleted_from does exist, is left untouched.
 */
final class Version20261004000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Resolve stale DeletedFromSource/DeletionConflict review items raised without an AnimeSyncState snapshot row for their plugin (issue #863)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "UPDATE sync_review_item
                SET resolved_at = ?
                WHERE resolved_at IS NULL
                    AND kind IN ('deleted_from_source', 'deletion_conflict')
                    AND NOT EXISTS (
                        SELECT 1 FROM anime_sync_state
                        WHERE anime_sync_state.anime_id = json_extract(sync_review_item.payload, '$.anime_id')
                            AND anime_sync_state.participant_id = json_extract(sync_review_item.payload, '$.deleted_from')
                    )",
            [(new \DateTimeImmutable())->getTimestamp()],
        );
    }

    public function down(Schema $schema): void
    {
        // Data-only cleanup — which items it resolved cannot be reconstructed, same acceptance
        // as every other one-time backfill/cleanup migration in this project (see gotchas.md).
    }
}
