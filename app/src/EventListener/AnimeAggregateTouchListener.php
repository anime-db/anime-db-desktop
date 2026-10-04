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
use App\Entity\AnimeDescription;
use App\Entity\AnimeGenre;
use App\Entity\AnimeName;
use App\Entity\AnimeTheme;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Keeps an Anime aggregate's dateUpdate current when only a child collection row changes
 * (issue #888): a OneToMany child (AnimeName, AnimeGenre, AnimeTheme, AnimeDescription) is the
 * owning side of nothing on the `anime` row itself, so inserting/updating/removing one never
 * schedules the parent Anime for an UPDATE and Anime::onPreUpdate() never fires for it — the
 * catalog's "sorted by dateUpdate" default would otherwise silently reflect only dateAdd for
 * any record whose edits never touch its own scalar fields (e.g. filling in an alternative
 * title or a genre from a plugin).
 *
 * Runs in onFlush (before SQL is built) rather than postFlush: recomputeSingleEntityChangeSet()
 * has to run while the owning Anime is still eligible to be newly scheduled for UPDATE, so that
 * Doctrine actually issues that UPDATE and fires Anime's own postUpdate event — which is what
 * AnimeSearchIndexListener::postUpdate() already listens for to dispatch IndexAnimeMessage.
 * That reuse is why this listener never dispatches a message itself: forcing the UPDATE is
 * enough to get exactly one IndexAnimeMessage per touched Anime out of the existing listener.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class AnimeAggregateTouchListener
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();

        /** @var array<int, Anime> $touched spl_object_id($anime) => $anime */
        $touched = [];
        foreach ([
            ...$unitOfWork->getScheduledEntityInsertions(),
            ...$unitOfWork->getScheduledEntityUpdates(),
            ...$unitOfWork->getScheduledEntityDeletions(),
        ] as $entity) {
            $anime = $this->resolveParent($entity);
            if ($anime !== null) {
                $touched[spl_object_id($anime)] = $anime;
            }
        }

        foreach ($touched as $anime) {
            // Insertions and deletions of the Anime itself already get their dateUpdate/index
            // handled elsewhere (the constructor, and postRemove's DeleteFromIndexMessage) —
            // recomputing a change set for either state is either unnecessary or unsupported.
            if ($unitOfWork->isScheduledForInsert($anime) || $unitOfWork->isScheduledForDelete($anime)) {
                continue;
            }

            $anime->touchDateUpdate();
            $unitOfWork->recomputeSingleEntityChangeSet($entityManager->getClassMetadata($anime::class), $anime);
        }
    }

    private function resolveParent(object $entity): ?Anime
    {
        return match (true) {
            $entity instanceof AnimeName,
            $entity instanceof AnimeGenre,
            $entity instanceof AnimeTheme,
            $entity instanceof AnimeDescription => $entity->anime,
            default => null,
        };
    }
}
