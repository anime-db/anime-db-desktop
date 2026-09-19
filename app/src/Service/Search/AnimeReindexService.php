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

namespace App\Service\Search;

use App\Entity\Anime;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * Full catalog reindex (issue #198), shared by bin/console app:search:reindex and the
 * "Reindex" button on /settings so neither reimplements the other's traversal/indexing logic.
 *
 * Anime is fetched page by page (LIMIT/OFFSET) rather than in one query so a large personal
 * catalog is never fully materialized in memory at once; EntityManager::clear() after each
 * page releases the previous page's entities from the identity map.
 *
 * AnimeSearchIndexer::toDocument() reads five *-to-many collections (names, genres, themes,
 * studios, labels) per Anime, so the page query fetch-joins all five (issue #207) instead of
 * letting each be lazy-loaded per row (N+1). A plain LEFT JOIN FETCH on multiple *-to-many
 * associations combined with setFirstResult/setMaxResults would corrupt pagination (the SQL
 * LIMIT applies to joined rows, not distinct Anime rows), so Doctrine's Paginator is used
 * instead: it hydrates each page in two queries (page of Anime ids, then those ids with the
 * joins) regardless of how many rows the joins fan out to.
 *
 * The index is cleared after configureIndex() (which also auto-creates it) and before any
 * document is added, so a full rebuild never leaves behind documents for records that are no
 * longer present in the source data (issue #655).
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
        $this->indexer->clearIndex();

        $count = 0;
        $offset = 0;

        do {
            $query = $this->entityManager->getRepository(Anime::class)->createQueryBuilder('a')
                ->leftJoin('a.names', 'names')->addSelect('names')
                ->leftJoin('a.genres', 'genres')->addSelect('genres')
                ->leftJoin('a.themes', 'themes')->addSelect('themes')
                ->leftJoin('a.studios', 'studios')->addSelect('studios')
                ->leftJoin('a.labels', 'labels')->addSelect('labels')
                ->orderBy('a.id', 'ASC')
                ->setFirstResult($offset)
                ->setMaxResults(self::PAGE_SIZE)
                ->getQuery();

            /** @var list<Anime> $page */
            $page = [...new Paginator($query, fetchJoinCollection: true)];

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
