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

namespace App\Entity\Enum;

/**
 * What a SyncReviewItem is flagging:
 * - PotentialDuplicate — cross-vendor dedup heuristic (issue #216b);
 * - DeletedFromSource / DeletionConflict — a title that disappeared from the user's list on a
 *   source, never auto-deleted, flagged for the user to decide (issue #217);
 * - NeedsCorrection — a true N-way reconciliation conflict (issue #366, ">=2 changed
 *   participants disagreeing"): the engine already applied its best-effort max-updatedAt
 *   arbitration, this item is the "Поправить" prompt for a human to pin the actually-correct
 *   value (see SyncReconciler and .claude-docs/sync.md's reconciliation registry, pitfall #4).
 *
 * No CHECK constraint pins these values in the migration (SQLite can't ALTER one), so adding a
 * case here is enough — the enumType column validates at the app layer.
 */
enum SyncReviewItemKind: string
{
    case PotentialDuplicate = 'potential_duplicate';
    case DeletedFromSource = 'deleted_from_source';
    case DeletionConflict = 'deletion_conflict';
    case NeedsCorrection = 'needs_correction';
}
