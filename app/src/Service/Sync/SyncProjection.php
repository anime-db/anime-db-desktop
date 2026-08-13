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

use App\Entity\Enum\WatchStatus;

/**
 * The atomic reconciliation unit (issue #365/#366): (watchStatus, watchedEpisodes) as one fact
 * with one timestamp, never diffed/synced field-by-field — see .claude-docs/sync.md's "Единица
 * реконсиляции". $watchedEpisodes is null both for a movie (no episode axis at all) and for a
 * series participant that simply didn't report episode progress this time; either way it is
 * never coerced to 0.
 *
 * equals() treats a null $watchedEpisodes as "not reported" rather than a value in its own
 * right: it never conflicts with the other side's reading, reported or not. Only two different
 * *reported* (non-null) episode counts disagree. Without this, a participant that simply
 * doesn't send episode progress (e.g. a plugin ahead of anime-db-plugins#46) would manufacture a
 * false conflict against every other participant that does.
 */
final readonly class SyncProjection
{
    public function __construct(
        public WatchStatus $status,
        public ?int $watchedEpisodes,
    ) {
    }

    public function equals(self $other): bool
    {
        if ($this->status !== $other->status) {
            return false;
        }

        return $this->watchedEpisodes === $other->watchedEpisodes
            || $this->watchedEpisodes === null
            || $other->watchedEpisodes === null;
    }
}
