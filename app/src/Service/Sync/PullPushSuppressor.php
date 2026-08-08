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
 * Breaks the pull->push echo loop (issue #352): PullSyncService::pull() applies a remote
 * watchStatus through Anime::setWatchStatus() + flush(), which trips the very same Doctrine
 * preUpdate hook (AnimeSyncPushListener) a user-driven edit does — without this guard, every
 * pull that changed a status would immediately dispatch a PushSyncMessage back to every active
 * plugin, including the one it was just pulled from, clobbering the source account with a
 * lossily-remapped status (e.g. Shikimori's "rewatching" round-trips back as "watching").
 *
 * A shared, request/worker-scoped singleton (autowired, no explicit service config needed):
 * PullSyncService::pull() wraps its whole run in suppress(), and AnimeSyncPushListener checks
 * isSuppressed() before dispatching. A depth counter rather than a plain bool so a suppressed
 * pull() that itself nests another suppressed call (there is none today, but recovery-EntityManager
 * flushes already happen inside the same run) can never have an inner scope's finally turn
 * suppression off while the outer scope is still in progress.
 *
 * Suppression is global for the whole duration of a pull run: AnimeSyncPushListener has no way to
 * tell which plugin a pull came from, so push is muted to *every* source, not only the one being
 * pulled from. Consequence: a change applied by a pull from source X is not propagated to another
 * source Y of the same title — Y catches up on its own next pull under a last-pull-wins model. This
 * is an accepted trade-off for #352 (per-source suppression is not cleanly achievable at the level of
 * a global Doctrine listener, which has no notion of "current plugin"); reconciling divergent
 * multi-source data is separate future work, out of scope here.
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
