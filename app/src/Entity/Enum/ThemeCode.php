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
 * The theme axis of MAL's 4-axis taxonomy (genres/explicit_genres/themes/demographics,
 * see GET /v4/genres/anime?filter=themes on the Jikan API) — genres and demographics are
 * separate axes, implemented as their own enums (see GenreCode). Themes and genres share
 * the same multi-valued semantics: a title can carry several themes at once.
 * Extend by adding a new case here and a matching migration adding the value to the CHECK constraint.
 */
enum ThemeCode: string
{
    case Harem = 'harem';
    case Historical = 'historical';
    case Isekai = 'isekai';
    case MartialArts = 'martial-arts';
    case Mecha = 'mecha';
    case Military = 'military';
    case Music = 'music';
    case Mythology = 'mythology';
    case Parody = 'parody';
    case Psychological = 'psychological';
    case School = 'school';
    case StrategyGame = 'strategy-game';
    case SuperPower = 'super-power';
    case Vampire = 'vampire';
}
