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

namespace App\Repository;

use App\Entity\Notification;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads of the notification history for the UI and the "read" marks. Records are written elsewhere
 * ({@see \App\Service\Notification\NotificationCenter}); nothing here deletes a record.
 *
 * A mark is a bulk UPDATE limited to `read_at IS NULL`: it never touches the identity map and a
 * record already read keeps its original time. Marking a record read takes it out from under the
 * `coalesce_unread` merge, so the next event with the same key starts a new record.
 */
class NotificationRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function countUnread(): int
    {
        return (int) $this->entityManager->createQuery(
            'SELECT COUNT(n.id) FROM '.Notification::class.' n WHERE n.readAt IS NULL'
        )->getSingleScalarResult();
    }

    /**
     * The newest records first.
     *
     * @return list<Notification>
     */
    public function findRecent(int $limit): array
    {
        return array_values($this->entityManager->createQuery(
            'SELECT n FROM '.Notification::class.' n ORDER BY n.occurredAt DESC, n.id DESC'
        )->setMaxResults($limit)->getResult());
    }

    /**
     * Every stored record, the newest first.
     *
     * @return list<Notification>
     */
    public function findAll(): array
    {
        return array_values($this->entityManager->createQuery(
            'SELECT n FROM '.Notification::class.' n ORDER BY n.occurredAt DESC, n.id DESC'
        )->getResult());
    }

    /**
     * Marks exactly the given records read (the ones the user was shown), not "everything unread
     * right now": a record that arrived after the list was built stays unread.
     *
     * @param list<int> $ids
     *
     * @return int the number of records that changed
     */
    public function markRead(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        /** @var list<Notification> $records */
        $records = $this->entityManager->createQuery(
            'SELECT n FROM '.Notification::class.' n WHERE n.id IN (:ids) AND n.readAt IS NULL'
        )->setParameter('ids', $ids)->getResult();

        return $this->markAll($records);
    }

    /** @return int the number of records that changed */
    public function markAllRead(): int
    {
        /** @var list<Notification> $records */
        $records = $this->entityManager->createQuery(
            'SELECT n FROM '.Notification::class.' n WHERE n.readAt IS NULL'
        )->getResult();

        return $this->markAll($records);
    }

    /**
     * The "read" rule lives in {@see Notification::markRead()}; the history is capped, so loading the
     * records and going through the entity costs nothing noticeable.
     *
     * @param list<Notification> $records
     */
    private function markAll(array $records): int
    {
        foreach ($records as $record) {
            $record->markRead();
        }
        $this->entityManager->flush();

        return \count($records);
    }
}
