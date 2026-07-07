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

namespace App\Service;

use App\Entity\Enum\AnimeSortField;
use App\Entity\Enum\SortDirection;
use App\Repository\AnimeListSort;

/**
 * The only place allowed to turn raw request strings into a sort column: validates against
 * the AnimeSortField whitelist before anything reaches AnimeRepository (issue #74). Falls
 * back to the default (date_update DESC) on a missing or unrecognized field instead of
 * raising an error, since an unknown sort request is not worth failing the whole list for.
 */
final class AnimeListSortResolver
{
    private const DEFAULT_FIELD = AnimeSortField::DateUpdate;
    private const DEFAULT_DIRECTION = SortDirection::Desc;

    public function resolve(?string $field, ?string $direction): AnimeListSort
    {
        $resolvedField = null !== $field ? AnimeSortField::tryFrom($field) : null;
        if (null === $resolvedField) {
            return new AnimeListSort(self::DEFAULT_FIELD, self::DEFAULT_DIRECTION);
        }

        $resolvedDirection = null !== $direction ? SortDirection::tryFrom($direction) : null;

        return new AnimeListSort($resolvedField, $resolvedDirection ?? self::DEFAULT_DIRECTION);
    }
}
