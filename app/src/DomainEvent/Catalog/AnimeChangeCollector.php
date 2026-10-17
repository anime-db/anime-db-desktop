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

use App\Entity\Anime;
use App\Event\Catalog\AnimeAdded;
use App\Event\Catalog\AnimeChanged;
use App\Event\Catalog\AnimeRemoved;
use App\Event\Catalog\FieldChange;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;

/**
 * Resolves, from what the UnitOfWork holds in onFlush, which catalog records were added, removed
 * or changed, and returns event factories for the {@see \App\DomainEvent\DomainEventPublisher}.
 *
 * Its own resolution, independent of AnimeAggregateTouchListener: it covers the Anime row, all
 * child entities (names, genres, themes, descriptions, sources, images, external ids) and the
 * studios/labels M2M collections. "Before" of a child comes from getOriginalEntityData(), not from
 * the collection snapshot, which holds the very same (already modified) objects as "after".
 * Technical fields never produce a change; a change of nothing but them yields no event.
 *
 * The data of AnimeRemoved and AnimeChanged is read here, in onFlush, while the rows are still in
 * place (the M2M collections of a removed record are initialized here). AnimeAdded only gets the
 * id when the factory runs in postFlush, after the INSERT.
 *
 * Out of scope: what bypasses the UnitOfWork (DQL/SQL updates), FK cascades of a Label/Studio
 * removal or rename, and replacing the whole catalog database.
 */
final class AnimeChangeCollector
{
    public function __construct(
        private readonly AnimeSnapshotNormalizer $normalizer,
    ) {
    }

