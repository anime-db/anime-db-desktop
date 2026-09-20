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

use App\Entity\Enum\AnimeType;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\ThemeCode;
use App\Entity\Enum\WatchStatus;

/**
 * Criteria for the anime list query. Immutable and fully typed on purpose: the controller
 * (or any future caller) parses/validates raw request input into this object before it ever
 * reaches AnimeRepository, which is the single place issue #74 requires the total (COUNT)
 * and select queries to share.
 *
 * The withoutX() methods (issue #666) each clear exactly one filter dimension while keeping
 * the rest intact — AnimeRepository::facetsByFilter() uses them to count a facet section
 * "as if" its own filter were not applied, per the facet-panel semantics the issue requires.
 *
 * @see AnimeRepository::countByFilter()
 * @see AnimeRepository::findByFilter()
 * @see AnimeRepository::facetsByFilter()
 */
final class AnimeListFilter
{
    /**
     * @param list<WatchStatus> $watchStatuses OR-matched: any of the given statuses is enough
     * @param list<AnimeType>   $types         OR-matched: any of the given types is enough
     * @param list<GenreCode>   $genres        OR-matched: any of the given genres is enough
     * @param list<int>         $studioIds     OR-matched: any of the given studios is enough
     * @param list<int>         $labelIds      OR-matched: any of the given labels is enough
     * @param list<ThemeCode>   $themes        OR-matched: any of the given themes is enough
     * @param list<int>         $userRatings   OR-matched: any of the given ratings (1-5) is
     *                                         enough; combines with $userRatingIsNull via OR
     *                                         rather than excluding it — see
     *                                         AnimeRepository::createFilteredQueryBuilder()
     * @param list<int>|null    $ids           when set (issue #199), restricts the result to
     *                                         exactly these ids and takes precedence over
     *                                         $name — see AnimeRepository::createFilteredQueryBuilder()
     */
    public function __construct(
        public readonly array $watchStatuses = [],
        public readonly array $types = [],
        public readonly ?string $country = null,
        public readonly ?string $name = null,
        public readonly array $genres = [],
        public readonly array $studioIds = [],
        public readonly array $labelIds = [],
        public readonly array $themes = [],
        public readonly array $userRatings = [],
        public readonly ?int $userRatingFrom = null,
        public readonly ?int $userRatingTo = null,
        public readonly bool $userRatingIsNull = false,
        public readonly ?\DateTimeImmutable $datePremiereFrom = null,
        public readonly ?\DateTimeImmutable $datePremiereTo = null,
        public readonly bool $datePremiereIsNull = false,
        public readonly ?\DateTimeImmutable $dateEndFrom = null,
        public readonly ?\DateTimeImmutable $dateEndTo = null,
        public readonly ?\DateTimeImmutable $dateAddFrom = null,
        public readonly ?\DateTimeImmutable $dateAddTo = null,
        public readonly ?array $ids = null,
    ) {
    }

    /**
     * @param list<int> $ids anime ids already resolved by AnimeSearchResolver (issue #199)
     */
    public function withIds(array $ids): self
    {
        return $this->with(['ids' => $ids]);
    }

    public function withoutWatchStatuses(): self
    {
        return $this->with(['watchStatuses' => []]);
    }

    public function withoutTypes(): self
    {
        return $this->with(['types' => []]);
    }

    public function withoutGenres(): self
    {
        return $this->with(['genres' => []]);
    }

    public function withoutStudios(): self
    {
        return $this->with(['studioIds' => []]);
    }

    public function withoutLabels(): self
    {
        return $this->with(['labelIds' => []]);
    }

    public function withoutThemes(): self
    {
        return $this->with(['themes' => []]);
    }

    public function withoutUserRating(): self
    {
        return $this->with([
            'userRatings' => [],
            'userRatingFrom' => null,
            'userRatingTo' => null,
            'userRatingIsNull' => false,
        ]);
    }

    public function withoutDatePremiere(): self
    {
        return $this->with(['datePremiereFrom' => null, 'datePremiereTo' => null, 'datePremiereIsNull' => false]);
    }

    /** @param array<string, mixed> $overrides */
    private function with(array $overrides): self
    {
        /** @var array<string, mixed> $properties */
        $properties = get_object_vars($this);

        return new self(...array_merge($properties, $overrides));
    }
}
