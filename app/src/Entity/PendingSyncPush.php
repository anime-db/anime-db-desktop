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
 * A failed forward-propagation push (issue #862) for a participant that has no
 * {@see AnimeSyncState} row yet — {@see \App\Service\Sync\SyncConvergenceService::applyManualResolution()}
 * can push to a participant the reconciliation engine has never written a snapshot for. The
 * marker cannot live on an AnimeSyncState row in that case (there is none, and fabricating one
 * with made-up last-seen values would corrupt the next reconciliation), so it gets this own,
 * value-free row instead: existence alone means "retry a push to this participant next time this
 * anime reconciles with no changes".
 *
 * Once a push to the same participant actually lands (the AnimeSyncState row then exists), this
 * row is deleted — see {@see PendingSyncPushRepository::clearPending()}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pending_sync_push')]
class PendingSyncPush
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Anime::class)]
    #[ORM\JoinColumn(name: 'anime_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public readonly Anime $anime;

    #[ORM\Id]
    #[ORM\Column(name: 'participant_id', length: 64)]
    public readonly string $participantId;

    public function __construct(Anime $anime, string $participantId)
    {
        $this->anime = $anime;
        $this->participantId = $participantId;
    }
}