    /** @return list<\Closure(): object> */
    public function collect(EntityManagerInterface $entityManager): array
    {
        $unitOfWork = $entityManager->getUnitOfWork();

        $factories = [];
        $inserted = [];
        $deleted = [];
        /** @var array<int, Anime> $touched */
        $touched = [];
        /** @var list<array{string, object}> $childEntries scheduled child entities with their state: insert/update/delete */
        $childEntries = [];

        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Anime) {
                $inserted[spl_object_id($entity)] = true;
                $factories[] = static fn (): object => new AnimeAdded($entity->id ?? throw new \LogicException('An inserted anime has no id after flush.'));
            } else {
                $childEntries[] = ['insert', $entity];
            }
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof Anime) {
                $touched[spl_object_id($entity)] = $entity;
            } else {
                $childEntries[] = ['update', $entity];
            }
        }

        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof Anime) {
                $deleted[spl_object_id($entity)] = true;
                $event = new AnimeRemoved($entity->id ?? throw new \LogicException('A removed anime has no id.'), $this->normalizer->snapshot($entity, $entityManager));
                $factories[] = static fn (): object => $event;
            } else {
                $childEntries[] = ['delete', $entity];
            }
        }

        /** @var array<int, array<string, array<string, array<int, object>>>> $children by spl_object_id(Anime), then collection field and state */
        $children = [];
        foreach ($childEntries as [$state, $entity]) {
            $field = array_search($entity::class, AnimeSnapshotNormalizer::CHILD_CLASSES, true);
            if ($field === false) {
                continue;
            }

            /** @var Anime $anime */
            $anime = $entityManager->getClassMetadata($entity::class)->getFieldValue($entity, 'anime');
            $touched[spl_object_id($anime)] = $anime;
            $children[spl_object_id($anime)][$field][$state][spl_object_id($entity)] = $entity;
        }

        /** @var array<int, array<string, array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}>> $manyToMany */
        $manyToMany = [];
        foreach ([...$unitOfWork->getScheduledCollectionUpdates(), ...$unitOfWork->getScheduledCollectionDeletions()] as $collection) {
            $owner = $collection->getOwner();
            $field = $collection->getMapping()->fieldName;
            if (!$owner instanceof Anime || !\in_array($field, ['studios', 'labels'], true) || isset($deleted[spl_object_id($owner)])) {
                continue;
            }

            $id = spl_object_id($owner);
            $touched[$id] = $owner;
            $current = $entityManager->getClassMetadata($owner::class)->getFieldValue($owner, $field);
            $manyToMany[$id][$field] = [
                $this->normalizer->normalizeNamedItems($collection->getSnapshot()),
                $this->normalizer->normalizeNamedItems($current),
            ];
        }

        foreach ($touched as $id => $anime) {
            if (isset($inserted[$id]) || isset($deleted[$id])) {
                continue;
            }

            $changes = $this->scalarChanges($anime, $unitOfWork);
            foreach (AnimeSnapshotNormalizer::ANIME_COLLECTION_FIELDS as $field) {
                if (isset($manyToMany[$id][$field])) {
                    [$before, $after] = $manyToMany[$id][$field];
                } elseif (isset($children[$id][$field])) {
                    [$before, $after] = $this->childCollectionChange($anime, $field, $children[$id][$field], $unitOfWork, $entityManager);
                } else {
                    continue;
                }

                if ($before !== $after) {
                    $changes[$field] = new FieldChange($before, $after);
                }
            }

            if ($changes !== []) {
                $event = new AnimeChanged($anime->id ?? throw new \LogicException('A changed anime has no id.'), $changes);
                $factories[] = static fn (): object => $event;
            }
        }

        return $factories;
    }

    /** @return array<string, FieldChange> */
    private function scalarChanges(Anime $anime, UnitOfWork $unitOfWork): array
    {
        $changes = [];
        foreach ($unitOfWork->getEntityChangeSet($anime) as $field => $change) {
            if (!\is_array($change) || !\in_array($field, AnimeSnapshotNormalizer::ANIME_DOMAIN_FIELDS, true)) {
                continue;
            }

            $before = $this->normalizer->normalizeValue($change[0]);
            $after = $this->normalizer->normalizeValue($change[1]);
            if ($before !== $after) {
                $changes[$field] = new FieldChange($before, $after);
            }
        }

        return $changes;
    }

    /**
     * @param array<string, array<int, object>> $changed scheduled children of the collection by state
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function childCollectionChange(Anime $anime, string $field, array $changed, UnitOfWork $unitOfWork, EntityManagerInterface $entityManager): array
    {
        /** @var iterable<object> $collection */
        $collection = $entityManager->getClassMetadata($anime::class)->getFieldValue($anime, $field);
        if ($collection instanceof PersistentCollection) {
            $collection->initialize();
        }

        $changed += ['insert' => [], 'update' => [], 'delete' => []];

        $current = [];
        foreach ($collection as $item) {
            $current[spl_object_id($item)] = $item;
        }

        // The collection and the UnitOfWork may disagree on a child that was removed from (or never
        // added to) the collection: the scheduled sets are the authority on what is inserted/deleted.
        $after = array_diff_key($current + $changed['insert'], $changed['delete']);
        $before = array_diff_key($current + $changed['delete'], $changed['insert']);

        $normalize = function (array $items, bool $original) use ($changed, $unitOfWork, $entityManager): array {
            $normalized = [];
            foreach ($items as $id => $item) {
                $data = null;
                if ($original && (isset($changed['update'][$id]) || isset($changed['delete'][$id]))) {
                    $data = $this->originalData($item, $unitOfWork);
                }
                $normalized[] = $this->normalizer->normalizeChild($item, $entityManager, $data);
            }

            return $this->normalizer->sort($normalized);
        };

        return [$normalize($before, true), $normalize($after, false)];
    }

    /**
     * The values of $entity as loaded. Once Doctrine has computed a change set it overwrites
     * getOriginalEntityData() with the current values, so the old values of the changed fields are
     * taken from the change set.
     *
     * @return array<string, mixed>|null
     */
    private function originalData(object $entity, UnitOfWork $unitOfWork): ?array
    {
        $data = $unitOfWork->getOriginalEntityData($entity);
        if ($data === []) {
            return null;
        }

        foreach ($unitOfWork->getEntityChangeSet($entity) as $field => $change) {
            if (\is_array($change)) {
                $data[$field] = $change[0];
            }
        }

        return $data;
    }
}
