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

namespace App\Service\Sync;

/**
 * One participant's contribution to a reconciliation run (issue #366): either a freshly
 * observed "current" reading (this run's pulled item, or local's own live state) or a
 * previously-persisted "last-seen" reading (an {@see \App\Entity\AnimeSyncState} row, issue
 * #365, translated into this same shape) — {@see SyncReconciler} deliberately cannot tell the
 * two apart, since the algorithm treats them uniformly (diff current against last-seen).
 *
 * $participantId is either the literal "local" or a {@see \App\Entity\ValueObject\PluginId}
 * string, mirroring AnimeSyncState::$participantId.
 */
final readonly class ParticipantState
{
    public function __construct(
        public string $participantId,
        public SyncProjection $projection,
        public ?\DateTimeImmutable $updatedAt,
    ) {
    }
}
