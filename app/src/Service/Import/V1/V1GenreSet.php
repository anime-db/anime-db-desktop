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

use App\Entity\Enum\Demographic;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\ThemeCode;

/**
 * How the v1 genres of one record landed in the v2 vocabularies.
 *
 * @internal
 */
final class V1GenreSet
{
    /**
     * @param list<GenreCode> $genres
     * @param list<ThemeCode> $themes
     * @param list<string>    $extraDemographics v1 names of demographics beyond the one kept
     * @param list<string>    $droppedByDesign   v1 names of the 18+ axis, left out on purpose
     * @param list<string>    $unmapped          v1 names that have no counterpart in v2
     */
    public function __construct(
        public readonly array $genres = [],
        public readonly array $themes = [],
        public readonly ?Demographic $demographic = null,
        public readonly array $extraDemographics = [],
        public readonly array $droppedByDesign = [],
        public readonly array $unmapped = [],
    ) {
    }
}
