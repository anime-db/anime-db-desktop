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

/**
 * The eight filter-panel sections (issue #666), each counted by AnimeRepository::
 * facetsByFilter() against the filter with that one section's own criteria cleared — see
 * AnimeListFilter::withoutX() — so a section's counts answer "what would happen if I added
 * this value", not "how many rows are already in the current selection".
 */
final class AnimeFacets
{
    /**
     * @param list<AnimeFacetValueBucket>  $watchStatuses
     * @param list<AnimeFacetValueBucket>  $types
     * @param list<AnimeFacetValueBucket>  $datePremiereDecades value is a decade like "1990s", or "none"
     * @param list<AnimeFacetValueBucket>  $userRatings         value is "1".."5", or "none"
     * @param list<AnimeFacetEntityBucket> $labels
     * @param list<AnimeFacetValueBucket>  $genres
     * @param list<AnimeFacetValueBucket>  $themes
     * @param list<AnimeFacetEntityBucket> $studios
     */
    public function __construct(
        public readonly int $catalogTotal,
        public readonly array $watchStatuses,
        public readonly array $types,
        public readonly array $datePremiereDecades,
        public readonly array $userRatings,
        public readonly array $labels,
        public readonly array $genres,
        public readonly array $themes,
        public readonly array $studios,
    ) {
    }
}
