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

use App\Entity\Enum\ProductionStatus;
use App\Entity\Enum\WatchStatus;
use App\Entity\Exception\InvalidEpisodeCountException;
use App\Entity\Exception\InvalidWatchStatusException;
use App\Event\WatchProgressChangedManuallyEvent;
use Doctrine\ORM\Mapping as ORM;

/**
 * A multi-episode anime (TV/OVA/ONA/Special/Music). Concrete subtype is one
 * of TvAnime/OvaAnime/OnaAnime/SpecialAnime/MusicAnime; they are currently
 * empty markers because no business rule distinguishes them yet.
 */
#[ORM\Entity]
abstract class SeriesAnime extends Anime
{
    #[ORM\Column(nullable: true)]
    private ?int $episodesCount = null;

    /**
     * Number of the last watched episode. There is no separate "episode" entity.
     */
    #[ORM\Column(nullable: true)]
    private ?int $watchedEpisodes = null;

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

        if ($watchedEpisodes !== null) {
            $this->setWatchStatus(($this->episodesCount !== null
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
        if ($watchedEpisodes === null) {
            return;
        }

        if ($watchedEpisodes < 0) {
            throw new InvalidEpisodeCountException('watched_episodes must not be negative');
        }

        if ($episodesCount !== null && $watchedEpisodes > $episodesCount) {
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

    /**
     * The manual-edit counterpart of setWatchedEpisodes() (issue #371) — see
     * Anime::changeWatchStatusManually() for why this split exists. Covers the episodes half of
     * the reconciliation unit that the old Doctrine preUpdate listener needed a dedicated
     * watchedEpisodes changed-field check for (issue #365, "camp #5"); here it is simply a
     * second call site recording the same WatchProgressChangedManuallyEvent.
     *
     * The event's current/previous watchStatus is captured around setWatchedEpisodes() rather
     * than assumed unchanged: that call derives a watchStatus as a side effect (e.g. reaching
     * episodes_count flips it to Completed), so the two can differ even though watchedEpisodes,
     * not watchStatus, is what triggers this method's event.
     */
    public function changeWatchedEpisodesManually(?int $watchedEpisodes): self
    {
        $previousEpisodes = $this->watchedEpisodes;
        $previousStatus = $this->getWatchStatus();
        $this->setWatchedEpisodes($watchedEpisodes);

        if ($this->watchedEpisodes !== $previousEpisodes) {
            $currentStatus = $this->getWatchStatus();
            $this->recordThat(fn (): WatchProgressChangedManuallyEvent => new WatchProgressChangedManuallyEvent(
                $this->id,
                $currentStatus,
                $previousStatus,
            ));
        }

        return $this;
    }

    /**
     * The manual-edit counterpart of watchNextEpisode() (issue #371), delegating to
     * changeWatchedEpisodesManually() instead of duplicating its event-recording so the two stay
     * in lockstep.
     */
    public function watchNextEpisodeManually(): self
    {
        return $this->changeWatchedEpisodesManually(($this->watchedEpisodes ?? 0) + 1);
    }

    public function setWatchStatus(WatchStatus $watchStatus): self
    {
        parent::setWatchStatus($watchStatus);

        if ($watchStatus === WatchStatus::Completed) {
            $this->watchedEpisodes = $this->episodesCount;
        }

        return $this;
    }

    /**
     * Episodes-then-status order (issue #365): applying $watchedEpisodes first lets the pair
     * represent e.g. "Dropped at 5/12" — setWatchedEpisodes(5) derives Watching via the usual
     * coupling, then the explicit setWatchStatus($status) below overrides it to Dropped without
     * touching watchedEpisodes (only a target of Completed forces watchedEpisodes := episodesCount).
     * $watchedEpisodes === null means the source didn't report episode progress this time
     * ("doesn't report" isn't "0"), so the local value is left untouched entirely.
     *
     * Each setter is atomic on its own (assertEpisodeCount()/the Completed-not-Released check
     * both validate before mutating), but the pair together is not: setWatchedEpisodes() can
     * succeed and then the explicit setWatchStatus() can still reject. On that path the episodes
     * mutation is rolled back to its pre-call value via the same two setters (same order, so the
     * intermediate status the rollback's setWatchedEpisodes() call derives is itself overwritten
     * by the restored setWatchStatus() right after) — a rejected pair must never leave the entity
     * holding a value the source never actually sent.
     */
    public function applyWatchProgress(WatchStatus $status, ?int $watchedEpisodes, \DateTimeImmutable $updatedAt): self
    {
        $previousStatus = $this->getWatchStatus();
        $previousWatchedEpisodes = $this->watchedEpisodes;

        try {
            if ($watchedEpisodes !== null) {
                $this->setWatchedEpisodes($watchedEpisodes);
            }
            $this->setWatchStatus($status);
        } catch (InvalidEpisodeCountException|InvalidWatchStatusException) {
            if ($watchedEpisodes !== null) {
                $this->setWatchedEpisodes($previousWatchedEpisodes);
            }
            $this->setWatchStatus($previousStatus);
            $this->flagWatchProgressRejected();

            return $this;
        }

        $this->touchWatchProgress($updatedAt);

        return $this;
    }
}
