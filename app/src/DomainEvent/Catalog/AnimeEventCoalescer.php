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

namespace App\DomainEvent\Catalog;

use App\Event\Catalog\AnimeAdded;
use App\Event\Catalog\AnimeChanged;
use App\Event\Catalog\AnimeRemoved;
use App\Event\Catalog\FieldChange;

/**
 * Merges the catalog events of one record within one delivery:
 *  - Changed + Changed: per field, `before` of the first and `after` of the last; a field that ended
 *    up where it started drops out, and so does an event left without fields;
 *  - Added + Changed: Added;
 *  - Added + Removed: nothing;
 *  - Changed + Removed: Removed.
 * Any other event passes through untouched, in its original position.
 */
final class AnimeEventCoalescer
{
    /**
     * @param list<object> $events
     *
     * @return list<object>
     */
    public function coalesce(array $events): array
    {
        /** @var array<int, object|null> $result */
        $result = [];
        /** @var array<int, int> $slots anime id => index in $result of its pending event */
        $slots = [];

        foreach ($events as $event) {
            if (!$event instanceof AnimeAdded && !$event instanceof AnimeChanged && !$event instanceof AnimeRemoved) {
                $result[] = $event;
                continue;
            }

            $slot = $slots[$event->animeId] ?? null;
            $previous = $slot !== null ? $result[$slot] : null;

            if ($previous === null) {
                $slots[$event->animeId] = \count($result);
                $result[] = $event;
            } elseif ($previous instanceof AnimeAdded && $event instanceof AnimeChanged) {
                continue;
            } elseif ($previous instanceof AnimeAdded && $event instanceof AnimeRemoved) {
                $result[$slot] = null;
                unset($slots[$event->animeId]);
            } elseif ($previous instanceof AnimeChanged && $event instanceof AnimeChanged) {
                $merged = $this->merge($previous, $event);
                $result[$slot] = $merged;
                if ($merged === null) {
                    unset($slots[$event->animeId]);
                }
            } elseif ($previous instanceof AnimeChanged && $event instanceof AnimeRemoved) {
                $result[$slot] = $event;
                $slots[$event->animeId] = $slot;
            } else {
                // A removed record's id reappearing (the database may reuse it), or an event order
                // that cannot happen: keep both as they are.
                $slots[$event->animeId] = \count($result);
                $result[] = $event;
            }
        }

        return array_values(array_filter($result, static fn (?object $event): bool => $event !== null));
    }

    private function merge(AnimeChanged $first, AnimeChanged $last): ?AnimeChanged
    {
        $changes = $first->changes;
        foreach ($last->changes as $field => $change) {
            $before = isset($changes[$field]) ? $changes[$field]->before : $change->before;
            if ($before === $change->after) {
                unset($changes[$field]);
            } else {
                $changes[$field] = new FieldChange($before, $change->after);
            }
        }

        return $changes === [] ? null : new AnimeChanged($first->animeId, $changes);
    }
}
