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

namespace App\Entity;

use App\Entity\Enum\WatchStatus;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per (anime, participant) the reconciliation snapshot (issue #365) merges against —
 * "participant" is either a PluginId string or the literal "local", which is why this is a plain
 * string column rather than the PluginId value object (PluginId's "vendor-name" format rejects
 * "local"). Detecting *who* changed (last-seen projection vs. the participant's current report,
 * or an updatedAt that moved on) and everything else that reads/writes this table is the sync
 * engine's job (deliberately out of scope here, see the issue body); this class is storage only.
 *
 * PK is (anime_id, participant_id) — at most one snapshot row per participant per anime. Values
 * are mutated in place via update() rather than remove+re-add, so a participant's row keeps a
 * stable identity across reconciliation runs.
 */
#[ORM\Entity]
#[ORM\Table(name: 'anime_sync_state')]
class AnimeSyncState
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Anime::class)]
    #[ORM\JoinColumn(name: 'anime_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public readonly Anime $anime;

    #[ORM\Id]
    #[ORM\Column(name: 'participant_id', length: 64)]
    public readonly string $participantId;

    #[ORM\Column(name: 'last_status', length: 16, enumType: WatchStatus::class)]
    public private(set) WatchStatus $lastStatus;

    #[ORM\Column(name: 'last_watched_episodes', nullable: true)]
    public private(set) ?int $lastWatchedEpisodes;

    #[ORM\Column(name: 'last_updated_at', type: 'unix_timestamp')]
    public private(set) \DateTimeImmutable $lastUpdatedAt;

    public function __construct(
        Anime $anime,
        string $participantId,
        WatchStatus $lastStatus,
        ?int $lastWatchedEpisodes,
        \DateTimeImmutable $lastUpdatedAt,
    ) {
        $this->anime = $anime;
        $this->participantId = $participantId;
        $this->lastStatus = $lastStatus;
        $this->lastWatchedEpisodes = $lastWatchedEpisodes;
        $this->lastUpdatedAt = $lastUpdatedAt;
    }

    public function update(WatchStatus $lastStatus, ?int $lastWatchedEpisodes, \DateTimeImmutable $lastUpdatedAt): void
    {
        $this->lastStatus = $lastStatus;
        $this->lastWatchedEpisodes = $lastWatchedEpisodes;
        $this->lastUpdatedAt = $lastUpdatedAt;
    }
}
