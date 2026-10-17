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

namespace App\Tests\Support;

use App\DomainEvent\Catalog\AnimeEventRecorder;
use App\DomainEvent\Catalog\AnimeSnapshotNormalizer;
use App\DomainEvent\DomainEventPublisher;
use App\Event\Catalog\AnimeAdded;
use App\Event\Catalog\AnimeChanged;
use App\Event\Catalog\AnimeRemoved;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** A real publisher and recorder over a plain dispatcher that remembers the catalog events it delivered. */
final class CatalogEventProbe
{
    /** @var list<object> */
    public array $events = [];
    public readonly DomainEventPublisher $publisher;
    public readonly AnimeEventRecorder $recorder;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $dispatcher = new EventDispatcher();
        foreach ([AnimeAdded::class, AnimeChanged::class, AnimeRemoved::class] as $class) {
            $dispatcher->addListener($class, function (object $event): void {
                $this->events[] = $event;
            });
        }

        $this->publisher = new DomainEventPublisher($dispatcher, new NullLogger());
        $this->recorder = new AnimeEventRecorder($this->publisher, new AnimeSnapshotNormalizer(), $entityManager);
    }

    /** @return list<AnimeChanged> */
    public function changed(): array
    {
        return array_values(array_filter($this->events, static fn (object $event): bool => $event instanceof AnimeChanged));
    }

    /** @return list<AnimeRemoved> */
    public function removed(): array
    {
        return array_values(array_filter($this->events, static fn (object $event): bool => $event instanceof AnimeRemoved));
    }
}
