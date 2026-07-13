<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\Anime;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Full catalog reindex (issue #198), shared by bin/console app:search:reindex and the
 * "Reindex" button on /settings so neither reimplements the other's traversal/indexing logic.
 *
 * Anime is fetched page by page (LIMIT/OFFSET) rather than in one query so a large personal
 * catalog is never fully materialized in memory at once; EntityManager::clear() after each
 * page releases the previous page's entities from the identity map.
 */
final class AnimeReindexService
{
    private const int PAGE_SIZE = 200;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AnimeSearchIndexer $indexer,
    ) {
    }

    public function reindexAll(): int
    {
        $this->indexer->configureIndex();

        $count = 0;
        $offset = 0;

        do {
            /** @var list<Anime> $page */
            $page = $this->entityManager->getRepository(Anime::class)->createQueryBuilder('a')
                ->orderBy('a.id', 'ASC')
                ->setFirstResult($offset)
                ->setMaxResults(self::PAGE_SIZE)
                ->getQuery()
                ->getResult();

            foreach ($page as $anime) {
                $this->indexer->index($anime);
                ++$count;
            }

            $this->entityManager->clear();
            $offset += self::PAGE_SIZE;
        } while (\count($page) === self::PAGE_SIZE);

        return $count;
    }
}
