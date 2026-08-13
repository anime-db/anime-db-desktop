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

use App\Entity\Studio;
use Doctrine\ORM\EntityManagerInterface;

class StudioRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** @return list<Studio> */
    public function findAllOrderedByName(int $limit, int $offset = 0): array
    {
        /* @var list<Studio> */
        return $this->entityManager->getRepository(Studio::class)->createQueryBuilder('s')
            ->orderBy('s.name', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    public function findOneByName(string $name): ?Studio
    {
        return $this->entityManager->getRepository(Studio::class)->findOneBy(['name' => $name]);
    }
}
