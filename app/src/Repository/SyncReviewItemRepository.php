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

use App\Entity\Enum\SyncReviewItemKind;
use App\Entity\SyncReviewItem;
use Doctrine\ORM\EntityManagerInterface;

class SyncReviewItemRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function save(SyncReviewItem $item): void
    {
        $this->entityManager->persist($item);
        $this->entityManager->flush();
    }

    /**
     * Drops every item, resolved or not (issue #951): they reference anime only through JSON
     * payload ids, so they outlive an emptied catalog. Joins the connection's current transaction.
     */
    public function removeAll(): void
    {
        $this->entityManager->getConnection()->executeStatement('DELETE FROM sync_review_item');
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    /** @return SyncReviewItem[] */
    public function findAllUnresolvedOrderedByCreatedAt(): array
    {
        return $this->entityManager->getRepository(SyncReviewItem::class)
            ->findBy(['resolvedAt' => null], ['createdAt' => 'ASC']);
    }

    /**
     * Backs the settings sidebar's "needs correction" badge (issue #822), rendered on every
     * settings page load, so it must be a count query rather than loading every unresolved item
     * and counting in PHP the way {@see \App\Controller\SettingsController} used to.
     */
    public function countUnresolvedByKind(SyncReviewItemKind $kind): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(item.id)')
            ->from(SyncReviewItem::class, 'item')
            ->andWhere('item.resolvedAt IS NULL')
            ->andWhere('item.kind = :kind')
            ->setParameter('kind', $kind)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
