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
use App\Entity\Enum\WatchStatus;

/**
 * Criteria for the anime list query. Immutable and fully typed on purpose: the controller
 * (or any future caller) parses/validates raw request input into this object before it ever
 * reaches AnimeRepository, which is the single place issue #74 requires the total (COUNT)
 * and select queries to share.
 *
 * @see AnimeRepository::countByFilter()
 * @see AnimeRepository::findByFilter()
 */
final class AnimeListFilter
{
    /**
     * @param list<GenreCode> $genres    OR-matched: any of the given genres is enough
     * @param list<int>       $studioIds OR-matched: any of the given studios is enough
     * @param list<int>       $labelIds  OR-matched: any of the given labels is enough
     * @param list<int>|null  $ids       when set (issue #199), restricts the result to exactly
     *                                   these ids and takes precedence over $name — see
     *                                   AnimeRepository::createFilteredQueryBuilder()
     */
    public function __construct(
        public readonly WatchStatus $watchStatus,
        public readonly ?AnimeType $type = null,
        public readonly ?string $country = null,
        public readonly ?string $name = null,
        public readonly array $genres = [],
        public readonly array $studioIds = [],
        public readonly array $labelIds = [],
        public readonly ?int $userRatingFrom = null,
        public readonly ?int $userRatingTo = null,
        public readonly ?\DateTimeImmutable $datePremiereFrom = null,
        public readonly ?\DateTimeImmutable $datePremiereTo = null,
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
        return new self(
            watchStatus: $this->watchStatus,
            type: $this->type,
            country: $this->country,
            name: $this->name,
            genres: $this->genres,
            studioIds: $this->studioIds,
            labelIds: $this->labelIds,
            userRatingFrom: $this->userRatingFrom,
            userRatingTo: $this->userRatingTo,
            datePremiereFrom: $this->datePremiereFrom,
            datePremiereTo: $this->datePremiereTo,
            dateEndFrom: $this->dateEndFrom,
            dateEndTo: $this->dateEndTo,
            dateAddFrom: $this->dateAddFrom,
            dateAddTo: $this->dateAddTo,
            ids: $ids,
        );
    }
}
