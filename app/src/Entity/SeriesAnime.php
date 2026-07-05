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

use App\Entity\Enum\AnimeType;
use App\Entity\Enum\ProductionStatus;
use App\Entity\Enum\WatchStatus;
use App\Entity\Exception\InvalidEpisodeCountException;
use Doctrine\ORM\Mapping as ORM;

/**
 * A multi-episode anime (TV/OVA/ONA/Special/Music).
 */
#[ORM\Entity]
class SeriesAnime extends Anime
{
    #[ORM\Column(nullable: true)]
    private ?int $episodesCount = null;

    /**
     * Number of the last watched episode. There is no separate "episode" entity.
     */
    #[ORM\Column(nullable: true)]
    private ?int $watchedEpisodes = null;

    public function getType(): AnimeType
    {
        return AnimeType::Tv;
    }

    public function getEpisodesCount(): ?int
    {
        return $this->episodesCount;
    }

    public function setEpisodesCount(?int $episodesCount): self
    {
        $this->assertEpisodeCount($this->watchedEpisodes, $episodesCount);
        $this->episodesCount = $episodesCount;

        return $this;
    }

    public function getWatchedEpisodes(): ?int
    {
        return $this->watchedEpisodes;
    }

    public function setWatchedEpisodes(?int $watchedEpisodes): self
    {
        $this->assertEpisodeCount($watchedEpisodes, $this->episodesCount);
        $this->watchedEpisodes = $watchedEpisodes;

        if (null !== $watchedEpisodes) {
            $this->setWatchStatus((null !== $this->episodesCount
                    && $watchedEpisodes === $this->episodesCount
                    && $this->getProductionStatus() === ProductionStatus::Released)
                ? WatchStatus::Completed
                : WatchStatus::Watching);
        }

        return $this;
    }

    /**
     * Keeps watched_episodes <= episodes_count in both directions: called from
     * setWatchedEpisodes() when the watched count changes, and from setEpisodesCount()
     * when the total is corrected after the fact (e.g. a plugin lowers episodes_count
     * below an already-recorded watched_episodes).
     */
    private function assertEpisodeCount(?int $watchedEpisodes, ?int $episodesCount): void
    {
        if (null === $watchedEpisodes) {
            return;
        }

        if ($watchedEpisodes < 0) {
            throw new InvalidEpisodeCountException('watched_episodes must not be negative');
        }

        if (null !== $episodesCount && $watchedEpisodes > $episodesCount) {
            throw new InvalidEpisodeCountException('watched_episodes must not exceed episodes_count');
        }
    }

    /**
     * Marks the next episode as watched, capping at episodes_count and moving
     * watch_status to watching (or to completed on the last episode, but only once
     * production_status is released) via setWatchedEpisodes(), regardless of the
     * previous status: the user may be resuming a dropped/on-hold title or rewatching
     * a completed one.
     */
    public function watchNextEpisode(): self
    {
        return $this->setWatchedEpisodes(($this->watchedEpisodes ?? 0) + 1);
    }

    public function setWatchStatus(WatchStatus $watchStatus): self
    {
        parent::setWatchStatus($watchStatus);

        if ($watchStatus === WatchStatus::Completed) {
            $this->watchedEpisodes = $this->episodesCount;
        }

        return $this;
    }
}
