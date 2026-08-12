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
 * Originally broke the pull->push echo loop (issue #352): PullSyncService::pull() applies a
 * remote watchStatus through Anime::setWatchStatus() + flush(), which used to trip the same
 * Doctrine preUpdate hook (AnimeSyncPushListener) a user-driven edit did — without this guard,
 * every pull that changed a status would have immediately dispatched a PushSyncMessage back to
 * every active plugin, including the one it was just pulled from, clobbering the source account
 * with a lossily-remapped status (e.g. Shikimori's "rewatching" round-trips back as "watching").
 *
 * Issue #371 moved that distinction into the domain layer itself: the push trigger now only
 * fires off Anime::changeWatchStatusManually()/SeriesAnime::changeWatchedEpisodesManually(), which
 * PullSyncService never calls, so a pull-applied change no longer reaches the trigger at all —
 * this class currently has no consumer left to guard for that purpose. It is kept, still wrapping
 * every pull() run, for issue #366 to reposition onto the origin-aware forward-propagation
 * suppression that replaces this global mute (see .claude-docs/sync.md's "Различие ручная /
 * синк-правка" section).
 *
 * A shared, request/worker-scoped singleton (autowired, no explicit service config needed). A
 * depth counter rather than a plain bool so a suppressed pull() that itself nests another
 * suppressed call (there is none today, but recovery-EntityManager flushes already happen inside
 * the same run) can never have an inner scope's finally turn suppression off while the outer
 * scope is still in progress.
 */
final class PullPushSuppressor
{
    private int $depth = 0;

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function suppress(callable $callback): mixed
    {
        ++$this->depth;

        try {
            return $callback();
        } finally {
            --$this->depth;
        }
    }

    public function isSuppressed(): bool
    {
        return $this->depth > 0;
    }
}
