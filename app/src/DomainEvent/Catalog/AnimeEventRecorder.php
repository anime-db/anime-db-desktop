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

use App\DomainEvent\DomainEventPublisher;
use App\Entity\Anime;
use App\Event\Catalog\AnimeChanged;
use App\Event\Catalog\AnimeRemoved;
use App\Event\Catalog\AnimeSnapshot;
use App\Event\Catalog\FieldChange;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Explicit recording of catalog events for the writes that bypass the UnitOfWork (DBAL statements
 * with a known "before" and "after"), which {@see AnimeChangeCollector} cannot see. The caller
 * records right after its write succeeded; the delivery rules are the publisher's: the event is
 * never delivered at once — outside a transaction it waits for the nearest delivery point (the
 * next flush() return, which merges it with that flush's events, or the safety net), inside one
 * for the outermost commit, and it is dropped on a rollback. Values are given in the normalized form of {@see AnimeSnapshotNormalizer}.
 *
 * Not covered by any event: the FK cascade of a Label/Studio removal or rename, and replacing the
 * whole catalog database (restoring from an archive).
 */
class AnimeEventRecorder
{
    public function __construct(
        private readonly DomainEventPublisher $publisher,
        private readonly AnimeSnapshotNormalizer $normalizer,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Records AnimeChanged for the fields whose value really differs; nothing when none does.
     *
     * @param array<string, array{0: mixed, 1: mixed}> $changes field => [before, after]
     */
    public function recordChanged(int $animeId, array $changes): void
    {
        $fields = [];
        foreach ($changes as $field => [$before, $after]) {
            if ($before !== $after) {
                $fields[$field] = new FieldChange($before, $after);
            }
        }

        if ($fields !== []) {
            $this->publisher->record(new AnimeChanged($animeId, $fields));
        }
    }

    public function recordRemoved(int $animeId, AnimeSnapshot $snapshot): void
    {
        $this->publisher->record(new AnimeRemoved($animeId, $snapshot));
    }

    public function snapshot(Anime $anime): AnimeSnapshot
    {
        return $this->normalizer->snapshot($anime, $this->entityManager);
    }

    /**
     * Current normalized elements of a child collection of $anime (a sorted list).
     *
     * @return list<array<string, mixed>>
     */
    public function children(Anime $anime, string $field): array
    {
        /** @var iterable<object> $items */
        $items = $this->entityManager->getClassMetadata($anime::class)->getFieldValue($anime, $field);

        return $this->normalizer->normalizeChildren($items, $this->entityManager);
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    public function sort(array $items): array
    {
        return $this->normalizer->sort($items);
    }

    public function normalizeValue(mixed $value): mixed
    {
        return $this->normalizer->normalizeValue($value);
    }

    /**
     * Current normalized values of the given scalar domain fields; a field the type of $anime does
     * not have (episodesCount on a movie) is null.
     *
     * @param list<string> $fields
     *
     * @return array<string, mixed>
     */
    public function scalarFields(Anime $anime, array $fields): array
    {
        $metadata = $this->entityManager->getClassMetadata($anime::class);

        $values = [];
        foreach ($fields as $field) {
            $values[$field] = $field === 'type' || $metadata->hasField($field)
                ? $this->normalizer->normalizeAnimeField($anime, $field, $this->entityManager)
                : null;
        }

        return $values;
    }
}
