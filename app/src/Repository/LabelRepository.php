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

use App\Entity\Label;
use Doctrine\ORM\EntityManagerInterface;

class LabelRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** @return Label[] */
    public function findAllOrderedByName(): array
    {
        return $this->entityManager->getRepository(Label::class)->findBy([], ['name' => 'ASC']);
    }

    public function findOneByName(string $name): ?Label
    {
        return $this->entityManager->getRepository(Label::class)->findOneBy(['name' => $name]);
    }

    /**
     * One aggregating query for the whole label list, rather than a per-label count query —
     * a label with no linked anime still gets a row (cnt = 0) thanks to the LEFT JOIN.
     *
     * @return array<int, int> number of linked anime per label, keyed by label id
     */
    public function countAnimeByLabel(): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('label.id AS id', 'COUNT(anime.id) AS cnt')
            ->from(Label::class, 'label')
            ->leftJoin('label.animes', 'anime')
            ->groupBy('label.id')
            ->getQuery()
            ->getArrayResult();

        return array_combine(
            array_map(static fn (array $row): int => (int) $row['id'], $rows),
            array_map(static fn (array $row): int => (int) $row['cnt'], $rows),
        );
    }
}
