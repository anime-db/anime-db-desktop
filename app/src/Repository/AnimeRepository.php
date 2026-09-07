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

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\AnimeExternalId;
use App\Entity\Storage;
use App\Entity\ValueObject\PluginId;
use App\Entity\ValueObject\Rating;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * Low-level query builder for the anime list (issue #74). It never accepts a raw column
 * name from the caller: sorting comes in only as the validated AnimeListSort value object
 * (see App\Service\AnimeListSortResolver), and filtering only as AnimeListFilter.
 *
 * countByFilter() and findByFilter() both build on createFilteredQueryBuilder() so the
 * "total" and "select" queries can never drift apart on which rows match the filter.
 *
 * A plain service, not a Doctrine entity repository (see LabelRepository for the same
 * pattern): Anime is not bound to it via #[ORM\Entity(repositoryClass: ...)].
 */
class AnimeRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function countByFilter(AnimeListFilter $filter): int
    {
        $qb = $this->createFilteredQueryBuilder($filter)
            ->select('COUNT(DISTINCT a.id)');

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function hasAny(): bool
    {
        $qb = $this->entityManager->getRepository(Anime::class)->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->setMaxResults(1);

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /** @return list<Anime> */
    public function findByFilter(AnimeListFilter $filter, AnimeListSort $sort, int $limit, int $offset): array
    {
        $qb = $this->createFilteredQueryBuilder($filter)
            ->distinct()
            ->orderBy($sort->field->toDqlField(), $sort->direction->value)
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        /* @var list<Anime> */
        return $qb->getQuery()->getResult();
    }

    /**
     * Anime with neither $storage nor $storagePath set ("orphans", see OrphanAnimeMatcher)
     * whose Anime::$normalizedTitle or one of its AnimeName::$normalizedName records equals
     * $normalizedName. Both columns are kept normalized at write time (Anime::setTitle(),
     * AnimeName::__construct()) via the same App\Entity\NameNormalizer::normalize() the
     * caller must have already applied to $normalizedName, so this is a plain indexed
     * column comparison rather than normalizing title/name in SQL on every query.
     *
     * @return list<Anime>
     */
    public function findOrphanCandidatesByNormalizedName(string $normalizedName): array
    {
        $qb = $this->entityManager->getRepository(Anime::class)->createQueryBuilder('a')
            ->leftJoin('a.names', 'n')
            ->andWhere('a.storage IS NULL')
            ->andWhere('a.storagePath IS NULL')
            ->andWhere('a.normalizedTitle = :needle OR n.normalizedName = :needle')
            ->setParameter('needle', $normalizedName)
            ->distinct()
            ->orderBy('a.id', 'ASC');

        /* @var list<Anime> */
        return $qb->getQuery()->getResult();
    }

    /**
     * Anime already linked to $storage (both Anime::$storage and Anime::$storagePath set) —
     * what a storage scan (App\Service\Storage\ScanStorageService) compares found top-level
     * names against to tell "already known" from "new" files.
     *
     * @return list<Anime>
     */
    public function findByStorage(Storage $storage): array
    {
        $qb = $this->entityManager->getRepository(Anime::class)->createQueryBuilder('a')
            ->andWhere('a.storage = :storage')
            ->andWhere('a.storagePath IS NOT NULL')
            ->setParameter('storage', $storage)
            ->orderBy('a.id', 'ASC');

        /* @var list<Anime> */
        return $qb->getQuery()->getResult();
    }

    /**
     * The Anime (if any) already linked to $storagePath within $storage — used by
     * ScanStorageService::linkToChosenCandidate() (issue #147) to reject a confirm that
     * would otherwise silently steal an already-occupied storage_path from another Anime.
     */
    public function findByStorageAndPath(Storage $storage, string $storagePath): ?Anime
    {
        $qb = $this->entityManager->getRepository(Anime::class)->createQueryBuilder('a')
            ->andWhere('a.storage = :storage')
            ->andWhere('a.storagePath = :storagePath')
            ->setParameter('storage', $storage)
            ->setParameter('storagePath', $storagePath)
            ->setMaxResults(1);

        /* @var ?Anime */
        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Reverse lookup for pull-sync idempotency (issue #257) and the create/resolve
     * conflict path (issue #297): the Anime, if any, already carrying $externalId for
     * $pluginId in the anime_external_id index (see Anime::getExternalId()/
     * rememberExternalId()). Used both to fold an already-synced SyncItem onto its known
     * local Anime and to detect a "new" SyncItem that in fact already has a local match, so
     * a repeated pull of the same plugin never creates a duplicate.
     *
     * A plain indexed-column lookup (issue #297) — replaces the earlier json_extract() scan
     * over metadata, which had no index to lean on at all.
     */
    public function resolve(PluginId $pluginId, string $externalId): ?Anime
    {
        $qb = $this->entityManager->getRepository(Anime::class)->createQueryBuilder('a')
            ->innerJoin('a.externalIds', 'e')
            ->andWhere('e.pluginId = :pluginId')
            ->andWhere('e.externalId = :externalId')
            ->setParameter('pluginId', (string) $pluginId)
            ->setParameter('externalId', $externalId)
            ->setMaxResults(1);

        /* @var ?Anime */
        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * One-shot index of every Anime already linked to $pluginId, keyed by its external id.
     * A caller resolving a whole pull() list — PullSyncService — no longer hydrates every
     * row into PHP just to discard the ones without this plugin's id, only the ids that
     * already match are loaded as managed entities. Queried off AnimeExternalId (issue
     * #297) rather than a json_extract() scan (see resolve() above for the same change).
     *
     * @return array<string, Anime>
     */
    public function indexByExternalId(PluginId $pluginId): array
    {
        $rows = $this->entityManager->getRepository(AnimeExternalId::class)->createQueryBuilder('e')
            ->addSelect('a')
            ->innerJoin('e.anime', 'a')
            ->andWhere('e.pluginId = :pluginId')
            ->setParameter('pluginId', (string) $pluginId)
            ->getQuery()
            ->getResult();

        $index = [];
        foreach ($rows as $row) {
            /* @var AnimeExternalId $row */
            $index[$row->externalId] = $row->anime;
        }

        return $index;
    }

    /**
     * A single page of the whole catalog, ordered by id, with $sources eagerly joined — what
     * BackfillExternalIdMessageHandler (issue #258) walks page by page rather than loading the
     * whole catalog into memory at once, same LIMIT/OFFSET + Paginator pattern as
     * AnimeReindexService::reindexAll(). $sources is joined because Anime::getExternalId()
     * resolves against it.
     *
     * @return list<Anime>
     */
    public function findPage(int $offset, int $limit): array
    {
        $query = $this->entityManager->getRepository(Anime::class)->createQueryBuilder('a')
            ->leftJoin('a.sources', 'sources')->addSelect('sources')
            ->orderBy('a.id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery();

        /** @var list<Anime> $page */
        $page = [...new Paginator($query, fetchJoinCollection: true)];

        return $page;
    }

    /**
     * Resolves a potential-duplicate cluster's stored ids (issue #269) back to entities for
     * display, keyed by id like indexByExternalId() so a caller can look up each requested id
     * without caring that findBy() doesn't preserve the requested order.
     *
     * @param list<int> $ids
     *
     * @return array<int, Anime>
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $animeById = [];
        foreach ($this->entityManager->getRepository(Anime::class)->findBy(['id' => $ids]) as $candidate) {
            /* @var Anime $candidate */
            $animeById[(int) $candidate->id] = $candidate;
        }

        return $animeById;
    }

    private function createFilteredQueryBuilder(AnimeListFilter $filter): QueryBuilder
    {
        $qb = $this->entityManager->getRepository(Anime::class)->createQueryBuilder('a');

        if ($filter->watchStatus !== null) {
            $qb->andWhere('a.watchStatus = :watchStatus')
                ->setParameter('watchStatus', $filter->watchStatus);
        }

        if ($filter->type !== null) {
            $qb->andWhere($qb->expr()->isInstanceOf('a', $filter->type->entityClass()));
        }

        if ($filter->country !== null) {
            $qb->andWhere('a.countries LIKE :country')
                ->setParameter('country', '%"'.$filter->country.'"%');
        }

        if ($filter->ids !== null) {
            // Already resolved by AnimeSearchResolver (issue #199), via Meilisearch — takes
            // precedence over $name and skips the FTS5 quick-filter below entirely.
            $qb->andWhere('a.id IN (:searchAnimeIds)')
                ->setParameter('searchAnimeIds', $filter->ids !== [] ? $filter->ids : [0]);
        } elseif ($filter->name !== null) {
            $ftsAnimeIds = $this->matchAnimeIdsByName($filter->name);
            $qb->andWhere('a.id IN (:ftsAnimeIds)')
                ->setParameter('ftsAnimeIds', $ftsAnimeIds !== [] ? $ftsAnimeIds : [0]);
        }

        if ($filter->genres !== []) {
            $qb->innerJoin('a.genres', 'g')
                ->andWhere('g.code IN (:genres)')
                ->setParameter('genres', $filter->genres);
        }

        if ($filter->studioIds !== []) {
            $qb->innerJoin('a.studios', 'st')
                ->andWhere('st.id IN (:studioIds)')
                ->setParameter('studioIds', $filter->studioIds);
        }

        if ($filter->labelIds !== []) {
            $qb->innerJoin('a.labels', 'lb')
                ->andWhere('lb.id IN (:labelIds)')
                ->setParameter('labelIds', $filter->labelIds);
        }

        if ($filter->userRatingFrom !== null) {
            $qb->andWhere('a.userRating >= :userRatingFrom')
                ->setParameter('userRatingFrom', new Rating($filter->userRatingFrom), RatingType::NAME);
        }

        if ($filter->userRatingTo !== null) {
            $qb->andWhere('a.userRating <= :userRatingTo')
                ->setParameter('userRatingTo', new Rating($filter->userRatingTo), RatingType::NAME);
        }

        $this->applyDateRange($qb, 'a.datePremiere', $filter->datePremiereFrom, $filter->datePremiereTo, 'datePremiere');
        $this->applyDateRange($qb, 'a.dateEnd', $filter->dateEndFrom, $filter->dateEndTo, 'dateEnd');
        $this->applyDateRange($qb, 'a.dateAdd', $filter->dateAddFrom, $filter->dateAddTo, 'dateAdd');

        return $qb;
    }

    /**
     * anime_fts (issue #195) is a plain SQLite FTS5 virtual table, not a mapped Doctrine
     * entity, so it cannot be DQL-joined like a.genres/a.studios above. Instead this runs a
     * native MATCH query first and folds the result into an "a.id IN (...)" DQL clause — same
     * final row set as a JOIN, without requiring a NativeQuery ResultSetMapping for the rest
     * of createFilteredQueryBuilder(). $name is wrapped as a quoted FTS5 phrase with a
     * trailing prefix wildcard ("foo bar"*) so it matches quick-filter-style incremental
     * typing and never throws on user input containing FTS5 operator characters.
     *
     * @return list<int>
     */
    private function matchAnimeIdsByName(string $name): array
    {
        $needle = '"'.str_replace('"', '""', $name).'"*';

        /* @var list<int> */
        return array_map(
            intval(...),
            $this->entityManager->getConnection()->fetchFirstColumn(
                'SELECT DISTINCT anime_id FROM anime_fts WHERE anime_fts MATCH ?',
                [$needle],
            ),
        );
    }

    private function applyDateRange(
        QueryBuilder $qb,
        string $dqlField,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        string $paramPrefix,
    ): void {
        if ($from !== null) {
            $qb->andWhere("{$dqlField} >= :{$paramPrefix}From")
                ->setParameter("{$paramPrefix}From", $from, UnixTimestampType::NAME);
        }

        if ($to !== null) {
            $qb->andWhere("{$dqlField} <= :{$paramPrefix}To")
                ->setParameter("{$paramPrefix}To", $to, UnixTimestampType::NAME);
        }
    }
}
