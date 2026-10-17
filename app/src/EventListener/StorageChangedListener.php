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

use App\DomainEvent\DomainEventPublisher;
use App\Entity\Storage;
use App\Event\StorageChanged;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;

/**
 * Records a {@see StorageChanged} domain event whenever a {@see Storage} row is created, updated or
 * removed (issue #1079). It hooks the entity lifecycle rather than the controllers because storages
 * also change outside them: a drive-letter move found by `StorageMarkerService::relocateIfMarkerMoved()`,
 * manual links, preset downloads storages and the v1 import — native's storage list would go stale
 * after each of those.
 *
 * The hooks fire inside the still-open transaction, so nothing is published from here: the event goes
 * to {@see DomainEventPublisher::record()}, which delivers it after the commit and drops it on a rollback.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
#[AsDoctrineListener(event: Events::postRemove)]
final class StorageChangedListener
{
    /**
     * Ids of the Storage being removed, keyed by spl_object_id: the ORM nulls the entity id
     * before postRemove fires, so it has to be remembered in preRemove.
     *
     * @var array<int, int>
     */
    private array $removingIds = [];

    public function __construct(
        private readonly DomainEventPublisher $publisher,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->publish($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->publish($args->getObject());
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Storage) {
            return;
        }

        $this->removingIds[spl_object_id($entity)] = $this->requireId($entity);
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Storage) {
            return;
        }

        $key = spl_object_id($entity);
        $id = $this->removingIds[$key] ?? null;
        unset($this->removingIds[$key]);
        if ($id === null) {
            return;
        }

        $this->publisher->record(new StorageChanged($id));
    }

    private function publish(object $entity): void
    {
        if (!$entity instanceof Storage) {
            return;
        }

        $this->publisher->record(new StorageChanged($this->requireId($entity)));
    }

    private function requireId(Storage $storage): int
    {
        return $storage->id ?? throw new \LogicException('Storage must have an id at this point in its lifecycle.');
    }
}
