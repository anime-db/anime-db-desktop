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
 * The demographic axis of MAL's 4-axis taxonomy (genres/explicit_genres/themes/demographics,
 * see GET /v4/genres/anime?filter=demographics on the Jikan API). Unlike genres/themes, a
 * title carries at most one demographic in practice, so this is a single nullable Anime
 * field (see Anime::$demographic), not a many-to-many join entity like AnimeGenre/AnimeTheme.
 * Extend by adding a new case here and a matching migration adding the value to the CHECK constraint.
 */
enum Demographic: string
{
    case Shounen = 'shounen';
    case Shoujo = 'shoujo';
    case Seinen = 'seinen';
    case Josei = 'josei';
    case Kids = 'kids';
}
