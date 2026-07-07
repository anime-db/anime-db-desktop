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
use App\Entity\Enum\AnimeType;
use App\Entity\MovieAnime;
use App\Entity\MusicAnime;
use App\Entity\OnaAnime;
use App\Entity\OvaAnime;
use App\Entity\SpecialAnime;
use App\Entity\TvAnime;
use App\Entity\ValueObject\Rating;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Low-level query builder for the anime list (issue #74). It never accepts a raw column
 * name from the caller: sorting comes in only as the validated AnimeListSort value object
 * (see App\Service\AnimeListSortResolver), and filtering only as AnimeListFilter.
 *
 * countByFilter() and findByFilter() both build on createFilteredQueryBuilder() so the
 * "total" and "select" queries can never drift apart on which rows match the filter.
 */
class AnimeRepository extends ServiceEntityRepository
{
    /** @var array<value-of<AnimeType>, class-string<Anime>> */
    private const TYPE_CLASS = [
        AnimeType::Movie->value => MovieAnime::class,
        AnimeType::Tv->value => TvAnime::class,
        AnimeType::Ova->value => OvaAnime::class,
        AnimeType::Ona->value => OnaAnime::class,
        AnimeType::Special->value => SpecialAnime::class,
        AnimeType::Music->value => MusicAnime::class,
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Anime::class);
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

    private function createFilteredQueryBuilder(AnimeListFilter $filter): QueryBuilder
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.watchStatus = :watchStatus')
            ->setParameter('watchStatus', $filter->watchStatus);

        if (null !== $filter->type) {
            $qb->andWhere($qb->expr()->isInstanceOf('a', self::TYPE_CLASS[$filter->type->value]));
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
