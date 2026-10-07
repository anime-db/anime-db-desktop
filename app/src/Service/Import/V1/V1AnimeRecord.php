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

namespace App\Service\Import\V1;

/**
 * One v1 `item` row together with its related rows, as read by {@see V1CatalogReader}: bare data,
 * no behaviour. Values are raw v1 values; every rule about them lives in
 * {@see V1AnimeResolverInterface} or in {@see V1AnimeFactory}.
 *
 * Nothing in the type stops other code from building one with an arbitrary `dateAdd`, which
 * {@see V1AnimeFactory} then trusts — hence `@internal`: only the v1 import builds it.
 *
 * @internal
 */
final class V1AnimeRecord
{
    /**
     * @param list<string> $names   alternative names, in v1 row order
     * @param list<string> $sources external card URLs
     * @param list<string> $labels  label names, statuses included
     * @param list<string> $genres  genre names, ordered by v1 genre id
     */
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly ?string $type = null,
        public readonly ?\DateTimeImmutable $dateAdd = null,
        public readonly ?\DateTimeImmutable $dateUpdate = null,
        public readonly ?\DateTimeImmutable $datePremiere = null,
        public readonly ?\DateTimeImmutable $dateEnd = null,
        public readonly ?int $duration = null,
        public readonly ?int $rating = null,
        public readonly ?string $country = null,
        public readonly ?string $studio = null,
        public readonly ?string $summary = null,
        public readonly ?int $episodesNumber = null,
        public readonly ?string $episodes = null,
        public readonly ?string $translate = null,
        public readonly ?string $fileInfo = null,
        public readonly ?string $cover = null,
        public readonly ?string $path = null,
        public readonly ?V1StorageRecord $storage = null,
        public readonly array $names = [],
        public readonly array $sources = [],
        public readonly array $labels = [],
        public readonly array $genres = [],
    ) {
    }
}
