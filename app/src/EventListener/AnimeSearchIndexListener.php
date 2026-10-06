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

use App\Entity\Anime;
use App\Message\DeleteFromIndexMessage;
use App\Message\IndexAnimeMessage;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Keeps the Meilisearch anime index in sync with the catalog (issue #197): dispatches
 * IndexAnimeMessage on create/update and DeleteFromIndexMessage on delete onto the `async`
 * transport, so a Meilisearch outage never fails the HTTP request that saved the entity —
 * the actual AnimeSearchIndexer::index()/delete() call (issue #196) only ever happens in the
 * consumer process, where the transport's own retry_strategy (issue #97) can absorb it.
 *
 * A plain Doctrine event listener (fires for every entity) rather than an #[AsEntityListener]
 * tied to Anime specifically: Anime is abstract with single-table-inheritance subclasses
 * (MovieAnime, TvAnime, ...) as the ones actually persisted, so filtering by `instanceof Anime`
 * here covers all of them without repeating the attribute per subclass.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
#[AsDoctrineListener(event: Events::postRemove)]
final class AnimeSearchIndexListener
{
    /**
     * Ids of the Anime being removed, keyed by spl_object_id: the ORM nulls the entity id
     * before postRemove fires, so it has to be remembered in preRemove.
     *
     * @var array<int, int>
     */
    private array $removingIds = [];

    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->dispatchIndex($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->dispatchIndex($args->getObject());
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Anime) {
            return;
        }

        $this->removingIds[spl_object_id($entity)] = $this->requireId($entity);
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Anime) {
            return;
        }

        $key = spl_object_id($entity);
        $id = $this->removingIds[$key] ?? null;
        unset($this->removingIds[$key]);
        if ($id === null) {
            return;
        }

        $this->messageBus->dispatch(new DeleteFromIndexMessage($id));
    }

    private function dispatchIndex(object $entity): void
    {
        if (!$entity instanceof Anime) {
            return;
        }

        $this->messageBus->dispatch(new IndexAnimeMessage($this->requireId($entity)));
    }

    private function requireId(Anime $anime): int
    {
        return $anime->id ?? throw new \LogicException('Anime must have an id at this point in its lifecycle.');
    }
}
