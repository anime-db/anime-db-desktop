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

namespace App\Service\Plugin\Pull;

use App\Entity\Anime;
use App\Entity\ValueObject\PluginId;

/**
 * One Anime PullSyncService::pull() found missing from a source's list (issue #217): the local
 * record used to carry $deletedFrom's external id (via metadata['external_id'], issue #257),
 * but the source's latest pull() no longer lists it.
 *
 * Never raised for an Anime with $storage set (see PullSyncService::detectDeletions()) — a
 * title linked to downloaded files is never even considered for this, regardless of what any
 * source reports. $stillPresentOn only reflects a still-recorded link to another currently
 * active sync plugin (see SyncRegistry::allActive()), not a live re-check of that plugin's own
 * list — sync plugins each run their own pull() independently, so there is no cross-plugin
 * confirmation available at the point a single plugin's pull() raises this notice.
 *
 * A no-op DTO on its own: the batch "requires attention" surface this feeds into (issue #216)
 * does not exist yet, so nothing consumes the list PullSyncService::pull() returns today.
 */
final class PullDeletionNotice
{
    /** @param list<PluginId> $stillPresentOn */
    public function __construct(
        public readonly Anime $anime,
        public readonly PullDeletionReason $reason,
        public readonly PluginId $deletedFrom,
        public readonly array $stillPresentOn,
    ) {
    }
}
