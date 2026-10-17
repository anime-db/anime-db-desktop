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

namespace App\EventListener;

use App\DomainEvent\Catalog\AnimeChangeCollector;
use App\DomainEvent\DomainEventPublisher;
use App\Entity\AggregateRootInterface;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * The Doctrine half of the domain-event mechanism (issue #371): collects what the aggregate roots
 * recorded via {@see \App\Entity\AggregateRootTrait} and passes it to the
 * {@see DomainEventPublisher}, which hands it to subscribers once the data is final (after
 * flush() returned / after the outer commit) — never from inside Doctrine's hooks.
 *
 * onFlush takes the event factories of the inserted/updated aggregates (replacing, not adding to,
 * what the publisher holds); postFlush builds the events — an INSERT has assigned the ids by then.
 *
 * A plain Doctrine event listener (fires for every entity): it depends on AggregateRootInterface,
 * not on Anime, so it works for any aggregate root that records domain events.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class DomainEventListener
{
    public function __construct(
        private readonly DomainEventPublisher $publisher,
        private readonly AnimeChangeCollector $animeChangeCollector,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();

        $factories = [];
        foreach ([$unitOfWork->getScheduledEntityInsertions(), $unitOfWork->getScheduledEntityUpdates()] as $entities) {
            foreach ($entities as $entity) {
                if ($entity instanceof AggregateRootInterface) {
                    $factories = [...$factories, ...$entity->releaseEventFactories()];
                }
            }
        }

        // Catalog events (AnimeAdded/AnimeRemoved/AnimeChanged) are resolved from the UnitOfWork, not recorded by the aggregate.
        $this->publisher->collect([...$factories, ...$this->animeChangeCollector->collect($entityManager)]);
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $this->publisher->promote();
    }
}
