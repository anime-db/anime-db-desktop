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

namespace App\Entity\Enum;

/**
 * Whitelist of columns the anime list may be sorted by. AnimeListSortResolver is the
 * only place allowed to turn a raw request string into one of these cases; AnimeRepository
 * never accepts a raw column name from the caller (see issue #74).
 */
enum AnimeSortField: string
{
    case Name = 'name';
    case DateUpdate = 'date_update';
    case UserRating = 'user_rating';
    case DatePremiere = 'date_premiere';
    case DateEnd = 'date_end';

    /**
     * DQL property path on the Anime alias "a", not a raw SQL column name.
     */
    public function toDqlField(): string
    {
        return match ($this) {
            self::Name => 'a.title',
            self::DateUpdate => 'a.dateUpdate',
            self::UserRating => 'a.userRating',
            self::DatePremiere => 'a.datePremiere',
            self::DateEnd => 'a.dateEnd',
        };
    }
}
