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

/**
 * Minimal aggregate-root event recording (issue #371), mirroring gpslab/domain-event's
 * recordThat()/releaseEvents() shape without the dependency itself: that package is locked to
 * `symfony/* ~2.3|~3|~4|~5` and `doctrine/orm ~2.4` (last released 2021), incompatible with
 * this project's Symfony 8.1 / Doctrine ORM 3.6 / PHP 8.4+.
 *
 * $recordedEvents is deliberately not a mapped Doctrine property — it is transient in-memory
 * state, released by {@see \App\EventListener\AnimeDomainEventListener} from Doctrine's
 * postPersist/postUpdate hooks, i.e. only once the change the event describes has actually been
 * committed. A caller that calls recordThat() but whose change turns out to be a no-op (nothing
 * for Doctrine to flush) is responsible for not calling it in the first place — this trait does
 * not deduplicate.
 */
trait AggregateRootTrait
{
    /** @var list<object> */
    private array $recordedEvents = [];

    protected function recordThat(object $event): void
    {
        $this->recordedEvents[] = $event;
    }

    /** @return list<object> */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }
}
