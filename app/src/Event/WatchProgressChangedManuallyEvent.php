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

namespace App\Event;

use App\Entity\Enum\WatchStatus;

/**
 * Domain event (issue #371): recorded by Anime::changeWatchStatusManually()/
 * SeriesAnime::changeWatchedEpisodesManually()/watchNextEpisodeManually() — the manual-edit path
 * (AnimeEditableController, AnimeNewController) — and released once Doctrine has committed the
 * change, from DomainEventListener's postPersist/postUpdate hooks.
 *
 * Never recorded by Anime::applyWatchProgress() (the sync-apply path, PullSyncService): that is
 * exactly the manual/sync split this issue introduces, replacing the old Doctrine preUpdate
 * listener (AnimeSyncPushListener), which could only look at a changed field set and had no way
 * to tell a user's edit from a sync's own write.
 *
 * A plain DTO: carries the anime's id plus its watch-status transition, not the entity itself —
 * the only consumer (WatchProgressPushSubscriber) needs just the id, and an event should be able
 * to outlive/travel independently of the aggregate that raised it. On the episode-only edit path
 * (SeriesAnime::changeWatchedEpisodesManually()) $currentWatchStatus and $previousWatchStatus may
 * be equal — the trigger there is watchedEpisodes changing, not necessarily the status.
 *
 * $id mirrors Anime::$id's own nullable type rather than being widened to a guaranteed int:
 * AggregateRootTrait defers building this event until releaseEvents() runs (always after flush
 * assigns the id, in real use — see that trait's docblock), but nothing stops a caller from
 * releasing events before ever persisting the aggregate, so the type stays honest about that.
 * WatchProgressPushSubscriber, the one consumer that actually needs a concrete id, is the place
 * that enforces it is non-null by then.
 */
final readonly class WatchProgressChangedManuallyEvent
{
    public function __construct(
        public ?int $id,
        public WatchStatus $currentWatchStatus,
        public ?WatchStatus $previousWatchStatus,
    ) {
    }
}
