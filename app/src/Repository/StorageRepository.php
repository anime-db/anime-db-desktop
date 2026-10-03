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

use App\Entity\Enum\StorageType;
use App\Entity\Storage;
use Doctrine\ORM\EntityManagerInterface;

class StorageRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** @return Storage[] */
    public function findAllOrderedByName(): array
    {
        return $this->entityManager->getRepository(Storage::class)->findBy([], ['name' => 'ASC']);
    }

    public function hasAny(): bool
    {
        $qb = $this->entityManager->getRepository(Storage::class)->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->setMaxResults(1);

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Storages whose {@see StorageType::isWritable()} is true — the ones a scan can actually be
     * triggered on (issue #835: the empty-catalog invitation offers "scan storage" only for these,
     * falling back to "add storage" otherwise).
     *
     * @return Storage[]
     */
    public function findAllScannable(): array
    {
        $writableTypes = array_values(array_filter(
            StorageType::cases(),
            static fn (StorageType $type): bool => $type->isWritable(),
        ));

        return $this->entityManager->getRepository(Storage::class)->createQueryBuilder('s')
            ->where('s.type IN (:types)')
            ->setParameter('types', $writableTypes)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
