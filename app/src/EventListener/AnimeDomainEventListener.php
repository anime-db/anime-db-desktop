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

namespace App\EventListener;

use App\Entity\Anime;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The infrastructure half of the minimal domain-event mechanism (issue #371):
 * Anime::releaseEvents() (see AggregateRootTrait) is drained here, once per entity per flush,
 * and each recorded event is published through the regular Symfony event dispatcher for an
 * application-level listener to react to — e.g. WatchProgressChangedByUserEvent driving the
 * sync push trigger, see App\EventSubscriber\WatchProgressPushSubscriber.
 *
 * postPersist/postUpdate rather than preUpdate/onFlush (the old AnimeSyncPushListener's hook):
 * events must only go out once Doctrine has actually committed the change they describe, and by
 * post*, a freshly-inserted entity already has its id (see AnimeSearchIndexListener, the same
 * postPersist/postUpdate pairing used for the same reason).
 *
 * A plain Doctrine event listener (fires for every entity) rather than an #[AsEntityListener]
 * tied to Anime specifically, for the same single-table-inheritance reason AnimeSearchIndexListener
 * gives: Anime is abstract, only its concrete subclasses are ever actually persisted.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
final class AnimeDomainEventListener
{
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->releaseAndDispatch($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->releaseAndDispatch($args->getObject());
    }

    private function releaseAndDispatch(object $entity): void
    {
        if (!$entity instanceof Anime) {
            return;
        }

        foreach ($entity->releaseEvents() as $event) {
            $this->eventDispatcher->dispatch($event);
        }
    }
}
