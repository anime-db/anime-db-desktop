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

namespace App\Repository;

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\ValueObject\Rating;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

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

    private function createFilteredQueryBuilder(AnimeListFilter $filter): QueryBuilder
    {
        $qb = $this->entityManager->getRepository(Anime::class)->createQueryBuilder('a')
            ->andWhere('a.watchStatus = :watchStatus')
            ->setParameter('watchStatus', $filter->watchStatus);

        if (null !== $filter->type) {
            $qb->andWhere($qb->expr()->isInstanceOf('a', $filter->type->entityClass()));
        }

        if (null !== $filter->country) {
            $qb->andWhere('a.countries LIKE :country')
                ->setParameter('country', '%"'.$filter->country.'"%');
        }

        if ([] !== $filter->genres) {
            $qb->innerJoin('a.genres', 'g')
                ->andWhere('g.code IN (:genres)')
                ->setParameter('genres', $filter->genres);
        }

        if ([] !== $filter->studioIds) {
            $qb->innerJoin('a.studios', 'st')
                ->andWhere('st.id IN (:studioIds)')
                ->setParameter('studioIds', $filter->studioIds);
        }

        if ([] !== $filter->labelIds) {
            $qb->innerJoin('a.labels', 'lb')
                ->andWhere('lb.id IN (:labelIds)')
                ->setParameter('labelIds', $filter->labelIds);
        }

        if (null !== $filter->userRatingFrom) {
            $qb->andWhere('a.userRating >= :userRatingFrom')
                ->setParameter('userRatingFrom', new Rating($filter->userRatingFrom), RatingType::NAME);
        }

        if (null !== $filter->userRatingTo) {
            $qb->andWhere('a.userRating <= :userRatingTo')
                ->setParameter('userRatingTo', new Rating($filter->userRatingTo), RatingType::NAME);
        }

        $this->applyDateRange($qb, 'a.datePremiere', $filter->datePremiereFrom, $filter->datePremiereTo, 'datePremiere');
        $this->applyDateRange($qb, 'a.dateEnd', $filter->dateEndFrom, $filter->dateEndTo, 'dateEnd');
        $this->applyDateRange($qb, 'a.dateAdd', $filter->dateAddFrom, $filter->dateAddTo, 'dateAdd');

        return $qb;
    }

    private function applyDateRange(
        QueryBuilder $qb,
        string $dqlField,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        string $paramPrefix,
    ): void {
        if (null !== $from) {
            $qb->andWhere("{$dqlField} >= :{$paramPrefix}From")
                ->setParameter("{$paramPrefix}From", $from, UnixTimestampType::NAME);
        }

        if (null !== $to) {
            $qb->andWhere("{$dqlField} <= :{$paramPrefix}To")
                ->setParameter("{$paramPrefix}To", $to, UnixTimestampType::NAME);
        }
    }
}
