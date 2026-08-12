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

namespace App\Event;

use App\Entity\Anime;

/**
 * Domain event (issue #371): recorded by Anime::changeWatchStatusByUser()/
 * SeriesAnime::changeWatchedEpisodesByUser()/watchNextEpisodeByUser() — the manual-edit path
 * (AnimeEditableController, AnimeNewController) — and released once Doctrine has committed the
 * change, from AnimeDomainEventListener's postPersist/postUpdate hooks.
 *
 * Never recorded by Anime::applyWatchProgress() (the sync-apply path, PullSyncService): that is
 * exactly the manual/sync split this issue introduces, replacing the old Doctrine preUpdate
 * listener (AnimeSyncPushListener), which could only look at a changed field set and had no way
 * to tell a user's edit from a sync's own write.
 *
 * Carries the Anime instance rather than a plain id: at the point a manual-create path
 * (AnimeNewController) records this event, the entity has no id yet — it is only assigned once
 * Doctrine executes the INSERT during flush(), which happens before postPersist fires.
 */
final readonly class WatchProgressChangedByUserEvent
{
    public function __construct(public Anime $anime)
    {
    }
}
