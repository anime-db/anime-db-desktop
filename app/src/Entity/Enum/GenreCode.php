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

namespace App\Entity\Enum;

/**
 * The genre axis of MAL's 4-axis taxonomy (genres/explicit_genres/themes/demographics,
 * see GET /v4/genres/anime on the Jikan API) — themes and demographics are separate axes,
 * implemented as their own enums in later issues, not values here.
 * Extend by adding a new case here and a matching migration adding the value to the CHECK constraint.
 */
enum GenreCode: string
{
    case Action = 'action';
    case Adventure = 'adventure';
    case AvantGarde = 'avant-garde';
    case AwardWinning = 'award-winning';
    case BoysLove = 'boys-love';
    case Comedy = 'comedy';
    case Drama = 'drama';
    case Fantasy = 'fantasy';
    case GirlsLove = 'girls-love';
    case Gourmet = 'gourmet';
    case Horror = 'horror';
    case Mystery = 'mystery';
    case Romance = 'romance';
    case SciFi = 'sci-fi';
    case SliceOfLife = 'slice-of-life';
    case Sports = 'sports';
    case Supernatural = 'supernatural';
    case Suspense = 'suspense';
}
